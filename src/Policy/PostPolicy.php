<?php

namespace ErnestDefoe\Answers\Policy;

use ErnestDefoe\Answers\Access;
use ErnestDefoe\Answers\Question;
use Flarum\Post\Post;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

/** Trusted members may improve other people's answers (never comments, never the question). */
class PostPolicy extends AbstractPolicy
{
    public function __construct(private readonly Access $access)
    {
    }

    public function edit(User $actor, Post $post): ?string
    {
        if ($actor->isGuest() || $post->user_id === $actor->id || $post->hidden_at !== null || ! Question::isAnswer($post)) {
            return null;
        }

        return $this->access->canEditOthers($actor, $post->discussion) ? $this->allow() : null;
    }
}
