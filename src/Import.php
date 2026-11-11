<?php

namespace ErnestDefoe\Answers;

use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Builder;

/**
 * One-way import from FoF Best Answer: its Q&A tags (tags.is_qna, plus the
 * enabled-tags setting) become Q&A tags here, and each best answer becomes
 * the accepted answer, with the same reputation and counts an acceptance
 * gives. Safe to run again: questions that already have an accepted answer
 * are skipped. Best Answer's own columns are only read.
 */
class Import
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly SettingsRepositoryInterface $settings,
        private readonly Question $questions,
        private readonly Accept $accept
    ) {
    }

    public function available(): bool
    {
        return $this->db->getSchemaBuilder()->hasColumn('discussions', 'best_answer_post_id');
    }

    /** @return array{tags: int, questions: int, accepted: int} */
    public function run(): array
    {
        /** @var Builder $schema */
        $schema = $this->db->getSchemaBuilder();
        $tagIds = json_decode((string) $this->settings->get('fof-best-answer.enabled-tags', '[]'), true);
        $tagIds = is_array($tagIds) ? array_map('intval', $tagIds) : [];
        if ($schema->hasColumn('tags', 'is_qna')) {
            $tagIds = array_merge($tagIds, $this->db->table('tags')->where('is_qna', true)->pluck('id')->map(fn ($id) => (int) $id)->all());
        }
        $tagIds = array_values(array_unique(array_filter($tagIds)));

        $this->db->table('tags')->whereIn('id', $tagIds)->update(['answers_enabled' => true]);
        foreach ($tagIds as $tagId) {
            $this->questions->refreshTag($tagId);
        }

        $accepted = 0;
        if ($this->available()) {
            Discussion::query()
                ->where('answers_question', true)
                ->whereNull('answers_accepted_post_id')
                ->whereNotNull('best_answer_post_id')
                ->with('tags')
                ->chunkById(200, function ($discussions) use (&$accepted) {
                    $posts = Post::query()->whereIn('id', $discussions->pluck('best_answer_post_id'))->get()->keyBy('id');
                    foreach ($discussions as $discussion) {
                        $post = $posts[$discussion->best_answer_post_id] ?? null;
                        if (! $post) {
                            continue;
                        }
                        $post->setRelation('discussion', $discussion);
                        if (! Question::isAnswer($post) || $post->hidden_at !== null) {
                            continue;
                        }
                        $actor = User::query()->find($discussion->best_answer_user_id ?? $discussion->user_id) ?? new User();
                        $this->accept->set($discussion, $post, $actor, true);
                        $accepted++;
                    }
                });
        }

        return [
            'tags' => count($tagIds),
            'questions' => $this->db->table('discussions')->where('answers_question', true)->count(),
            'accepted' => $accepted,
        ];
    }
}
