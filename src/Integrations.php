<?php

namespace ErnestDefoe\Answers;

use Flarum\Extension\ExtensionManager;

/** Which optional extensions are switched on: each partner is used only while it is. */
class Integrations
{
    public function __construct(private readonly ExtensionManager $extensions)
    {
    }

    public function gamification(): bool
    {
        return $this->extensions->isEnabled('fof-gamification') && class_exists(\FoF\Gamification\Vote::class);
    }

    public function money(): bool
    {
        return $this->extensions->isEnabled('antoinefr-money');
    }

    public function followTags(): bool
    {
        return $this->extensions->isEnabled('fof-follow-tags');
    }

    public function bestAnswer(): bool
    {
        return $this->extensions->isEnabled('fof-best-answer');
    }
}
