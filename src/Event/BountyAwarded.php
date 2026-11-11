<?php

namespace ErnestDefoe\Answers\Event;

use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\User\User;

/** Public: other extensions (Kudos, Cadence, Buzz, Vantage…) listen for it. */
class BountyAwarded
{
    public function __construct(public Post $post, public int $amount, public string $currency)
    {
    }
}
