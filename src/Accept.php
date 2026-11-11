<?php

namespace ErnestDefoe\Answers;

use ErnestDefoe\Answers\Event\AnswerAccepted;
use ErnestDefoe\Answers\Event\AnswerUnaccepted;
use ErnestDefoe\Answers\Notification\AnswerAcceptedBlueprint;
use Flarum\Discussion\Discussion;
use Flarum\Notification\NotificationSyncer;
use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;

/**
 * Accepting an answer (or taking the acceptance back). The answer's author
 * earns points_accepted and the asker points_accepter, unless they answered
 * themselves; the counts on both members follow; a running bounty is paid.
 */
class Accept
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Reputation $reputation,
        private readonly Settings $settings,
        private readonly Question $questions,
        private readonly Bounties $bounties,
        private readonly NotificationSyncer $notifications,
        private readonly Dispatcher $events
    ) {
    }

    /** $quiet: no alerts, events or bounty (the Best Answer import). */
    public function set(Discussion $discussion, ?Post $post, User $actor, bool $quiet = false): void
    {
        // The discussion row is locked and the current acceptance read under the lock, so two accepts at once
        // (two tabs, the asker and a moderator) run one after the other: the second takes back the first's points.
        // Lock order shared with Votes: discussion → posts (the recount reads them) → reputation rows by user id → users.
        $previous = $this->reputation->transaction(function () use ($discussion, $post) {
            $currentId = $this->db->table('discussions')->where('id', $discussion->id)->lockForUpdate()->value('answers_accepted_post_id');
            $previous = $currentId ? Post::query()->find($currentId) : null;
            if ($previous?->id === $post?->id) {
                $discussion->answers_accepted_post_id = $post?->id;

                return false;
            }
            $tags = Question::tagIds($discussion);

            $this->db->table('discussions')->where('id', $discussion->id)->update(['answers_accepted_post_id' => $post?->id]);
            $discussion->answers_accepted_post_id = $post?->id;
            $this->questions->recount($discussion->id);

            $this->reputation->lock([$previous?->user_id, $post?->user_id, $discussion->user_id], $tags);
            if ($previous) {
                $this->reputation->takeBack($previous->user_id, 'accepted', $discussion->id, $previous->id);
                $this->reputation->takeBack($discussion->user_id, 'accepter', $discussion->id, $previous->id);
            }
            if ($post && $post->user_id !== $discussion->user_id) {
                $this->reputation->give($post->user_id, $tags, $this->settings->int('points_accepted'), 'accepted', $discussion->id, $post->id);
                $this->reputation->give($discussion->user_id, $tags, $this->settings->int('points_accepter'), 'accepter', $discussion->id, $post->id);
            }

            // Users last, in id order.
            $counts = [];
            if ($previous) {
                $counts[] = [$previous->user_id, 'answers_accepted_count', -1];
            }
            if ($post) {
                $counts[] = [$post->user_id, 'answers_accepted_count', 1];
            }
            // The asker's "solved" count moves only when the question changes between solved and not.
            if (! $previous && $post) {
                $counts[] = [$discussion->user_id, 'answers_solved_count', 1];
            } elseif ($previous && ! $post) {
                $counts[] = [$discussion->user_id, 'answers_solved_count', -1];
            }
            usort($counts, fn ($a, $b) => (int) $a[0] <=> (int) $b[0]);
            foreach ($counts as [$userId, $column, $by]) {
                $this->count($userId, $column, $by);
            }

            return $previous;
        });
        if ($previous === false) {
            return;
        }
        $discussion->answers_state = (int) $this->db->table('discussions')->where('id', $discussion->id)->value('answers_state');

        if ($previous && ! $quiet) {
            $this->notifications->delete(new AnswerAcceptedBlueprint($previous, $actor));
        }

        if ($quiet) {
            return;
        }

        if ($post) {
            if ($post->user_id && $post->user_id !== $actor->id && $post->user) {
                $this->notifications->sync(new AnswerAcceptedBlueprint($post, $actor), [$post->user]);
            }
            $this->bounties->payOnAccept($discussion, $post);
            $this->events->dispatch(new AnswerAccepted($discussion, $post, $actor));
        } elseif ($previous) {
            $this->events->dispatch(new AnswerUnaccepted($discussion, $previous, $actor));
        }
    }

    /** Counters are UNSIGNED on MySQL: never subtract below zero. */
    private function count(?int $userId, string $column, int $by): void
    {
        if (! $userId) {
            return;
        }
        $query = $this->db->table('users')->where('id', $userId);
        $by > 0 ? $query->increment($column, $by) : $query->where($column, '>', 0)->decrement($column, -$by);
    }
}
