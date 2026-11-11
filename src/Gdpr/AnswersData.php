<?php

namespace ErnestDefoe\Answers\Gdpr;

use Flarum\Gdpr\Data\Type;
use Illuminate\Database\ConnectionInterface;

/**
 * A member's votes and reputation in a data export. On erasure the user's
 * Deleting event takes their votes off every score; the rows go with the user.
 * Registered only when flarum/gdpr is enabled.
 */
class AnswersData extends Type
{
    public static function exportDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-answers.lib.gdpr.export');
    }

    public static function anonymizeDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-answers.lib.gdpr.anonymize');
    }

    public static function deleteDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-answers.lib.gdpr.delete');
    }

    public function export(): ?array
    {
        $db = resolve(ConnectionInterface::class);

        return [
            ['answers/votes.json' => $this->encodeForExport($db->table('answers_votes')->where('user_id', $this->user->id)->orderBy('id')->get(['post_id', 'value', 'created_at'])->all())],
            ['answers/reputation.json' => $this->encodeForExport($db->table('answers_reputation')->where('user_id', $this->user->id)->get(['tag_id', 'points'])->all())],
            ['answers/reputation-history.json' => $this->encodeForExport($db->table('answers_reputation_log')->where('user_id', $this->user->id)->orderBy('id')->get(['tag_id', 'discussion_id', 'post_id', 'reason', 'points', 'created_at'])->all())],
        ];
    }

    /** An anonymised account keeps its votes and reputation, now under no name. */
    public function anonymize(): void
    {
    }

    /** Erasure deletes the user, and the user's Deleting event runs Forget (once). */
    public function delete(): void
    {
    }
}
