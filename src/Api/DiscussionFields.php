<?php

namespace ErnestDefoe\Answers\Api;

use ErnestDefoe\Answers\Access;
use ErnestDefoe\Answers\Bounties;
use ErnestDefoe\Answers\Question;
use Flarum\Api\Context;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Schema;
use Flarum\Discussion\Discussion;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * A question's state, counts and bounty: columns, so a list of 20 costs
 * nothing extra. What the viewer may do is worked out on the discussion's
 * own page only (it can read reputation).
 */
class DiscussionFields
{
    public function __construct(private readonly Access $access, private readonly Bounties $bounties, private readonly ConnectionInterface $db)
    {
    }

    public function __invoke(): array
    {
        $question = fn (Discussion $d) => Question::is($d);
        $page = fn (Discussion $d, Context $context) => Question::is($d) && $context->showing(DiscussionResource::class);

        return [
            Schema\Boolean::make('answersQuestion')->property('answers_question')->visible($question),
            Schema\Integer::make('answersState')->property('answers_state')->visible($question),
            Schema\Integer::make('answersCount')->property('answers_count')->visible($question),
            Schema\Integer::make('answersScore')->property('answers_score')->visible($question),
            Schema\Integer::make('answersBounty')->property('answers_bounty')->visible(fn (Discussion $d) => $d->answers_bounty > 0),
            Schema\Integer::make('answersAcceptedPostId')->property('answers_accepted_post_id')->visible($question),
            Schema\Integer::make('answersDuplicateOfId')->property('answers_duplicate_of_id')->visible(fn (Discussion $d) => $d->answers_duplicate_of_id !== null),
            Schema\Integer::make('answersFirstPostId')->property('first_post_id')->visible($question),

            Schema\Attribute::make('answersBountyInfo')
                ->visible(fn (Discussion $d, Context $context) => $d->answers_bounty > 0 && $page($d, $context))
                ->get(function (Discussion $d) {
                    $bounty = $this->bounties->open($d->id);
                    if (! $bounty) {
                        return null;
                    }

                    return [
                        'amount' => (int) $bounty->amount,
                        'currency' => $bounty->currency,
                        'reason' => $bounty->reason,
                        'endsAt' => (new \DateTime($bounty->ends_at))->format(\DateTime::RFC3339),
                        'startedBy' => $bounty->user_id ? User::query()->find($bounty->user_id)?->display_name : null,
                    ];
                }),

            Schema\Boolean::make('canAnswersAccept')->visible($page)
                ->get(fn (Discussion $d, Context $context) => $this->access->canAccept($context->getActor(), $d)),
            Schema\Boolean::make('canAnswersEditOthers')->visible($page)
                ->get(fn (Discussion $d, Context $context) => $this->access->canEditOthers($context->getActor(), $d)),
            Schema\Boolean::make('canAnswersClose')->visible($page)
                ->get(fn (Discussion $d, Context $context) => $this->access->canVoteToClose($context->getActor(), $d) || ($this->access->moderates($context->getActor(), $d) && ! $d->answers_duplicate_of_id)),
            Schema\Boolean::make('canAnswersModerate')->visible($page)
                ->get(fn (Discussion $d, Context $context) => $this->access->moderates($context->getActor(), $d)),
            Schema\Boolean::make('canAnswersReopen')->visible($page)
                ->get(fn (Discussion $d, Context $context) => $d->answers_duplicate_of_id !== null && $this->access->moderates($context->getActor(), $d)),
            Schema\Attribute::make('answersBountyRefusal')->visible($page)
                ->get(fn (Discussion $d, Context $context) => $this->access->bountyRefusal($context->getActor(), $d)),

            Schema\Relationship\ToOne::make('answersAcceptedPost')->type('posts')->includable()->visible($question),
            Schema\Relationship\ToOne::make('answersDuplicateOf')->type('discussions')->includable()->visible(fn (Discussion $d) => $d->answers_duplicate_of_id !== null),
        ];
    }
}
