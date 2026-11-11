<?php

namespace ErnestDefoe\Answers;

use Flarum\Foundation\ValidationException;
use Symfony\Contracts\Translation\TranslatorInterface;

/** A refusal the member can read: Flarum shows a validation message as it is, so it is a sentence, not a code. */
final class Fail
{
    public static function on(string $field, string $code): ValidationException
    {
        return new ValidationException([$field => resolve(TranslatorInterface::class)->trans('ernestdefoe-answers.api.'.$code)]);
    }
}
