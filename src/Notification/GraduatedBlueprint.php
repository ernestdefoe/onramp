<?php

namespace ErnestDefoe\Onramp\Notification;

use Flarum\Database\AbstractModel;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

/** "You finished your journey." */
class GraduatedBlueprint implements AlertableInterface, BlueprintInterface
{
    public function __construct(public User $user)
    {
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->user;
    }

    public function getFromUser(): ?User
    {
        return null;
    }

    public function getData(): mixed
    {
        return null;
    }

    public static function getType(): string
    {
        return 'onrampGraduated';
    }

    public static function getSubjectModel(): string
    {
        return User::class;
    }
}
