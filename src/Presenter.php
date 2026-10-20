<?php

namespace ErnestDefoe\Onramp;

use ErnestDefoe\Onramp\StepType\Registry;
use Flarum\Extension\ExtensionManager;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

/**
 * A member's journey, shaped for their browser.
 *
 * `summary()` rides on the member's own user in every payload and is built
 * from stored state only (three small queries, and only while their journey
 * is open). `full()` adds each pending step's live count — "2 of 3 read" —
 * and is asked for only when the member opens Onramp.
 */
class Presenter
{
    public function __construct(
        private Journeys $journeys,
        private Registry $types,
        private Rewards $rewards,
        private Config $config,
        private ConnectionInterface $db,
        private ExtensionManager $extensions,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function summary(User $user): ?array
    {
        return $this->present($user, false);
    }

    /** @return array<string, mixed>|null */
    public function full(User $user): ?array
    {
        return $this->present($user, true);
    }

    /** @return array<string, mixed>|null */
    private function present(User $user, bool $live): ?array
    {
        if ($user->isGuest() || ! ($journey = $this->journeys->journey($user))) {
            return null;
        }

        $steps = $this->journeys->steps();
        $rows = $this->db->table('onramp_progress')
            ->where('user_id', $user->id)
            ->get(['step_id', 'completed_at', 'skipped_at'])
            ->keyBy('step_id');

        $badges = $this->badges($steps->pluck('badge_id')->push($this->config->rewardBadge())->filter()->unique()->values()->all());
        $tags = $this->tags($user, $steps->map(fn (Step $s) => (int) ($s->settings()['tag'] ?? 0))->filter()->unique()->values()->all());
        $points = $this->rewards->pointsAvailable();

        $views = $steps->map(function (Step $step) use ($user, $rows, $badges, $tags, $points, $live, $journey) {
            $type = $this->types->get($step->type);
            $config = $step->settings();
            $row = $rows->get($step->id);
            $tag = (int) ($config['tag'] ?? 0);

            $view = [
                'id' => $step->id,
                'type' => $step->type,
                'title' => $step->title,
                'description' => $step->description,
                'icon' => $step->icon ?: $type?->icon(),
                'buttonLabel' => $step->button_label,
                'buttonUrl' => $step->button_url,
                'required' => $step->is_required,
                'points' => $points ? $step->points : 0,
                'badge' => $badges[$step->badge_id] ?? null,
                'target' => $type?->target($config) ?? 1,
                'tag' => $tags[$tag] ?? null,
                'path' => $config['path'] ?? null,
                'completedAt' => $row?->completed_at ? $this->iso($row->completed_at) : null,
                'skippedAt' => $row && ! $row->completed_at && $row->skipped_at ? $this->iso($row->skipped_at) : null,
            ];

            if ($live && ! $view['completedAt'] && $type && $journey->isOpen()) {
                $view['progress'] = $type->reportedByClient() ? 0 : min($view['target'], $type->progress($user, $config));
            }

            return $view;
        })->values();

        $done = $views->whereNotNull('completedAt')->count();
        $skipped = $views->whereNotNull('skippedAt')->count();

        return [
            'status' => $journey->status(),
            'startedAt' => $this->iso($journey->started_at),
            'completedAt' => $journey->completed_at ? $this->iso($journey->completed_at) : null,
            'done' => $done,
            'skipped' => $skipped,
            'total' => $views->count(),
            'launcher' => $this->config->launcher(),
            'steps' => $views->all(),
            'reward' => [
                'points' => $points ? $this->config->rewardPoints() : 0,
                'badge' => $badges[$this->config->rewardBadge()] ?? null,
            ],
        ];
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function badges(array $ids): array
    {
        if (! $ids || ! $this->rewards->badgesAvailable()) {
            return [];
        }

        return $this->db->table('fof_badges')
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->get(['id', 'name', 'icon', 'icon_color', 'background_color'])
            ->mapWithKeys(fn ($b) => [(int) $b->id => [
                'id' => (int) $b->id,
                'name' => $b->name,
                'icon' => $b->icon,
                'color' => $b->icon_color,
                'background' => $b->background_color,
            ]])
            ->all();
    }

    /**
     * The tags steps are limited to — only those this member can see. A
     * restricted tag's name never reaches someone it is hidden from; the step
     * just shows the type's own wording.
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function tags(User $user, array $ids): array
    {
        if (! $ids || ! $this->extensions->isEnabled('flarum-tags')) {
            return [];
        }

        return \Flarum\Tags\Tag::query()
            ->whereVisibleTo($user)
            ->whereIn('tags.id', $ids)
            ->get(['tags.id', 'tags.name', 'tags.slug'])
            ->mapWithKeys(fn ($t) => [(int) $t->id => ['id' => (int) $t->id, 'name' => $t->name, 'slug' => $t->slug]])
            ->all();
    }

    private function iso(mixed $date): string
    {
        return \Carbon\Carbon::parse($date)->toIso8601String();
    }
}
