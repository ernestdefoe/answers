<?php

namespace ErnestDefoe\Answers\Notification;

use Flarum\Database\AbstractModel;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Post\Post;
use Flarum\User\User;

/** "Your answer won a 100 reputation bounty", to the answer's author. Subject: the answer. */
class BountyAwardedBlueprint implements BlueprintInterface, AlertableInterface
{
    public function __construct(public Post $post, public int $amount, public string $currency)
    {
    }

    public function getFromUser(): ?User
    {
        return null;
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->post;
    }

    public function getData(): mixed
    {
        return ['amount' => $this->amount, 'currency' => $this->currency];
    }

    public static function getType(): string
    {
        return 'answersBounty';
    }

    public static function getSubjectModel(): string
    {
        return Post::class;
    }
}
