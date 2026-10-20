<?php

/*
 * Onramp — onboarding quests that turn new sign-ups into regulars.
 *
 * The shape of the extension: events say "look again at this member", the
 * tracker looks once at the end of the request, a step is counted from the
 * forum's own records, and the browser hears about it in the same response.
 */

use ErnestDefoe\Onramp\Api;
use ErnestDefoe\Onramp\Config;
use ErnestDefoe\Onramp\Console;
use ErnestDefoe\Onramp\Http\EvaluateAfterRequest;
use ErnestDefoe\Onramp\Integration\Badges\BadgesProvider;
use ErnestDefoe\Onramp\Listener;
use ErnestDefoe\Onramp\Notification;
use ErnestDefoe\Onramp\OnrampServiceProvider;
use ErnestDefoe\Onramp\Presenter;
use Flarum\Api\Context;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Resource\UserResource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Flarum\User\Event\Registered;
use Flarum\User\User;
use Illuminate\Console\Scheduling\Event;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        // 🚨 The lazy pages' chunks. Without this they are never published
        // and /onramp is a ChunkLoadError — while forum.js loads fine.
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/less/forum.less')
        // A pasted /onramp link loads. The staff tab on a profile rides on
        // core's own /u/{username}/{filter} route.
        ->route('/onramp', 'onramp'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\View())->namespace('ernestdefoe-onramp', __DIR__.'/views'),

    (new Extend\ServiceProvider())
        ->register(OnrampServiceProvider::class),

    (new Extend\Event())
        ->subscribe(Listener\TriggerSubscriber::class)
        ->listen(Registered::class, Listener\StartOnRegister::class),

    // After auth, so the request knows its actor; around the handler, so the
    // steps a request touched are looked at once it is done.
    (new Extend\Middleware('api'))->add(EvaluateAfterRequest::class),
    (new Extend\Middleware('forum'))->add(EvaluateAfterRequest::class),

    /*
     * 🚨 Static routes before `{id}` ones: FastRoute refuses a static route an
     * earlier variable route already matches, and the refusal is a 500 on
     * every API request while the route table is built.
     */
    (new Extend\Routes('api'))
        ->get('/onramp', 'onramp.journey', Api\JourneyController::class)
        ->post('/onramp/{action:skip|unskip|visit|close|reopen}', 'onramp.action', Api\StepActionController::class)
        ->get('/onramp/members/{id:[0-9]+}', 'onramp.member', Api\MemberController::class)
        ->post('/onramp/members/{id:[0-9]+}', 'onramp.member.manage', Api\MemberController::class)
        ->get('/onramp-admin/steps', 'onramp.admin.steps', Api\AdminStepsController::class)
        ->post('/onramp-admin/steps', 'onramp.admin.steps.create', Api\AdminStepsController::class)
        ->post('/onramp-admin/{what:order|import}', 'onramp.admin.steps.bulk', Api\AdminStepsController::class)
        ->patch('/onramp-admin/steps/{id:[0-9]+}', 'onramp.admin.steps.update', Api\AdminStepsController::class)
        ->delete('/onramp-admin/steps/{id:[0-9]+}', 'onramp.admin.steps.delete', Api\AdminStepsController::class)
        ->get('/onramp-admin/funnel', 'onramp.admin.funnel', Api\FunnelController::class)
        ->post('/onramp-admin/start-existing', 'onramp.admin.start', Api\StartExistingController::class),

    (new Extend\Notification())
        ->type(Notification\StepCompletedBlueprint::class, ['alert'])
        ->type(Notification\GraduatedBlueprint::class, ['alert'])
        ->type(Notification\ReminderBlueprint::class, ['alert', 'email']),

    (new Extend\Console())
        ->command(Console\StartCommand::class)
        ->command(Console\RemindCommand::class)
        ->schedule(Console\RemindCommand::class, function (Event $event): void {
            $event->hourly()->withoutOverlapping();
        }),

    (new Extend\Settings())
        ->default(Config::PREFIX.'enabled', Config::DEFAULTS['enabled'])
        ->default(Config::PREFIX.'launcher', Config::DEFAULTS['launcher'])
        ->default(Config::PREFIX.'reward_points', Config::DEFAULTS['reward_points'])
        ->default(Config::PREFIX.'reward_badge', Config::DEFAULTS['reward_badge'])
        ->default(Config::PREFIX.'reward_group', Config::DEFAULTS['reward_group'])
        ->default(Config::PREFIX.'reminders', Config::DEFAULTS['reminders'])
        ->default(Config::PREFIX.'reminder_days', Config::DEFAULTS['reminder_days'])
        ->default(Config::PREFIX.'close_after', Config::DEFAULTS['close_after']),

    (new Extend\Model(User::class))
        ->cast('onramp_open', 'boolean'),

    /*
     * 🚨 The member's own journey rides on their own user, which every page
     * already loads — and only while it is open. A graduate, an old-timer
     * who never had one, and every guest cost nothing: `onramp_open` is a
     * column on the row already in memory, checked before any query.
     */
    (new Extend\ApiResource(UserResource::class))
        ->fields(fn () => [
            Schema\Arr::make('onramp')
                ->visible(fn (User $user, Context $context) => $context->getActor()->id === $user->id && resolve(Config::class)->enabled())
                ->get(fn (User $user) => $user->onramp_open ? resolve(Presenter::class)->summary($user) : null),
        ]),

    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Boolean::make('canViewOnrampMembers')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('onramp.viewMembers')),
        ]),

    (new Extend\Conditional())
        ->whenExtensionEnabled('fof-badges', fn () => [
            (new Extend\ServiceProvider())->register(BadgesProvider::class),
        ]),
];
