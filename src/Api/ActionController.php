<?php

namespace ErnestDefoe\Answers\Api;

use ErnestDefoe\Answers\Accept;
use ErnestDefoe\Answers\Access;
use ErnestDefoe\Answers\Bounties;
use ErnestDefoe\Answers\Duplicates;
use ErnestDefoe\Answers\Question;
use ErnestDefoe\Answers\Reviews;
use ErnestDefoe\Answers\Revisions;
use ErnestDefoe\Answers\Settings;
use ErnestDefoe\Answers\Votes;
use Flarum\Discussion\Discussion;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Post\CommentPost;
use Flarum\Post\Post;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The actions on a question and its posts, one route each (the route name
 * picks the method): vote, accept, start a bounty, vote to close / reopen,
 * suggest an edit, roll back to a revision. Every one checks Access first.
 */
class ActionController implements RequestHandlerInterface
{
    public function __construct(
        private readonly Access $access,
        private readonly Votes $votes,
        private readonly Accept $accept,
        private readonly Bounties $bounties,
        private readonly Duplicates $duplicates,
        private readonly Reviews $reviews,
        private readonly Revisions $revisions,
        private readonly Settings $settings,
        private readonly RateLimit $limits,
        private readonly ConnectionInterface $db
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        return match ($request->getAttribute('routeName')) {
            'answers.vote' => $this->vote($request),
            'answers.accept' => $this->acceptAnswer($request),
            'answers.bounty' => $this->bounty($request),
            'answers.close' => $this->close($request),
            'answers.reopen' => $this->reopen($request),
            'answers.suggest' => $this->suggest($request),
            'answers.restore' => $this->restore($request),
            default => throw new ModelNotFoundException(),
        };
    }

    private function vote(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $post = Input::post($request, $actor);
        if (! $this->access->canVote($actor, $post)) {
            throw new PermissionDeniedException();
        }
        $this->limits->check($actor, 'vote', 60);

        $result = $this->votes->cast($post, $actor, (int) (Input::body($request)['value'] ?? 0));

        return new JsonResponse(['id' => $post->id] + $result);
    }

    private function acceptAnswer(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $discussion = Input::question($request, $actor);
        if (! $this->access->canAccept($actor, $discussion)) {
            throw new PermissionDeniedException();
        }

        $postId = Input::body($request)['postId'] ?? null;
        $post = null;
        if ($postId) {
            $post = Post::query()->whereVisibleTo($actor)->where('discussion_id', $discussion->id)->find((int) $postId);
            if (! $post) {
                throw new ModelNotFoundException();
            }
            $post->setRelation('discussion', $discussion);
            if (! Question::isAnswer($post) || $post->hidden_at !== null) {
                throw new ValidationException(['postId' => 'not_an_answer']);
            }
        }

        $this->accept->set($discussion, $post, $actor);

        return new JsonResponse(['id' => $discussion->id, 'acceptedPostId' => $discussion->answers_accepted_post_id, 'state' => (int) $discussion->answers_state]);
    }

    private function bounty(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $discussion = Input::question($request, $actor);
        if ($refusal = $this->access->bountyRefusal($actor, $discussion)) {
            throw $refusal === 'permission' ? new PermissionDeniedException() : new ValidationException(['bounty' => $refusal]);
        }
        $body = Input::body($request);
        $reason = in_array($body['reason'] ?? '', ['attention', 'current', 'reward'], true) ? $body['reason'] : 'attention';

        $this->bounties->start($discussion, $actor, (int) ($body['amount'] ?? 0), (string) ($body['currency'] ?? 'rep'), $reason);

        return new JsonResponse(['id' => $discussion->id, 'bounty' => (int) $discussion->answers_bounty]);
    }

    private function close(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $discussion = Input::question($request, $actor);
        $moderator = $this->access->moderates($actor, $discussion);
        if ($discussion->answers_duplicate_of_id || (! $moderator && ! $this->access->canVoteToClose($actor, $discussion))) {
            throw new PermissionDeniedException();
        }
        $this->limits->check($actor, 'close', 20);

        $original = Discussion::query()->whereVisibleTo($actor)->find((int) (Input::body($request)['duplicateOf'] ?? 0));
        if (! $original) {
            throw new ValidationException(['duplicateOf' => 'not_found']);
        }

        $votes = $this->duplicates->vote($discussion, $original, $actor, $moderator);

        return new JsonResponse(['id' => $discussion->id, 'duplicateOfId' => $discussion->answers_duplicate_of_id, 'votes' => $votes, 'needed' => $this->settings->int('close_votes')]);
    }

    private function reopen(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $discussion = Input::question($request, $actor);
        if (! $discussion->answers_duplicate_of_id || ! $this->access->moderates($actor, $discussion)) {
            throw new PermissionDeniedException();
        }
        $this->duplicates->reopen($discussion);

        return new JsonResponse(['id' => $discussion->id, 'state' => (int) $this->db->table('discussions')->where('id', $discussion->id)->value('answers_state')]);
    }

    private function suggest(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $post = Input::post($request, $actor);
        if (! Question::isAnswer($post) || ! $this->access->canSuggest($actor, $post)) {
            throw new PermissionDeniedException();
        }
        $this->limits->check($actor, 'suggest', 10);

        $body = Input::body($request);
        $content = Input::text($body['content'] ?? '', 63000);
        if ($content === '' || $content === trim((string) $post->content)) {
            throw new ValidationException(['content' => 'unchanged']);
        }
        $id = $this->reviews->open('suggested_edit', $post, $actor->id, $content, Input::text($body['summary'] ?? '', 200) ?: null);
        if (! $id) {
            throw new ValidationException(['content' => 'already_suggested']);
        }

        return new JsonResponse(['id' => $id], 201);
    }

    private function restore(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $post = Input::post($request, $actor);
        if (! $post instanceof CommentPost || ! $actor->can('edit', $post)) {
            throw new PermissionDeniedException();
        }
        $revision = $this->db->table('answers_revisions')->where('post_id', $post->id)->where('id', Input::id($request, 'revision'))->first();
        if (! $revision) {
            throw new ModelNotFoundException();
        }
        $number = $this->db->table('answers_revisions')->where('post_id', $post->id)->where('id', '<=', $revision->id)->count();

        $this->revisions->edit($post, (string) $revision->content, $actor, 'rollback:'.$number);

        return new JsonResponse(['id' => $post->id, 'revisions' => (int) $post->answers_revisions]);
    }
}
