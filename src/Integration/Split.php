<?php

namespace ErnestDefoe\Answers\Integration;

use ErnestDefoe\Answers\Accept;
use ErnestDefoe\Answers\Question;
use Flarum\Discussion\Discussion;
use Illuminate\Database\ConnectionInterface;

/**
 * FoF Split moves a range of posts (by number) into a new discussion. Comments don't sit next to their
 * answer in that range, so after a split: comments whose answer moved follow it, comments whose answer
 * stayed become ordinary replies where they landed, and a question whose accepted answer moved away is
 * no longer solved. Registered only while fof/split is enabled.
 */
class Split
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Accept $accept,
        private readonly Question $questions
    ) {
    }

    public function handle(object $event): void
    {
        /** @var Discussion $original */
        $original = $event->originalDiscussion;
        /** @var Discussion $new */
        $new = $event->newDiscussion;

        $moved = $this->db->table('posts')->where('discussion_id', $new->id)->pluck('id')->map(fn ($id) => (int) $id)->all();

        // Left behind under an answer that moved: they go with it, and the new discussion is renumbered.
        $behind = $this->db->table('posts')->where('discussion_id', $original->id)->whereIn('answers_parent_id', $moved ?: [0])->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($behind) {
            $this->db->table('posts')->whereIn('id', $behind)->update(['discussion_id' => $new->id]);
            $this->renumber($new->id);
            $moved = array_merge($moved, $behind);
        }

        // Moved without their answer: plain replies now.
        $this->db->table('posts')->where('discussion_id', $new->id)
            ->whereNotNull('answers_parent_id')->whereNotIn('answers_parent_id', $moved)
            ->update(['answers_parent_id' => null]);
        $this->db->table('posts')->where('id', $new->first_post_id)->update(['answers_parent_id' => null]);

        $original = Discussion::query()->with('tags')->find($original->id);
        if ($original && in_array((int) $original->answers_accepted_post_id, $moved, true)) {
            $this->accept->set($original, null, $event->actor, true);
        }

        // What the moved posts earned, and their review items, now belong to the new discussion (an un-vote
        // there looks its reputation up by the post's discussion). After the unaccept above took its points back.
        if ($moved) {
            $this->db->table('answers_reputation_log')->whereIn('post_id', $moved)->update(['discussion_id' => $new->id]);
            $this->db->table('answers_reviews')->whereIn('post_id', $moved)->update(['discussion_id' => $new->id]);
        }

        foreach ([$original, Discussion::query()->find($new->id)] as $discussion) {
            if ($discussion) {
                $discussion->refreshCommentCount();
                $discussion->refreshLastPost();
                $discussion->refreshParticipantCount();
                $discussion->save();
            }
        }
        $this->questions->refreshFlags(array_filter([$original?->id, $new->id]));
    }

    /** Numbers by date, as FoF Split does, in two passes so the (discussion, number) unique key never clashes. */
    private function renumber(int $discussionId): void
    {
        $ids = $this->db->table('posts')->where('discussion_id', $discussionId)->orderBy('created_at')->orderBy('id')->pluck('id');
        $this->db->table('posts')->where('discussion_id', $discussionId)->update(['number' => $this->db->raw($this->db->getQueryGrammar()->wrap('number').' + 1000000')]);
        foreach ($ids as $i => $id) {
            $this->db->table('posts')->where('id', $id)->update(['number' => $i + 1]);
        }
    }
}
