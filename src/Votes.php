<?php

namespace ErnestDefoe\Answers;

use Carbon\Carbon;
use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Up and down votes on questions and answers, upvotes on comments.
 *
 * The score is a column moved by the difference, and reputation follows the
 * vote: what the old vote gave is taken back before the new one gives. With
 * FoF Gamification on, the same vote is cast there too, through its own
 * vote(), so its points, ranks and leaderboards count it.
 */
class Votes
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Reputation $reputation,
        private readonly Settings $settings,
        private readonly Integrations $integrations,
        private readonly Reviews $reviews,
        private readonly Container $container
    ) {
    }

    /** @return array{score: int, vote: int} */
    public function cast(Post $post, User $actor, int $value): array
    {
        $discussion = $post->discussion;
        $isComment = $post->answers_parent_id !== null;
        $isQuestion = $post->id === $discussion->first_post_id;

        if ($value < -1 || $value > 1 || ($isComment && $value < 0)) {
            throw Fail::on('value', 'invalid');
        }
        if ($isComment && ! Question::commentsOn($discussion)) {
            throw Fail::on('value', 'comments_off');
        }

        // One transaction, locks taken in the order every writer here uses (discussion → post → reputation rows
        // by user id): concurrent votes on a post queue up, each reads the vote it replaces only under the lock
        // (no replaying one change to farm reputation), and a vote can't deadlock against an accept. Deadlocks
        // that slip through are retried; reputation events go out after the commit, the Gamification mirror too.
        try {
            [$old, $score] = $this->reputation->transaction(function () use ($post, $actor, $value, $discussion, $isComment, $isQuestion) {
                $this->db->table('discussions')->where('id', $discussion->id)->lockForUpdate()->value('id');
                $score = (int) $this->db->table('posts')->where('id', $post->id)->lockForUpdate()->value('answers_score');
                $old = (int) $this->db->table('answers_votes')->where('post_id', $post->id)->where('user_id', $actor->id)->value('value');
                if ($old === $value) {
                    return [$old, $score];
                }

                $rows = $this->db->table('answers_votes')->where('post_id', $post->id)->where('user_id', $actor->id);
                if ($value === 0) {
                    $rows->delete();
                } elseif ($old === 0) {
                    $this->db->table('answers_votes')->insert(['post_id' => $post->id, 'user_id' => $actor->id, 'value' => $value, 'created_at' => Carbon::now()]);
                } else {
                    $rows->update(['value' => $value]);
                }
                $score += $value - $old;
                $this->db->table('posts')->where('id', $post->id)->update(['answers_score' => $score]);
                if ($isQuestion) {
                    $this->db->table('discussions')->where('id', $discussion->id)->update(['answers_score' => $score]);
                }

                if (! $isComment) {
                    $tags = Question::tagIds($discussion);
                    $this->reputation->lock([$post->user_id, $actor->id], $tags);
                    $this->undo($post, $actor, $old, $isQuestion);
                    $this->apply($post, $actor, $value, $isQuestion, $tags);
                }

                return [$old, $score];
            });
        } catch (UniqueConstraintViolationException) {
            // Only reachable without row locks (SQLite): the first of two identical clicks counted.
            return ['score' => (int) $this->db->table('posts')->where('id', $post->id)->value('answers_score'), 'vote' => $value];
        }

        $post->answers_score = $score;
        if ($old !== $value && ! $isComment) {
            $this->mirror($post, $actor, $value);
        }

        if ($old !== $value && ! $isComment && ! $isQuestion && $value < 0 && $score <= $this->settings->int('low_quality_score')) {
            $this->reviews->open('low_quality', $post);
        }

        return ['score' => $score, 'vote' => $value];
    }

    /** A hidden post was restored: give back what each of its votes earns, as if cast again. */
    public function restore(Post $post): void
    {
        $discussion = $post->discussion;
        if ($post->answers_parent_id !== null || ! Question::is($discussion)) {
            return;
        }
        $isQuestion = $post->id === $discussion->first_post_id;
        $tags = Question::tagIds($discussion);
        $this->reputation->forgetVotes($post->id);
        foreach ($this->db->table('answers_votes')->where('post_id', $post->id)->get(['user_id', 'value']) as $vote) {
            $voter = new User();
            $voter->id = (int) $vote->user_id;
            $this->apply($post, $voter, (int) $vote->value, $isQuestion, $tags);
        }
    }

    private function apply(Post $post, User $actor, int $value, bool $isQuestion, array $tags): void
    {
        if ($value > 0) {
            $this->reputation->give($post->user_id, $tags, $this->settings->int($isQuestion ? 'points_question_up' : 'points_answer_up'), 'upvote', $post->discussion_id, $post->id, $actor->id);
        } elseif ($value < 0) {
            $this->reputation->give($post->user_id, $tags, $this->settings->int('points_down'), 'downvote', $post->discussion_id, $post->id, $actor->id);
            if (! $isQuestion) {
                $this->reputation->give($actor->id, $tags, $this->settings->int('points_voter_down'), 'downvote_cast', $post->discussion_id, $post->id);
            }
        }
    }

    private function undo(Post $post, User $actor, int $old, bool $isQuestion): void
    {
        if ($old > 0) {
            $this->reputation->takeBack($post->user_id, 'upvote', $post->discussion_id, $post->id, $actor->id);
        } elseif ($old < 0) {
            $this->reputation->takeBack($post->user_id, 'downvote', $post->discussion_id, $post->id, $actor->id);
            if (! $isQuestion) {
                $this->reputation->takeBack($actor->id, 'downvote_cast', $post->discussion_id, $post->id);
            }
        }
    }

    /** Cast the same vote in FoF Gamification, through its own method so its points, ranks and alerts follow. */
    private function mirror(Post $post, User $actor, int $value): void
    {
        if (! $this->settings->bool('gamification') || ! $this->integrations->gamification()) {
            return;
        }
        $fields = $this->container->make(\FoF\Gamification\Api\PostResourceFields::class);
        if (method_exists($fields, 'vote')) {
            $fields->vote($post, $value > 0 ? 'up' : ($value < 0 ? 'down' : null), $actor);
        }
    }
}
