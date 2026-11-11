<?php

namespace ErnestDefoe\Answers\Api;

use ErnestDefoe\Answers\Access;
use ErnestDefoe\Answers\Question;
use ErnestDefoe\Answers\Reputation;
use ErnestDefoe\Answers\Reviews;
use ErnestDefoe\Answers\Settings;
use Flarum\Discussion\Discussion;
use ErnestDefoe\Answers\Fail;
use Flarum\Http\RequestUtil;
use Flarum\Post\CommentPost;
use Flarum\Post\Post;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/answers/reviews?type=…: the open items this reviewer may judge
 * (questions they can see, in tags where they have the right and the
 * reputation, never their own posts or suggestions), with counts per type.
 * POST /api/answers/reviews/{id} {verdict, content?}: judge one.
 */
class ReviewController implements RequestHandlerInterface
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Access $access,
        private readonly Reviews $reviews,
        private readonly Reputation $reputation,
        private readonly Settings $settings
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        return $request->getMethod() === 'POST' ? $this->decide($request, $actor) : $this->index($request, $actor);
    }

    /** The open items an actor may review, as a query (also used for the header count). */
    public static function queue(ConnectionInterface $db, User $actor, Reputation $reputation, Settings $settings): ?Builder
    {
        if ($actor->isGuest() || (! $actor->hasPermissionLike('answersReview') && ! $actor->hasPermissionLike('answersModerate'))) {
            return null;
        }

        // Tags where this member's reputation is enough; moderators review everywhere they moderate.
        $earned = array_keys(array_filter($reputation->forUser($actor->id), fn ($p) => $p >= $settings->int('review_reputation')));
        if (! $earned && ! $actor->hasPermissionLike('answersModerate')) {
            return null;
        }

        return $db->table('answers_reviews')
            ->join('posts', 'posts.id', '=', 'answers_reviews.post_id')
            ->where('answers_reviews.status', 'open')
            ->whereNull('posts.hidden_at')
            ->where(fn ($q) => $q->whereNull('posts.user_id')->orWhere('posts.user_id', '<>', $actor->id))
            ->where(fn ($q) => $q->whereNull('answers_reviews.user_id')->orWhere('answers_reviews.user_id', '<>', $actor->id))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('answers_review_votes')
                ->whereColumn('answers_review_votes.review_id', 'answers_reviews.id')->where('answers_review_votes.user_id', $actor->id))
            ->whereIn('answers_reviews.discussion_id', Discussion::query()->whereVisibleTo($actor)->where('answers_question', true)
                ->unless($actor->hasPermissionLike('answersModerate'), fn ($q) => $q
                    ->whereIn('discussions.id', fn ($sub) => $sub->select('discussion_id')->from('discussion_tag')->whereIn('tag_id', $earned ?: [0])))
                ->select('discussions.id'));
    }

    private function index(ServerRequestInterface $request, User $actor): ResponseInterface
    {
        $query = self::queue($this->db, $actor, $this->reputation, $this->settings);
        if (! $query) {
            // Nothing this member may review (yet): an empty queue, and the page says why.
            return new JsonResponse(['counts' => [], 'items' => [], 'needed' => $this->settings->int('review_approvals'), 'denied' => true]);
        }

        $counts = (clone $query)->groupBy('answers_reviews.type')->select('answers_reviews.type')->selectRaw('count(*) as n')->pluck('n', 'type');
        $type = (string) ($request->getQueryParams()['type'] ?? '');
        $rows = (clone $query)->when(isset(Reviews::VERDICTS[$type]), fn ($q) => $q->where('answers_reviews.type', $type))
            ->orderBy('answers_reviews.id')->limit(20)
            ->get(['answers_reviews.id', 'answers_reviews.type', 'answers_reviews.post_id', 'answers_reviews.content', 'answers_reviews.summary', 'answers_reviews.user_id', 'answers_reviews.created_at']);

        // Re-check each against the real rules (a moderator query is broad; tag permissions are per question).
        $posts = Post::query()->whereIn('id', $rows->pluck('post_id'))->with(['discussion.tags', 'user'])->get()->keyBy('id');
        $suggesters = User::query()->whereIn('id', $rows->pluck('user_id')->filter())->get()->keyBy('id');

        $items = [];
        foreach ($rows as $row) {
            $post = $posts[$row->post_id] ?? null;
            if (! $post || ! $this->access->canReview($actor, $post->discussion)) {
                continue;
            }
            $items[] = [
                'id' => (int) $row->id,
                'type' => $row->type,
                'post' => ['id' => $post->id, 'number' => $post->number, 'source' => $row->type === 'suggested_edit' ? (string) $post->content : null, 'html' => $post instanceof CommentPost ? $post->formatContent($request) : '', 'score' => (int) $post->answers_score],
                'discussion' => ['id' => $post->discussion->id, 'title' => $post->discussion->title, 'slug' => $post->discussion->slug],
                'author' => $post->user ? ['username' => $post->user->username, 'displayName' => $post->user->display_name, 'joinedAt' => $post->user->joined_at?->format(\DateTime::RFC3339)] : null,
                'suggester' => $row->type === 'suggested_edit' && isset($suggesters[$row->user_id]) ? ['username' => $suggesters[$row->user_id]->username, 'displayName' => $suggesters[$row->user_id]->display_name] : null,
                'content' => $row->content,
                'summary' => $row->summary,
                'approvals' => (int) $this->db->table('answers_review_votes')->where('review_id', $row->id)->count(),
                'canImprove' => $row->type === 'suggested_edit' && $this->access->canEditOthers($actor, $post->discussion),
            ];
        }

        return new JsonResponse(['counts' => $counts, 'items' => $items, 'needed' => $this->settings->int('review_approvals')]);
    }

    private function decide(ServerRequestInterface $request, User $actor): ResponseInterface
    {
        $item = $this->db->table('answers_reviews')->where('id', Input::id($request))->where('status', 'open')->first();
        $post = $item ? Post::query()->whereVisibleTo($actor)->find($item->post_id) : null;
        if (! $item || ! $post || ! Question::is($post->discussion)) {
            throw new ModelNotFoundException();
        }
        if (! $this->access->canReview($actor, $post->discussion) || $post->user_id === $actor->id || $item->user_id === $actor->id) {
            throw new PermissionDeniedException();
        }

        $body = Input::body($request);
        $verdict = (string) ($body['verdict'] ?? '');
        if (! in_array($verdict, Reviews::VERDICTS[$item->type], true)) {
            throw Fail::on('verdict', 'invalid');
        }
        $content = isset($body['content']) ? Input::text($body['content'], 63000) : null;
        if ($content !== null && ($content === '' || ! $this->access->canEditOthers($actor, $post->discussion))) {
            throw new PermissionDeniedException();
        }

        $status = $this->reviews->decide($item, $actor, $verdict, $this->access->moderates($actor, $post->discussion), $content);

        return new JsonResponse(['id' => (int) $item->id, 'status' => $status]);
    }
}
