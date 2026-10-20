<?php

namespace ErnestDefoe\Onramp\Notification;

use Flarum\Database\AbstractModel;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\MailableInterface;
use Flarum\User\User;

/**
 * "You are N steps from the end": sent on the reminder days the admin chose,
 * alert and email, each switchable off by the member like any other.
 *
 * 🚨 `day` is in the data so each reminder is a NEW notification. Without it,
 * Flarum's syncer sees the same blueprint as the day-3 one and quietly
 * restores that old alert instead of sending.
 */
class ReminderBlueprint implements AlertableInterface, BlueprintInterface, MailableInterface
{
    public function __construct(public User $user, public int $day, public int $left, public string $next)
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
        return ['day' => $this->day, 'left' => $this->left];
    }

    public function getEmailViews(): array
    {
        return ['text' => 'ernestdefoe-onramp::emails.plain.reminder', 'html' => 'ernestdefoe-onramp::emails.html.reminder'];
    }

    public function getEmailSubject(TranslatorInterface $translator): string
    {
        return $translator->trans('ernestdefoe-onramp.email.reminder.subject', ['count' => $this->left]);
    }

    public static function getType(): string
    {
        return 'onrampReminder';
    }

    public static function getSubjectModel(): string
    {
        return User::class;
    }
}
