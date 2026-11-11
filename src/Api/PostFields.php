<?php

namespace ErnestDefoe\Answers\Api;

use ErnestDefoe\Answers\Access;
use ErnestDefoe\Answers\Question;
use Flarum\Api\Context;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Schema;
use Flarum\Post\Post;

/** The answer side of a post. Only posts in a question carry any of it. */
class PostFields
{
    public function __construct(private readonly Access $access, private readonly Batch $batch)
    {
    }

    public function __invoke(): array
    {
        $inQuestion = fn (Post $post) => Question::is($post->discussion);
        // A discussion list includes first posts, but shows neither the viewer's vote nor the author's reputation.
        $onThread = fn (Post $post, Context $context) => ! $context->listing(DiscussionResource::class) && Question::is($post->discussion);
        $isAnswer = fn (Post $post) => Question::isAnswer($post);

        return [
            // Writable once, when a comment is posted under an answer; Lifecycle::saving checks it.
            Schema\Integer::make('answersParentId')
                ->property('answers_parent_id')
                ->nullable()
                ->writableOnCreate()
                ->visible(fn (Post $post) => $post->answers_parent_id !== null),
            Schema\Integer::make('answersScore')
                ->property('answers_score')
                ->visible($inQuestion),
            Schema\Integer::make('answersRevisions')
                ->property('answers_revisions')
                ->visible(fn (Post $post) => $post->answers_revisions > 0),
            Schema\Integer::make('answersVote')
                ->visible(fn (Post $post, Context $context) => ! $context->getActor()->isGuest() && $onThread($post, $context))
                ->get(function (Post $post, Context $context) {
                    $this->batch->queueVote($context->request, $post->id);

                    return fn () => $this->batch->vote($context->request, $context->getActor(), $post->id);
                }),
            Schema\Integer::make('answersAuthorRep')
                ->visible(fn (Post $post, Context $context) => $post->user_id !== null && $onThread($post, $context))
                ->get(function (Post $post, Context $context) {
                    $tags = Question::tagIds($post->discussion);
                    $this->batch->queueRep($context->request, $post->user_id, $tags);

                    return fn () => $this->batch->rep($context->request, $post->user_id, $tags);
                }),
            Schema\Boolean::make('canAnswersHistory')
                ->visible(fn (Post $post) => $post->answers_revisions > 0)
                ->get(fn (Post $post, Context $context) => $this->access->canSeeHistory($context->getActor(), $post)),
            Schema\Boolean::make('canAnswersVote')
                ->visible($inQuestion)
                ->get(fn (Post $post, Context $context) => $this->access->canVote($context->getActor(), $post)),
            Schema\Boolean::make('canAnswersSuggest')
                ->visible($isAnswer)
                ->get(fn (Post $post, Context $context) => $this->access->canSuggest($context->getActor(), $post)),
            Schema\Relationship\ToMany::make('answersComments')
                ->type('posts')
                ->includable()
                ->visible($inQuestion),
        ];
    }
}
