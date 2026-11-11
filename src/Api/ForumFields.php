<?php

namespace ErnestDefoe\Answers\Api;

use ErnestDefoe\Answers\Bounties;
use ErnestDefoe\Answers\Import;
use ErnestDefoe\Answers\Integrations;
use ErnestDefoe\Answers\Reputation;
use ErnestDefoe\Answers\Settings;
use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * What every page needs: whether the viewer reviews (and how many items wait,
 * one count query for reviewers only), the bounty options and a couple of
 * limits. Admins also learn whether there's a Best Answer import to offer.
 */
class ForumFields
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Reputation $reputation,
        private readonly Settings $settings,
        private readonly Bounties $bounties,
        private readonly Integrations $integrations,
        private readonly Import $import,
        private readonly SettingsRepositoryInterface $raw
    ) {
    }

    public function __invoke(): array
    {
        return [
            Schema\Integer::make('answersReviewCount')
                ->visible(fn ($forum, Context $context) => $context->showing() && $this->mayReview($context))
                ->get(fn ($forum, Context $context) => (int) ReviewController::queue($this->db, $context->getActor(), $this->reputation, $this->settings)?->count()),
            Schema\Attribute::make('answersBounty')
                ->get(fn () => [
                    'amounts' => Settings::BOUNTY_AMOUNTS,
                    'currencies' => $this->bounties->currencies(),
                    'days' => $this->settings->int('bounty_days'),
                    'moneyName' => $this->integrations->money() ? (string) $this->raw->get('antoinefr-money.moneyname', '[money]') : null,
                ]),
            Schema\Integer::make('answersCommentLength')->get(fn () => $this->settings->int('comment_length')),
            Schema\Boolean::make('answersBestAnswerImport')
                ->visible(fn ($forum, Context $context) => $context->getActor()->isAdmin())
                ->get(fn () => $this->import->available()),
        ];
    }

    private function mayReview(Context $context): bool
    {
        $actor = $context->getActor();

        return ! $actor->isGuest() && ($actor->hasPermissionLike('answersReview') || $actor->hasPermissionLike('answersModerate'));
    }
}
