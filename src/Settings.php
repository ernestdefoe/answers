<?php

namespace ErnestDefoe\Answers;

use Flarum\Settings\SettingsRepositoryInterface;

/** Every setting, with its default (extend.php repeats them as string literals). */
final class Settings
{
    public const PREFIX = 'ernestdefoe-answers.';

    public const DEFAULTS = [
        'points_answer_up' => 10,
        'points_question_up' => 5,
        'points_accepted' => 15,
        'points_accepter' => 2,
        'points_down' => -2,
        'points_voter_down' => -1,
        'edit_reputation' => 1000,
        'review_reputation' => 500,
        'review_approvals' => 2,
        'close_votes' => 3,
        'low_quality_score' => -2,
        'low_quality_length' => 30,
        'comment_length' => 600,
        'bounty_currency' => 'both',
        'bounty_days' => 7,
        'bounty_min_age_days' => 2,
        'gamification' => true,
    ];

    /** What a bounty may be. */
    public const BOUNTY_AMOUNTS = [50, 100, 200, 500];

    public function __construct(private readonly SettingsRepositoryInterface $settings)
    {
    }

    public function int(string $key): int
    {
        $value = $this->settings->get(self::PREFIX.$key);

        return is_numeric($value) ? (int) $value : (int) self::DEFAULTS[$key];
    }

    public function bool(string $key): bool
    {
        $value = $this->settings->get(self::PREFIX.$key);

        return $value === null ? (bool) self::DEFAULTS[$key] : (bool) $value;
    }

    public function string(string $key): string
    {
        $value = $this->settings->get(self::PREFIX.$key);

        return is_string($value) && $value !== '' ? $value : (string) self::DEFAULTS[$key];
    }
}
