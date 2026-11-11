<?php

namespace ErnestDefoe\Answers;

use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Illuminate\Database\ConnectionInterface;

/**
 * Which discussions are questions, and their answer count and state. Both are
 * stored on the discussion, so lists read them for free; they are written
 * here and nowhere else, with set-based UPDATEs (a tag switched on can cover
 * thousands of discussions).
 */
class Question
{
    public const UNANSWERED = 0;
    public const ANSWERED = 1;
    public const SOLVED = 2;
    public const DUPLICATE = 3;

    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    public static function is(?Discussion $discussion): bool
    {
        return $discussion !== null && (bool) $discussion->answers_question;
    }

    /** An answer: a visible-or-not comment in a question that is neither the question nor a comment. */
    public static function isAnswer(Post $post): bool
    {
        return $post->type === 'comment'
            && $post->answers_parent_id === null
            && $post->discussion !== null
            && self::is($post->discussion)
            && $post->id !== $post->discussion->first_post_id
            && (int) $post->number !== 1;
    }

    /** @return int[] the discussion's Q&A tags (its tags are usually loaded already). */
    public static function tagIds(Discussion $discussion): array
    {
        return $discussion->tags->where('answers_enabled', true)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /** Re-read whether these discussions sit in a Q&A tag, then recount them. */
    public function refreshFlags(array $discussionIds): void
    {
        foreach (array_chunk(array_values(array_unique($discussionIds)), 500) as $ids) {
            $qa = $this->db->table('discussion_tag')
                ->join('tags', 'tags.id', '=', 'discussion_tag.tag_id')
                ->whereIn('discussion_tag.discussion_id', $ids)
                ->where('tags.answers_enabled', true)
                ->distinct()
                ->pluck('discussion_tag.discussion_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $this->db->table('discussions')->whereIn('id', $ids)->whereNotIn('id', $qa)->update(['answers_question' => false]);
            if ($qa) {
                $this->db->table('discussions')->whereIn('id', $qa)->update(['answers_question' => true]);
                $this->recount($qa);
            }
        }
    }

    /** A tag was switched on or off: every discussion in it. */
    public function refreshTag(int $tagId): void
    {
        $this->db->table('discussion_tag')->where('tag_id', $tagId)->orderBy('discussion_id')
            ->chunk(1000, fn ($rows) => $this->refreshFlags($rows->pluck('discussion_id')->map(fn ($id) => (int) $id)->all()));
    }

    /** @param int[]|int $discussionIds */
    public function recount(array|int $discussionIds): void
    {
        foreach (array_chunk((array) $discussionIds, 500) as $ids) {
            // A correlated count, built by the query builder so the table prefix is applied to every name.
            $answers = $this->db->table('posts')
                ->selectRaw('count(*)')
                ->whereColumn('posts.discussion_id', 'discussions.id')
                ->whereColumn('posts.id', '<>', 'discussions.first_post_id')
                ->whereRaw("{$this->col('posts.type')} = 'comment'")
                ->whereNull('posts.answers_parent_id')
                ->whereNull('posts.hidden_at')
                ->whereRaw("not {$this->col('posts.is_private')}");

            $this->db->table('discussions')->whereIn('id', $ids)
                ->update(['answers_count' => $this->db->raw('('.$answers->toSql().')')]);

            // A second statement: PostgreSQL's SET reads the old row, so the state can't see the new count in the first.
            $this->db->table('discussions')->whereIn('id', $ids)->update(['answers_state' => $this->db->raw(
                'case when '.$this->col('answers_duplicate_of_id').' is not null then '.self::DUPLICATE
                .' when '.$this->col('answers_accepted_post_id').' is not null then '.self::SOLVED
                .' when '.$this->col('answers_count').' > 0 then '.self::ANSWERED
                .' else '.self::UNANSWERED.' end'
            )]);
        }
    }

    private function col(string $name): string
    {
        return $this->db->getQueryGrammar()->wrap($name);
    }
}
