<?php

namespace ErnestDefoe\Answers\Event;

use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\User\User;

/** Public: other extensions (Kudos, Cadence, Buzz, Vantage…) listen for it. */
class BountyStarted
{
    public function __construct(public Discussion $discussion, public User $actor, public int $amount, public string $currency)
    {
    }
}
