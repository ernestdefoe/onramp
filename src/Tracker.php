<?php

namespace ErnestDefoe\Onramp;

use Flarum\User\User;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Collects "look again at this member's X steps" during a request and
 * evaluates them once, at the end.
 *
 * 🚨 Why at the end and not in the event listener:
 *
 * - ORDER. Mentions are synced, a Cadence streak is fed and an auto-follow is
 *   written by other listeners on the same events. Counted inside the event,
 *   whether Onramp saw them would depend on which extension booted first.
 * - ONCE. One reply fires Posted for reply, mention, follow and streak steps;
 *   deferred, that is one look at the member, not four.
 * - SAFETY. The member's post is already saved when this runs. Anything that
 *   goes wrong in Onramp is logged and swallowed — it must never turn a
 *   successful reply into an error page.
 *
 * Outside an HTTP request (a queue job, the console) there is no end to wait
 * for, so a touch is evaluated straight away.
 */
class Tracker
{
    private bool $deferring = false;

    private ?int $actorId = null;

    /** @var array<int, array<string, true>> user id => type keys */
    private array $pending = [];

    public function __construct(private Journeys $journeys, private Config $config, private LoggerInterface $log)
    {
    }

    public function begin(?User $actor): void
    {
        $this->deferring = true;
        $this->actorId = $actor && ! $actor->isGuest() ? $actor->id : null;
        $this->pending = [];
    }

    public function touch(int $userId, string $type): void
    {
        $this->pending[$userId][$type] = true;

        if (! $this->deferring) {
            $this->flush();
        }
    }

    /**
     * Evaluate what was collected.
     *
     * @return array{completed: list<Step>, graduated: bool}|null what the
     *         acting member completed, for the toast — null if nothing
     */
    public function flush(): ?array
    {
        $pending = $this->pending;
        $this->pending = [];
        $mine = null;

        if (! $pending || ! $this->config->enabled()) {
            return null;
        }

        try {
            // One query for everyone touched; most have no open journey and
            // cost nothing further.
            $users = User::query()->whereIn('id', array_keys($pending))->where('onramp_open', true)->get();

            foreach ($users as $user) {
                $watching = $this->deferring && $user->id === $this->actorId;
                $result = $this->journeys->evaluate($user, array_keys($pending[$user->id]), $watching ? Journeys::WATCHING : Journeys::AWAY);

                if ($watching && ($result['completed'] || $result['graduated'])) {
                    $mine = $result;
                }
            }
        } catch (Throwable $e) {
            $this->log->error('[onramp] evaluating steps failed: '.$e->getMessage(), ['exception' => $e]);
        }

        return $mine;
    }

    public function end(): void
    {
        $this->deferring = false;
        $this->actorId = null;
        $this->pending = [];
    }
}
