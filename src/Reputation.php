<?php

namespace ErnestDefoe\Answers;

use Carbon\Carbon;
use ErnestDefoe\Answers\Event\ReputationChanged;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use WeakMap;

/**
 * Reputation is counted per tag. The log is the truth and the profile's
 * history; answers_reputation holds its sums, so a threshold check or a
 * leaderboard is one indexed read. Taking points back deletes the rows that
 * gave them, so the history never shows a vote that was withdrawn.
 */
class Reputation
{
    /** @var WeakMap<User, array<string, int>> a viewer's points, per request (the actor object lives as long as it). */
    private WeakMap $memo;

    /** @var list<ReputationChanged>|null events held back while a transaction runs (null: dispatch at once). */
    private ?array $pending = null;

    public function __construct(private readonly ConnectionInterface $db, private readonly Dispatcher $events)
    {
        $this->memo = new WeakMap();
    }

    /** @param int[] $tagIds */
    public function give(?int $userId, array $tagIds, int $points, string $reason, ?int $discussionId = null, ?int $postId = null, ?int $actorId = null): void
    {
        if (! $userId || ! $points) {
            return;
        }

        foreach ($tagIds as $tagId) {
            $this->db->table('answers_reputation_log')->insert([
                'user_id' => $userId, 'tag_id' => $tagId, 'discussion_id' => $discussionId, 'post_id' => $postId,
                'reason' => $reason, 'points' => $points, 'created_at' => Carbon::now(), 'actor_id' => $actorId,
            ]);
            $this->bump($userId, $tagId, $points);
            $this->emit(new ReputationChanged($userId, $tagId, $points, $reason));
        }
    }

