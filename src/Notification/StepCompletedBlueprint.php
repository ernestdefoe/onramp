<?php

namespace ErnestDefoe\Onramp\Notification;

use ErnestDefoe\Onramp\Step;
use Flarum\Database\AbstractModel;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

/**
 * A step completed by something somebody ELSE did — a like received, an
 * answer marked best. Steps a member completes themselves are told by a toast
 * in the page they are on, never by an alert.
 */
class StepCompletedBlueprint implements AlertableInterface, BlueprintInterface
{
    public function __construct(public User $user, public Step $step)
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
        return ['step' => $this->step->id];
    }

    public static function getType(): string
    {
        return 'onrampStepCompleted';
    }

    public static function getSubjectModel(): string
    {
        return User::class;
    }
}
