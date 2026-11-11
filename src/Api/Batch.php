<?php

namespace ErnestDefoe\Answers\Api;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\ServerRequestInterface;
use WeakMap;

/**
 * Per-request batches for the post fields: every post in a response queues
 * its id, and the first deferred value resolved loads them all in one query
 * (the viewer's votes; the authors' reputation in the question's tags).
 * Keyed by the request object, so nothing outlives or crosses a viewer.
 */
class Batch
{
    /** @var WeakMap<ServerRequestInterface, array<string, mixed>> */
    private WeakMap $state;

    public function __construct(private readonly ConnectionInterface $db)
    {
        $this->state = new WeakMap();
    }

    public function queueVote(ServerRequestInterface $request, int $postId): void
    {
        $this->state[$request] ??= [];
        $this->state[$request]['votes'][$postId] = true;
    }

    public function vote(ServerRequestInterface $request, User $actor, int $postId): int
    {
        $state = $this->state[$request] ?? [];
        if (! isset($state['voteResults'])) {
            $ids = array_keys($state['votes'] ?? [$postId => true]);
            $state['voteResults'] = $this->db->table('answers_votes')->where('user_id', $actor->id)->whereIn('post_id', $ids)
                ->pluck('value', 'post_id')->map(fn ($v) => (int) $v)->all();
            $this->state[$request] = $state;
        }

        return $state['voteResults'][$postId] ?? 0;
    }

    /** @param int[] $tagIds */
    public function queueRep(ServerRequestInterface $request, int $userId, array $tagIds): void
    {
        $this->state[$request] ??= [];
        $this->state[$request]['repUsers'][$userId] = true;
        foreach ($tagIds as $tagId) {
            $this->state[$request]['repTags'][$tagId] = true;
        }
    }

    /** @param int[] $tagIds */
    public function rep(ServerRequestInterface $request, int $userId, array $tagIds): int
    {
        $state = $this->state[$request] ?? [];
        if (! isset($state['repResults'])) {
            $rows = $this->db->table('answers_reputation')
                ->whereIn('user_id', array_keys($state['repUsers'] ?? [$userId => true]))
                ->whereIn('tag_id', array_keys($state['repTags'] ?? array_flip($tagIds)))
                ->get(['user_id', 'tag_id', 'points']);
            $state['repResults'] = [];
            foreach ($rows as $row) {
                $state['repResults'][$row->user_id][$row->tag_id] = (int) $row->points;
            }
            $this->state[$request] = $state;
        }

        $best = 0;
        foreach ($tagIds as $tagId) {
            $best = max($best, $state['repResults'][$userId][$tagId] ?? 0);
        }

        return $best;
    }
}