    /**
     * Run a transaction (retried on deadlock) and announce the reputation changes it made only after it
     * commits: a retry reruns the closure, and listeners must never hear about a change that rolled back.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        $this->pending = [];
        try {
            $result = $this->db->transaction(function () use ($work) {
                $this->pending = [];

                return $work();
            }, 3);
        } catch (\Throwable $e) {
            $this->pending = null;
            throw $e;
        }
        $events = $this->pending;
        $this->pending = null;
        foreach ($events as $event) {
            $this->events->dispatch($event);
        }

        return $result;
    }

    /**
     * Lock the reputation rows these members have in these tags, in one order everywhere (by user, then tag),
     * creating missing rows first: two transactions touching the same members can't wait on each other.
     *
     * @param array<int|null> $userIds
     * @param int[] $tagIds
     */
    public function lock(array $userIds, array $tagIds): void
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        $tagIds = array_values(array_unique(array_map('intval', $tagIds)));
        if (! $userIds || ! $tagIds) {
            return;
        }
        sort($userIds);
        sort($tagIds);
        foreach ($userIds as $userId) {
            foreach ($tagIds as $tagId) {
                $this->db->table('answers_reputation')->insertOrIgnore(['user_id' => $userId, 'tag_id' => $tagId, 'points' => 0]);
            }
        }
        $this->db->table('answers_reputation')->whereIn('user_id', $userIds)->whereIn('tag_id', $tagIds)
            ->orderBy('user_id')->orderBy('tag_id')->lockForUpdate()->get(['user_id']);
    }

    private function emit(ReputationChanged $event): void
    {
        if ($this->pending !== null) {
            $this->pending[] = $event;
        } else {
            $this->events->dispatch($event);
        }
    }

    /**
     * Spend points (a bounty): one conditional decrement, so two spends at once can't both pass a balance
     * read and take a member below zero. Returns false when there isn't enough.
     */
    public function spend(int $userId, int $tagId, int $points, string $reason, ?int $discussionId = null): bool
    {
        $spent = $this->db->table('answers_reputation')->where('user_id', $userId)->where('tag_id', $tagId)
            ->where('points', '>=', $points)->decrement('points', $points);
        if (! $spent) {
            return false;
        }
        $this->db->table('answers_reputation_log')->insert([
            'user_id' => $userId, 'tag_id' => $tagId, 'discussion_id' => $discussionId, 'post_id' => null,
            'reason' => $reason, 'points' => -$points, 'created_at' => Carbon::now(),
        ]);
        $this->emit(new ReputationChanged($userId, $tagId, -$points, $reason));

        return true;
    }

    /** Take back one award per tag: the row this actor caused (a voter's own vote), else the newest matching one. */
    public function takeBack(?int $userId, string $reason, ?int $discussionId, ?int $postId, ?int $actorId = null): void
    {
        if (! $userId) {
            return;
        }

        $rows = $this->db->table('answers_reputation_log')
            ->where('user_id', $userId)->where('reason', $reason)
            ->where('discussion_id', $discussionId)->where('post_id', $postId)
            ->when($actorId, fn ($q) => $q->orderByRaw('case when '.$this->db->getQueryGrammar()->wrap('actor_id').' = ? then 0 else 1 end', [$actorId]))
            ->orderByDesc('id')->get(['id', 'tag_id', 'points']);

        $done = [];
        foreach ($rows as $row) {
            if (isset($done[$row->tag_id])) {
                continue;
            }
            $done[$row->tag_id] = true;
            $this->db->table('answers_reputation_log')->where('id', $row->id)->delete();
            $this->bump($userId, (int) $row->tag_id, -(int) $row->points);
            $this->emit(new ReputationChanged($userId, (int) $row->tag_id, -(int) $row->points, $reason));
        }
    }

    /** A post (or a whole discussion) is going: everything it earned goes with it. Spent bounties stay spent. */
    public function forget(?int $discussionId, ?int $postId): void
    {
        $query = $this->db->table('answers_reputation_log')->where('reason', '<>', 'bounty_offered');
        $postId ? $query->where('post_id', $postId) : $query->where('discussion_id', $discussionId);

        $rows = $query->get(['id', 'user_id', 'tag_id', 'points']);
        foreach ($rows->groupBy(fn ($row) => $row->user_id.':'.$row->tag_id) as $group) {
            $this->bump((int) $group[0]->user_id, (int) $group[0]->tag_id, -(int) $group->sum('points'));
        }
        $this->db->table('answers_reputation_log')->whereIn('id', $rows->pluck('id'))->delete();
    }

    /** A post was hidden: the reputation its votes earned (or cost) goes until it's restored (Votes::restore). */
    public function forgetVotes(int $postId): void
    {
        $rows = $this->db->table('answers_reputation_log')->where('post_id', $postId)
            ->whereIn('reason', ['upvote', 'downvote', 'downvote_cast'])->get(['id', 'user_id', 'tag_id', 'points']);
        foreach ($rows->groupBy(fn ($row) => $row->user_id.':'.$row->tag_id) as $group) {
            $this->bump((int) $group[0]->user_id, (int) $group[0]->tag_id, -(int) $group->sum('points'));
        }
        $this->db->table('answers_reputation_log')->whereIn('id', $rows->pluck('id'))->delete();
    }

    /** A member's best standing among these tags (thresholds count in any Q&A tag of the question). */
    public function best(User $actor, array $tagIds): int
    {
        if ($actor->isGuest() || ! $tagIds) {
            return 0;
        }
        sort($tagIds);
        $key = implode(',', $tagIds);

        $memo = $this->memo[$actor] ?? [];
        if (! isset($memo[$key])) {
            $memo[$key] = (int) $this->db->table('answers_reputation')
                ->where('user_id', $actor->id)->whereIn('tag_id', $tagIds)->max('points');
            $this->memo[$actor] = $memo;
        }

        return $memo[$key];
    }

    /** @return array<int, int> tag id => points for one member. */
    public function forUser(int $userId): array
    {
        return $this->db->table('answers_reputation')->where('user_id', $userId)->where('points', '<>', 0)
            ->pluck('points', 'tag_id')->map(fn ($p) => (int) $p)->all();
    }

    /** The memo must not outlive a change made in the same request. */
    public function forgetMemo(User $actor): void
    {
        unset($this->memo[$actor]);
    }

    private function bump(int $userId, int $tagId, int $points): void
    {
        if (! $points) {
            return;
        }
        $query = $this->db->table('answers_reputation')->where('user_id', $userId)->where('tag_id', $tagId);
        if (! $query->increment('points', $points)) {
            $this->db->table('answers_reputation')->insertOrIgnore(['user_id' => $userId, 'tag_id' => $tagId, 'points' => 0]);
            $this->db->table('answers_reputation')->where('user_id', $userId)->where('tag_id', $tagId)->increment('points', $points);
        }
    }
}
