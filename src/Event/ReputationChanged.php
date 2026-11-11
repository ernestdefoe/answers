<?php

namespace ErnestDefoe\Answers\Event;

use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\User\User;

/** Public: other extensions (Kudos, Cadence, Buzz, Vantage…) listen for it. */
class ReputationChanged
{
    public function __construct(public int $userId, public int $tagId, public int $points, public string $reason)
    {
    }
}
