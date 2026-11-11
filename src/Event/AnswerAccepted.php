<?php

namespace ErnestDefoe\Answers\Event;

use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\User\User;

/** Public: other extensions (Kudos, Cadence, Buzz, Vantage…) listen for it. */
class AnswerAccepted
{
    public function __construct(public Discussion $discussion, public Post $post, public User $actor)
    {
    }
}
