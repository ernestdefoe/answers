<?php

namespace ErnestDefoe\Answers;

use Carbon\Carbon;
use Flarum\Post\CommentPost;
use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;

/**
 * The review queue: low-quality answers, a member's first answer, and edits
 * suggested by members who can't edit directly. An item settles when enough
 * reviewers agree (the review_approvals setting) or when a moderator decides.
 */
class Reviews
{
    public const VERDICTS = [
        'low_quality' => ['ok', 'hide'],
        'first_answer' => ['ok', 'hide'],
        'suggested_edit' => ['approve', 'reject'],
    ];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Settings $settings,
        private readonly Revisions $revisions,
        private readonly Dispatcher $events
    ) {
    }

    /** Queue a post once per kind (suggested edits: once per suggester while one is open). */
    public function open(string $type, Post $post, ?int $userId = null, ?string $content = null, ?string $summary = null): ?int
    {
        $existing = $this->db->table('answers_reviews')->where('type', $type)->where('post_id', $post->id);
        if ($type === 'suggested_edit') {
            $existing->where('user_id', $userId)->where('status', 'open');
        }
        if ($existing->exists()) {
            return null;
        }

        return $this->db->table('answers_reviews')->insertGetId([
            'type' => $type,
            'post_id' => $post->id,
            'discussion_id' => $post->discussion_id,
            'user_id' => $userId ?? $post->user_id,
            'content' => $content,
            'summary' => $summary,
            // What the answer said when the edit was suggested: approving it later must not undo a newer edit.
            'base_hash' => $type === 'suggested_edit' ? self::hash($post) : null,
            'status' => 'open',
            'created_at' => Carbon::now(),
        ]);
    }

    private static function hash(Post $post): string
    {
        return sha1(trim((string) $post->content));
    }

    /**
     * One reviewer's verdict. Returns the item's status afterwards.
     * $content lets a reviewer who may edit improve a suggestion as they approve it.
     */
    public function decide(object $item, User $actor, string $verdict, bool $moderator, ?string $content = null): string
    {
        if ($content !== null && $verdict === 'approve') {
            // An improved suggestion is the reviewer's own edit: it applies now.
            return $this->settle($item, $actor, 'approve', $content);
        }

        $this->db->table('answers_review_votes')->insertOrIgnore([
            'review_id' => $item->id, 'user_id' => $actor->id, 'verdict' => $verdict, 'created_at' => Carbon::now(),
        ]);

        $agreeing = $this->db->table('answers_review_votes')->where('review_id', $item->id)->where('verdict', $verdict)->count();
        if ($moderator || $agreeing >= max(1, $this->settings->int('review_approvals'))) {
            return $this->settle($item, $actor, $verdict, null);
        }

        return 'open';
    }

    private function settle(object $item, User $actor, string $verdict, ?string $content): string
    {
        $post = CommentPost::query()->find($item->post_id);
        $status = ['ok' => 'ok', 'hide' => 'hidden', 'approve' => 'approved', 'reject' => 'rejected'][$verdict];

        // The answer changed since the edit was suggested: applying it would throw the newer edit away. A
        // reviewer's improved text is their own edit, made looking at the answer as it is now, so it applies.
        if ($post && $verdict === 'approve' && $content === null && $item->base_hash && ! hash_equals($item->base_hash, self::hash($post))) {
            $verdict = 'outdated';
            $status = 'outdated';
        }

        if ($post && $verdict === 'hide') {
            $post->hide($actor);
            $post->save();
            // Saving doesn't release raised events; Hidden recounts the question (and un-accepts it).
            foreach ($post->releaseEvents() as $event) {
                $this->events->dispatch($event);
            }
        }

        if ($post && $verdict === 'approve') {
            // A suggestion is credited to the member who suggested it; an improvement to the reviewer.
            $editor = $content !== null ? $actor : (User::query()->find($item->user_id) ?? $actor);
            $this->revisions->edit($post, $content ?? (string) $item->content, $editor, $item->summary);
        }

        $this->db->table('answers_reviews')->where('id', $item->id)->update([
            'status' => $status, 'resolved_user_id' => $actor->id, 'resolved_at' => Carbon::now(),
        ]);

        return $status;
    }
}
