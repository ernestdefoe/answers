<?php

namespace ErnestDefoe\Answers\Listener;

use ErnestDefoe\Answers\Accept;
use ErnestDefoe\Answers\Access;
use ErnestDefoe\Answers\Question;
use ErnestDefoe\Answers\Reputation;
use ErnestDefoe\Answers\Reviews;
use ErnestDefoe\Answers\Settings;
use ErnestDefoe\Answers\Votes;
use Flarum\Discussion\Discussion;
use Flarum\Discussion\Event\Deleting as DiscussionDeleting;
use Flarum\Discussion\Event\Started;
use ErnestDefoe\Answers\Fail;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Deleted;
use Flarum\Post\Event\Deleting;
use Flarum\Post\Event\Hidden;
use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Restored;
use Flarum\Post\Event\Saving;
use Flarum\Post\Post;
use Flarum\Tags\Event\DiscussionWasTagged;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;

/**
 * Keeps a question's flag, answer count and state true as posts come and go,
 * checks a comment's parent before it is saved, refuses new answers on a
 * closed duplicate, and puts first and suspiciously short answers in review.
 */
class Lifecycle
{
    public function __construct(
        private readonly Question $questions,
        private readonly Accept $accept,
        private readonly Access $access,
        private readonly Reputation $reputation,
        private readonly Reviews $reviews,
        private readonly Votes $votes,
        private readonly Settings $settings,
        private readonly ConnectionInterface $db,
        private readonly Dispatcher $events
    ) {
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Saving::class, $this->saving(...));
        $events->listen(Posted::class, $this->posted(...));
        $events->listen(Hidden::class, $this->hidden(...));
        $events->listen(Restored::class, $this->restored(...));
        $events->listen(Deleting::class, $this->deleting(...));
        $events->listen(Deleted::class, fn (Deleted $e) => $this->recount($e->post));
        $events->listen(Started::class, fn (Started $e) => $this->retagged($e->discussion));
        $events->listen(DiscussionWasTagged::class, fn (DiscussionWasTagged $e) => $this->retagged($e->discussion));
        $events->listen(DiscussionDeleting::class, $this->discussionDeleting(...));
    }

    public function saving(Saving $event): void
    {
        $post = $event->post;
        if ($post->exists || ! $post instanceof CommentPost) {
            return;
        }
        $discussion = $post->discussion ?? Discussion::query()->find($post->discussion_id);
        $parentId = $post->answers_parent_id;

        if (! Question::is($discussion)) {
            $post->answers_parent_id = null;

            return;
        }

        if ($parentId === null) {
            if ($discussion->answers_duplicate_of_id && $discussion->first_post_id) {
                throw Fail::on('content', 'closed_as_duplicate');
            }

            return;
        }

        $parent = Post::query()->find($parentId);
        if (! $parent || $parent->discussion_id !== $discussion->id || $parent->answers_parent_id !== null
            || $parent->type !== 'comment' || $parent->hidden_at !== null || ! $parent->isVisibleTo($event->actor)) {
            throw Fail::on('answersParentId', 'invalid');
        }

        $source = (string) Arr::get($event->data, 'attributes.content', '');
        if (mb_strlen(trim($source)) > $this->settings->int('comment_length')) {
            throw Fail::on('content', 'comment_too_long');
        }
    }

    public function posted(Posted $event): void
    {
        $post = $event->post;
        if (! Question::isAnswer($post)) {
            return;
        }
        $this->questions->recount($post->discussion_id);
        $post->discussion->answers_count = (int) $this->db->table('discussions')->where('id', $post->discussion_id)->value('answers_count');

        // A post held for approval is private: nobody reviews it until a moderator approves it (approved()).
        if (! $post->is_private) {
            $this->queueReviews($post);
        }
    }

    /** flarum/approval let a held answer through: count it, and queue it as if it had just been posted. */
    public function approved(Post $post): void
    {
        if (Question::isAnswer($post)) {
            $this->questions->recount($post->discussion_id);
            $this->queueReviews($post);
        }
    }

    private function queueReviews(Post $post): void
    {
        $author = $post->user;
        if (! $author || $this->access->moderates($author, $post->discussion)) {
            return;
        }

        $earlier = $this->db->table('posts')
            ->join('discussions', 'discussions.id', '=', 'posts.discussion_id')
            ->where('discussions.answers_question', true)
            ->where('posts.user_id', $author->id)
            ->where('posts.type', 'comment')
            ->whereNull('posts.answers_parent_id')
            ->whereColumn('posts.id', '<>', 'discussions.first_post_id')
            ->where('posts.id', '<>', $post->id)
            ->exists();
        if (! $earlier) {
            $this->reviews->open('first_answer', $post);
        }

        // Very short, or nothing but a link: worth a second look.
        $text = trim(preg_replace('~https?://\S+~', '', strip_tags((string) $post->formatContent())) ?? '');
        if (mb_strlen($text) < $this->settings->int('low_quality_length')) {
            $this->reviews->open('low_quality', $post);
        }
    }

    public function hidden(Hidden $event): void
    {
        $post = $event->post;
        $discussion = $post->discussion;
        if (! Question::is($discussion)) {
            return;
        }
        if ($discussion->answers_accepted_post_id === $post->id) {
            $this->accept->set($discussion, null, $this->actorFor($post));
        }
        // A hidden post earns nothing: its votes' reputation goes until it's restored.
        $this->reputation->forgetVotes($post->id);
        $this->recount($post);
    }

    public function restored(Restored $event): void
    {
        $post = $event->post;
        if (Question::is($post->discussion)) {
            $this->votes->restore($post);
            $this->recount($post);
        }
    }

    public function deleting(Deleting $event): void
    {
        $post = $event->post;
        $discussion = $post->discussion;
        if (! Question::is($discussion)) {
            return;
        }
        if ($discussion->answers_accepted_post_id === $post->id) {
            $this->accept->set($discussion, null, $event->actor);
        }
        $this->reputation->forget(null, $post->id);

        // Its comments go first, through Flarum, so counts, search and other extensions hear about each one
        // (the foreign key's cascade is only the last resort for a delete made outside Flarum).
        if ($post->answers_parent_id === null) {
            foreach (CommentPost::query()->where('answers_parent_id', $post->id)->get() as $comment) {
                $this->events->dispatch(new Deleting($comment, $event->actor, []));
                $comment->delete();
                foreach ($comment->releaseEvents() as $raised) {
                    $this->events->dispatch($raised);
                }
            }
        }
    }

    public function discussionDeleting(DiscussionDeleting $event): void
    {
        $discussion = $event->discussion;
        if (! Question::is($discussion)) {
            return;
        }
        if ($discussion->answers_accepted_post_id) {
            $this->accept->set($discussion, null, $event->actor);
        }
        $this->reputation->forget($discussion->id, null);
    }

    /** Tags changed (or were first set): re-read the flag, and refresh the model the response is built from. */
    private function retagged(Discussion $discussion): void
    {
        $this->questions->refreshFlags([$discussion->id]);
        $row = $this->db->table('discussions')->where('id', $discussion->id)->first(['answers_question', 'answers_count', 'answers_state']);
        $discussion->answers_question = (bool) $row->answers_question;
        $discussion->answers_count = (int) $row->answers_count;
        $discussion->answers_state = (int) $row->answers_state;
        $discussion->unsetRelation('tags');
    }

    private function recount(Post $post): void
    {
        if ($post->discussion_id && Question::is($post->discussion)) {
            $this->questions->recount($post->discussion_id);
        }
    }

    private function actorFor(Post $post): User
    {
        return ($post->hidden_user_id ? User::query()->find($post->hidden_user_id) : null) ?? $post->discussion->user ?? new User();
    }
}
