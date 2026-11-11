<?php

namespace ErnestDefoe\Answers\Notification;

use Flarum\Database\AbstractModel;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Post\Post;
use Flarum\User\User;

/** "Sam accepted your answer", to the answer's author. Subject: the answer. */
class AnswerAcceptedBlueprint implements BlueprintInterface, AlertableInterface
{
    public function __construct(public Post $post, public User $actor)
    {
    }

    public function getFromUser(): ?User
    {
        return $this->actor;
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->post;
    }

    public function getData(): mixed
    {
        return null;
    }

    public static function getType(): string
    {
        return 'answersAccepted';
    }

    public static function getSubjectModel(): string
    {
        return Post::class;
    }
}
