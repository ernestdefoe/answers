<?php

namespace ErnestDefoe\Answers\Api;

use ErnestDefoe\Answers\Access;
use ErnestDefoe\Answers\Question;
use ErnestDefoe\Answers\Settings;
use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Flarum\Http\SlugManager;
use Flarum\Tags\Tag;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Reads: a post's revisions (with the current source for suggesting an
 * edit), similar solved questions for the composer (by title), a tag's top helpers and
 * a member's reputation. Plain JSON; every text field is shown as text.
 */
class ReadController implements RequestHandlerInterface
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Access $access,
        private readonly RateLimit $limits,
        private readonly Cache $cache,
        private readonly SlugManager $slugs
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return match ($request->getAttribute('routeName')) {
            'answers.revisions' => $this->revisions($request),
            'answers.similar' => $this->similar($request),
            'answers.helpers' => $this->helpers($request),
            'answers.reputation' => $this->reputation($request),
            default => throw new ModelNotFoundException(),
        };
    }

    private function revisions(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $post = Input::post($request, $actor);
        $history = $this->access->canSeeHistory($actor, $post);

        $rows = ! $history ? collect() : $this->db->table('answers_revisions')->where('post_id', $post->id)->orderBy('id')->get();
        $users = $this->users($actor, $rows->pluck('user_id')->filter()->unique()->all());

        $revisions = [];
        foreach ($rows->values() as $i => $row) {
            $revisions[] = [
                'id' => (int) $row->id,
                'number' => $i + 1,
                'content' => $row->content,
                'summary' => $row->summary,
                'createdAt' => $this->date($row->created_at),
                'user' => $users[$row->user_id] ?? null,
            ];
        }

        // The current source is what a suggested edit starts from (core only sends it to editors).
        $canStart = $actor->can('edit', $post) || (Question::isAnswer($post) && $this->access->canSuggest($actor, $post));

        return new JsonResponse([
            'revisions' => $revisions,
            'source' => $canStart ? (string) $post->content : null,
            'canRestore' => $actor->can('edit', $post),
        ]);
    }

    private function similar(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        // Only the composer asks, and only members can start a discussion.
        $actor->assertRegistered();
        $q = trim((string) ($request->getQueryParams()['q'] ?? ''));
        if (mb_strlen($q) < 12) {
            return new JsonResponse(['data' => []]);
        }
        $this->limits->check($actor, 'similar', 30);

        // Titles only, word by word, ranked by how many words match: the same on every database and search
        // driver, and cheap, since only solved questions are read (the answers_state index).
        $words = array_slice(array_unique(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(mb_substr($q, 0, 200))) ?: [],
            fn ($w) => mb_strlen($w) >= 4
        )), 0, 8);
        if (! $words) {
            return new JsonResponse(['data' => []]);
        }

        $candidates = Discussion::query()->whereVisibleTo($actor)
            ->where('discussions.answers_question', true)
            ->where('discussions.answers_state', Question::SOLVED)
            ->where(function ($query) use ($words) {
                // lower() on both sides: PostgreSQL's LIKE is case-sensitive.
                $title = 'lower('.$this->db->getQueryGrammar()->wrap('discussions.title').')';
                foreach ($words as $word) {
                    $query->orWhereRaw($title.' like ?', ['%'.addcslashes($word, '%_\\').'%']);
                }
            })
            ->orderByDesc('discussions.last_posted_at')->limit(50)
            ->get(['discussions.id', 'discussions.title', 'discussions.slug', 'discussions.answers_count']);

        $scored = $candidates->map(function (Discussion $d) use ($words) {
            $title = mb_strtolower($d->title);

            return [$d, count(array_filter($words, fn ($w) => str_contains($title, $w)))];
        })->filter(fn ($pair) => $pair[1] >= min(2, count($words)))->sortByDesc(fn ($pair) => $pair[1])->take(5);

        return new JsonResponse(['data' => $scored->map(fn ($pair) => [
            'id' => $pair[0]->id,
            'slug' => $pair[0]->slug,
            'title' => $pair[0]->title,
            'answers' => (int) $pair[0]->answers_count,
        ])->values()->all()]);
    }

    private function helpers(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $tag = Tag::query()->whereVisibleTo($actor)->where('slug', (string) ($request->getQueryParams()['tag'] ?? ''))->first();
        if (! $tag || ! $tag->answers_enabled) {
            throw new ModelNotFoundException();
        }

        // Ten minutes is fresh enough for a leaderboard, and the same for every viewer of the tag.
        $top = $this->cache->remember('ernestdefoe-answers.helpers.'.$tag->id, 600, fn () => $this->db->table('answers_reputation')
            ->where('tag_id', $tag->id)->where('points', '>', 0)
            ->orderByDesc('points')->orderBy('user_id')->limit(5)
            ->get(['user_id', 'points'])->map(fn ($r) => ['userId' => (int) $r->user_id, 'points' => (int) $r->points])->all());

        $users = $this->users($actor, array_column($top, 'userId'));

        return new JsonResponse(['data' => array_values(array_filter(array_map(
            fn ($row) => isset($users[$row['userId']]) ? $users[$row['userId']] + ['points' => $row['points']] : null,
            $top
        )))]);
    }

    private function reputation(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $user = User::query()->whereVisibleTo($actor)->find(Input::id($request));
        if (! $user) {
            throw new ModelNotFoundException();
        }

        $visibleTags = Tag::query()->whereVisibleTo($actor)->where('answers_enabled', true)->get(['id', 'name', 'slug', 'color'])->keyBy('id');
        $tags = [];
        foreach ($this->db->table('answers_reputation')->where('user_id', $user->id)->whereIn('tag_id', $visibleTags->keys())->where('points', '<>', 0)->orderByDesc('points')->get() as $row) {
            $tag = $visibleTags[$row->tag_id];
            $tags[] = ['id' => $tag->id, 'name' => $tag->name, 'slug' => $tag->slug, 'color' => $tag->color, 'points' => (int) $row->points];
        }

        // History: only entries whose question the viewer may see.
        $log = $this->db->table('answers_reputation_log as l')
            ->leftJoin('discussions as d', 'd.id', '=', 'l.discussion_id')
            ->where('l.user_id', $user->id)
            ->whereIn('l.tag_id', $visibleTags->keys())
            ->where(fn ($q) => $q->whereNull('l.discussion_id')->orWhereIn('l.discussion_id', Discussion::query()->whereVisibleTo($actor)->select('discussions.id')))
            ->orderByDesc('l.id')->limit(30)
            ->get(['l.id', 'l.reason', 'l.points', 'l.created_at', 'l.post_id', 'd.id as discussion_id', 'd.title', 'd.slug']);

        return new JsonResponse([
            'tags' => $tags,
            'accepted' => (int) $user->answers_accepted_count,
            'solved' => (int) $user->answers_solved_count,
            'log' => $log->map(fn ($r) => [
                'reason' => $r->reason, 'points' => (int) $r->points, 'createdAt' => $this->date($r->created_at),
                'discussion' => $r->discussion_id ? ['id' => (int) $r->discussion_id, 'title' => $r->title, 'slug' => $r->slug] : null,
            ])->all(),
        ]);
    }

    /**
     * @param int[] $ids
     * @return array<int, array{id: int, username: string, displayName: string, avatarUrl: ?string, slug: string}>
     */
    private function users(User $actor, array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $slugs = $this->slugs->forResource(User::class);

        return User::query()->whereVisibleTo($actor)->whereIn('id', $ids)->get()->mapWithKeys(fn (User $u) => [$u->id => [
            'id' => $u->id, 'username' => $u->username, 'displayName' => $u->display_name, 'avatarUrl' => $u->avatar_url, 'slug' => $slugs->toSlug($u),
        ]])->all();
    }

    private function date(mixed $value): ?string
    {
        return $value ? (new \DateTime((string) $value))->format(\DateTime::RFC3339) : null;
    }
}
