<?php

namespace ErnestDefoe\Answers;

use Carbon\Carbon;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Revised;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;

/**
 * Full history of every post in a question. The original is written when the
 * first edit happens (nothing to store before then), then one row per edit,
 * holding the source text so any revision can be shown, diffed or restored.
 */
class Revisions
{
    private ?string $summary = null;

    public function __construct(private readonly ConnectionInterface $db, private readonly Dispatcher $events)
    {
    }

    /**
     * Edit a post outside the API (a rollback, an approved suggestion), with a summary for the
     * revision it makes. Saving a model doesn't release the events it raised; core's listeners
     * (mentions, search) and ours need Revised, so it is dispatched here.
     */
    public function edit(CommentPost $post, string $content, User $editor, ?string $summary): void
    {
        $this->summary = $summary !== null ? mb_substr(trim($summary), 0, 200) : null;
        try {
            $post->revise($content, $editor);
            $post->save();
            foreach ($post->releaseEvents() as $event) {
                $this->events->dispatch($event);
            }
        } finally {
            $this->summary = null;
        }
    }

    public function handle(Revised $event): void
    {
        $post = $event->post;
        if (! Question::is($post->discussion)) {
            return;
        }

        $rows = [];
        if ((int) $post->answers_revisions === 0) {
            $rows[] = ['post_id' => $post->id, 'user_id' => $post->user_id, 'content' => $event->oldContent, 'summary' => null, 'created_at' => $post->created_at ?? Carbon::now()];
        }
        $rows[] = ['post_id' => $post->id, 'user_id' => $event->actor->id, 'content' => (string) $post->content, 'summary' => $this->summary, 'created_at' => Carbon::now()];

        $this->db->table('answers_revisions')->insert($rows);
        $this->db->table('posts')->where('id', $post->id)->increment('answers_revisions', count($rows));
        $post->answers_revisions = (int) $post->answers_revisions + count($rows);
    }
}
