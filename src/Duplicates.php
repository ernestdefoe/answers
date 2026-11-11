<?php

namespace ErnestDefoe\Answers;

use Carbon\Carbon;
use ErnestDefoe\Answers\Event\QuestionClosedAsDuplicate;
use Flarum\Discussion\Discussion;
use Flarum\Foundation\ValidationException;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;

/**
 * Closing a question as a duplicate of another: a moderator at once, or
 * close_votes trusted members naming the same original. A closed question
 * takes no new answers (comments still work) and links to the original.
 */
class Duplicates
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Settings $settings,
        private readonly Question $questions,
        private readonly Dispatcher $events
    ) {
    }

    /** @return int votes so far for that original (0 once it is closed). */
    public function vote(Discussion $discussion, Discussion $original, User $actor, bool $moderator): int
    {
        // Point at the root: a duplicate of a duplicate is a duplicate of its original.
        if ($original->answers_duplicate_of_id) {
            $original = Discussion::query()->find($original->answers_duplicate_of_id) ?? $original;
        }
        if ($original->id === $discussion->id || ! Question::is($original)) {
            throw new ValidationException(['duplicateOf' => 'invalid']);
        }

        if ($moderator) {
            $this->close($discussion, $original, $actor);

            return 0;
        }

        $this->db->table('answers_close_votes')->where('discussion_id', $discussion->id)->where('user_id', $actor->id)->delete();
        $this->db->table('answers_close_votes')->insert([
            'discussion_id' => $discussion->id, 'user_id' => $actor->id, 'duplicate_of_id' => $original->id, 'created_at' => Carbon::now(),
        ]);

        $votes = $this->db->table('answers_close_votes')->where('discussion_id', $discussion->id)->where('duplicate_of_id', $original->id)->count();
        if ($votes >= max(1, $this->settings->int('close_votes'))) {
            $this->close($discussion, $original, $actor);

            return 0;
        }

        return $votes;
    }

    public function reopen(Discussion $discussion): void
    {
        $this->db->table('discussions')->where('id', $discussion->id)->update(['answers_duplicate_of_id' => null]);
        $this->db->table('answers_close_votes')->where('discussion_id', $discussion->id)->delete();
        $discussion->answers_duplicate_of_id = null;
        $this->questions->recount($discussion->id);
    }

    private function close(Discussion $discussion, Discussion $original, User $actor): void
    {
        $this->db->table('discussions')->where('id', $discussion->id)->update(['answers_duplicate_of_id' => $original->id]);
        $this->db->table('answers_close_votes')->where('discussion_id', $discussion->id)->delete();
        $discussion->answers_duplicate_of_id = $original->id;
        $this->questions->recount($discussion->id);
        $this->events->dispatch(new QuestionClosedAsDuplicate($discussion, $original, $actor));
    }
}
