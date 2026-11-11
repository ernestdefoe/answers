<?php

/*
 * Answers: Stack Overflow-style questions and answers for any tag.
 *
 * A tag switched to Q&A makes its discussions questions: replies become
 * answers sorted by votes, the accepted one on top, each with its own thread
 * of comments (real posts with a parent). State, counts, score and bounty
 * are columns on the discussion (Question writes them, set-based), so lists
 * cost nothing; reputation is a per-tag log with summed totals (Reputation).
 * Access decides every right: tag-scoped permissions plus reputation in the
 * question's tag. Votes, Accept, Bounties, Duplicates, Revisions and
 * Reviews are the actions; Lifecycle keeps it all true as posts come and go.
 *
 * forum.js wires list badges, filters, routes and the thread swap; the
 * question page, modals, review queue and profile tab are a lazy chunk.
 *
 * String literals below, never class constants: extend.php runs at boot, and
 * a class lookup here fatals every request while composer swaps the autoload
 * map mid-install. (Inside closures is fine.)
 */

use ErnestDefoe\Answers\Api;
use ErnestDefoe\Answers\Console;
use ErnestDefoe\Answers\Forget;
use ErnestDefoe\Answers\Gdpr\AnswersData;
use ErnestDefoe\Answers\Listener\Lifecycle;
use ErnestDefoe\Answers\Notification;
use ErnestDefoe\Answers\Policy\PostPolicy;
use ErnestDefoe\Answers\Provider;
use ErnestDefoe\Answers\Question;
use ErnestDefoe\Answers\Revisions;
use ErnestDefoe\Answers\Search;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource;
use Flarum\Api\Schema;
use Flarum\Api\Sort\SortColumn;
use Flarum\Discussion\Discussion;
use Flarum\Discussion\Search\DiscussionSearcher;
use Flarum\Extend;
use Flarum\Gdpr\Extend\UserData;
use Flarum\Post\Event\Revised;
use Flarum\Post\Filter\PostSearcher;
use Flarum\Post\Post;
use Flarum\Search\Database\DatabaseSearchDriver;
use Flarum\Tags\Api\Resource\TagResource;
use Flarum\Tags\Tag;
use Flarum\User\Event\Deleting as UserDeleting;
use Flarum\User\User;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        // 🚨 Not optional: the question page and modals are chunks in here, and undeclared chunks 404.
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/less/forum.less')
        ->route('/answers', 'answers.unanswered')
        ->route('/answers/review', 'answers.review')
        ->route('/u/{username}/answers', 'user.answers'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\ServiceProvider())
        ->register(Provider::class),

    (new Extend\Routes('api'))
        ->post('/answers/posts/{id:\d+}/vote', 'answers.vote', Api\ActionController::class)
        ->post('/answers/posts/{id:\d+}/suggest', 'answers.suggest', Api\ActionController::class)
        ->get('/answers/posts/{id:\d+}/revisions', 'answers.revisions', Api\ReadController::class)
        ->post('/answers/posts/{id:\d+}/revisions/{revision:\d+}/restore', 'answers.restore', Api\ActionController::class)
        ->post('/answers/discussions/{id:\d+}/accept', 'answers.accept', Api\ActionController::class)
        ->post('/answers/discussions/{id:\d+}/bounty', 'answers.bounty', Api\ActionController::class)
        ->post('/answers/discussions/{id:\d+}/close', 'answers.close', Api\ActionController::class)
        ->delete('/answers/discussions/{id:\d+}/close', 'answers.reopen', Api\ActionController::class)
        ->get('/answers/similar', 'answers.similar', Api\ReadController::class)
        ->get('/answers/helpers', 'answers.helpers', Api\ReadController::class)
        ->get('/answers/users/{id:\d+}/reputation', 'answers.reputation', Api\ReadController::class)
        ->get('/answers/reviews', 'answers.reviews', Api\ReviewController::class)
        ->post('/answers/reviews/{id:\d+}', 'answers.reviews.decide', Api\ReviewController::class)
        ->post('/answers/import', 'answers.import', Api\AdminController::class),

    (new Extend\Model(Post::class))
        ->cast('answers_parent_id', 'integer')
        ->cast('answers_score', 'integer')
        ->cast('answers_revisions', 'integer')
        ->relationship('answersComments', fn (Post $post) => $post->hasMany(Post::class, 'answers_parent_id')->orderBy('created_at')),

    (new Extend\Model(Discussion::class))
        ->cast('answers_question', 'boolean')
        ->cast('answers_state', 'integer')
        ->cast('answers_count', 'integer')
        ->cast('answers_score', 'integer')
        ->cast('answers_bounty', 'integer')
        ->cast('answers_bounty_ends_at', 'datetime')
        ->cast('answers_accepted_post_id', 'integer')
        ->cast('answers_duplicate_of_id', 'integer')
        ->belongsTo('answersAcceptedPost', Post::class, 'answers_accepted_post_id')
        ->belongsTo('answersDuplicateOf', Discussion::class, 'answers_duplicate_of_id'),

    (new Extend\Model(Tag::class))
        ->cast('answers_enabled', 'boolean'),

    (new Extend\Model(User::class))
        ->cast('answers_accepted_count', 'integer')
        ->cast('answers_solved_count', 'integer'),

    (new Extend\ApiResource(Resource\PostResource::class))
        ->fields(Api\PostFields::class)
        ->sorts(fn () => [SortColumn::make('answersScore')])
        // Comments under answers read their discussion (core's own canEdit does): one scoped load for all of them.
        ->endpoint(Endpoint\Index::class, fn (Endpoint\Index $endpoint) => $endpoint
            ->eagerLoadWhenIncluded(['answersComments' => ['answersComments.discussion.tags']])),

    (new Extend\ApiResource(Resource\DiscussionResource::class))
        ->fields(Api\DiscussionFields::class)
        // The tags are read by the question flags and the viewer's rights on the page itself.
        ->endpoint(Endpoint\Show::class, fn (Endpoint\Show $endpoint) => $endpoint->eagerLoad(['tags'])),

    (new Extend\ApiResource(TagResource::class))
        ->fields(fn () => [
            Schema\Boolean::make('answersEnabled')
                ->property('answers_enabled')
                ->writable(fn (Tag $tag, Context $context) => $context->getActor()->isAdmin())
                ->set(function (Tag $tag, bool $value) {
                    if ($tag->answers_enabled !== $value) {
                        $tag->answers_enabled = $value;
                        $tag->afterSave(fn (Tag $tag) => resolve(Question::class)->refreshTag($tag->id));
                    }
                }),
        ]),

    (new Extend\ApiResource(Resource\UserResource::class))
        ->fields(fn () => [
            Schema\Integer::make('answersAcceptedCount')->property('answers_accepted_count'),
            Schema\Integer::make('answersSolvedCount')->property('answers_solved_count'),
        ]),

    (new Extend\ApiResource(Resource\ForumResource::class))
        ->fields(Api\ForumFields::class),

    (new Extend\SearchDriver(DatabaseSearchDriver::class))
        ->addFilter(DiscussionSearcher::class, Search\AnswersFilter::class)
        ->addFilter(DiscussionSearcher::class, Search\DuplicateOfFilter::class)
        ->addFilter(PostSearcher::class, Search\AnswerPostsFilter::class),

    (new Extend\Policy())
        ->modelPolicy(Post::class, PostPolicy::class),

    (new Extend\Event())
        ->subscribe(Lifecycle::class)
        ->listen(Revised::class, [Revisions::class, 'handle'])
        // Before the user row goes: the votes cascade away with it, but not the scores they moved.
        ->listen(UserDeleting::class, fn (UserDeleting $event) => resolve(Forget::class)->user($event->user)),

    (new Extend\Notification())
        ->type(Notification\AnswerAcceptedBlueprint::class, ['alert'])
        ->type(Notification\BountyAwardedBlueprint::class, ['alert']),

    (new Extend\Console())
        ->command(Console\BountiesCommand::class)
        ->command(Console\ImportCommand::class)
        ->command(Console\RecountCommand::class)
        ->schedule(Console\BountiesCommand::class, function (ScheduledEvent $event): void {
            $event->everyTenMinutes()->withoutOverlapping();
        }),

    (new Extend\Settings())
        ->default('ernestdefoe-answers.points_answer_up', 10)
        ->default('ernestdefoe-answers.points_question_up', 5)
        ->default('ernestdefoe-answers.points_accepted', 15)
        ->default('ernestdefoe-answers.points_accepter', 2)
        ->default('ernestdefoe-answers.points_down', -2)
        ->default('ernestdefoe-answers.points_voter_down', -1)
        ->default('ernestdefoe-answers.edit_reputation', 1000)
        ->default('ernestdefoe-answers.review_reputation', 500)
        ->default('ernestdefoe-answers.review_approvals', 2)
        ->default('ernestdefoe-answers.close_votes', 3)
        ->default('ernestdefoe-answers.low_quality_score', -2)
        ->default('ernestdefoe-answers.low_quality_length', 30)
        ->default('ernestdefoe-answers.comment_length', 600)
        ->default('ernestdefoe-answers.bounty_currency', 'both')
        ->default('ernestdefoe-answers.bounty_days', 7)
        ->default('ernestdefoe-answers.bounty_min_age_days', 2)
        ->default('ernestdefoe-answers.gamification', true),

    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-gdpr', fn () => [
            (new UserData())->addType(AnswersData::class),
        ])
        ->whenExtensionEnabled('flarum-approval', fn () => [
            (new Extend\Event())->listen('Flarum\Approval\Event\PostWasApproved', fn ($event) => resolve(Lifecycle::class)->approved($event->post)),
        ]),
];
