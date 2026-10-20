<?php

namespace ErnestDefoe\Onramp\Api;

use Carbon\Carbon;
use ErnestDefoe\Onramp\Rewards;
use ErnestDefoe\Onramp\Step;
use ErnestDefoe\Onramp\StepType\Registry;
use ErnestDefoe\Onramp\StepType\StepType;
use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The step editor. Admins only.
 *
 *   GET    /api/onramp-admin/steps          every step + the type catalogue
 *   POST   /api/onramp-admin/steps          add one
 *   PATCH  /api/onramp-admin/steps/{id}     change one
 *   DELETE /api/onramp-admin/steps/{id}     remove one (members' progress on it goes too)
 *   POST   /api/onramp-admin/order          {ids: [...]} — the dragged order
 *   POST   /api/onramp-admin/import         {steps: [...], replace?: bool} — presets and imports
 */
class AdminStepsController implements RequestHandlerInterface
{
    public function __construct(
        private Registry $types,
        private StepInput $input,
        private Rewards $rewards,
        private ConnectionInterface $db,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $params = $request->getQueryParams();
        $body = (array) $request->getParsedBody();
        $id = (int) ($params['id'] ?? 0);
        $method = $request->getMethod();
        $route = (string) ($params['what'] ?? 'steps');

        if ($route === 'order') {
            $this->order(array_map('intval', (array) ($body['ids'] ?? [])));
        } elseif ($route === 'import') {
            $this->import((array) ($body['steps'] ?? []), (bool) ($body['replace'] ?? false));
        } elseif ($method === 'POST') {
            $row = $this->input->normalise((array) ($body['attributes'] ?? []));
            $row += ['position' => (int) Step::query()->max('position') + 1, 'is_enabled' => true];
            $step = new Step();
            $step->forceFill($row)->save();
        } elseif ($method === 'PATCH') {
            $step = Step::query()->findOrFail($id);
            $step->forceFill($this->input->normalise((array) ($body['attributes'] ?? []), true))->save();
        } elseif ($method === 'DELETE') {
            Step::query()->findOrFail($id)->delete();

            return new EmptyResponse(204);
        }

        return new JsonResponse($this->listing());
    }

    /** @return array<string, mixed> */
    private function listing(): array
    {
        $available = $this->types->available();

        return [
            'data' => Step::query()->orderBy('position')->orderBy('id')->get()->map(fn (Step $s) => [
                'id' => $s->id,
                'type' => $s->type,
                'config' => (object) $s->settings(),
                'title' => $s->title,
                'description' => $s->description,
                'icon' => $s->icon,
                'buttonLabel' => $s->button_label,
                'buttonUrl' => $s->button_url,
                'points' => $s->points,
                'badgeId' => $s->badge_id,
                'required' => $s->is_required,
                'enabled' => $s->is_enabled,
                // A step whose extension was switched off stays in the list,
                // marked, rather than vanishing from the editor.
                'available' => isset($available[$s->type]),
            ])->all(),
            'meta' => [
                'types' => array_values(array_map(fn (StepType $t) => [
                    'key' => $t->key(),
                    'icon' => $t->icon(),
                    'fields' => $t->fields(),
                    'extension' => $t->extension(),
                    'available' => isset($available[$t->key()]),
                ], $this->types->all())),
                'points' => $this->rewards->pointsAvailable(),
                'badges' => $this->rewards->badgesAvailable(),
            ],
        ];
    }

    /**
     * The dragged order, in ONE statement: `position = CASE id WHEN … END`.
     *
     * The ids are cast to int before they reach the SQL, so building the CASE
     * as text is safe; `id` is a column, never a table, so no prefix applies.
     *
     * @param list<int> $ids
     */
    private function order(array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id) => $id > 0)));

        if (! $ids) {
            return;
        }

        $case = 'CASE id';
        foreach ($ids as $position => $id) {
            $case .= " WHEN $id THEN $position";
        }

        $this->db->table('onramp_steps')->whereIn('id', $ids)->update(['position' => $this->db->raw($case.' END')]);
    }

    /**
     * Add steps from a preset or an exported file.
     *
     * Every step is checked like one typed into the editor. Steps of a type
     * this forum does not have are left out rather than failing the import,
     * so a file from a forum with more extensions still brings what fits.
     *
     * @param array<int, mixed> $steps
     */
    private function import(array $steps, bool $replace): void
    {
        $rows = [];

        foreach (array_slice($steps, 0, 50) as $raw) {
            if (! is_array($raw) || ! $this->types->get((string) ($raw['type'] ?? ''))) {
                continue;
            }

            $rows[] = $this->input->normalise([
                'type' => $raw['type'],
                'config' => (array) ($raw['config'] ?? []),
                'title' => $raw['title'] ?? null,
                'description' => $raw['description'] ?? null,
                'icon' => $raw['icon'] ?? null,
                'button_label' => $raw['buttonLabel'] ?? null,
                'button_url' => $raw['buttonUrl'] ?? null,
                'points' => $raw['points'] ?? 0,
                // Badge ids are this forum's own; one from another forum
                // would name a different badge, so imports never carry them.
                'is_required' => $raw['required'] ?? false,
                'is_enabled' => $raw['enabled'] ?? true,
            ]);
        }

        $this->db->transaction(function () use ($rows, $replace) {
            if ($replace) {
                Step::query()->delete();
            }

            $position = (int) Step::query()->max('position');
            $now = Carbon::now();

            foreach ($rows as $row) {
                $row['config'] = isset($row['config']) ? json_encode($row['config']) : null;
                $this->db->table('onramp_steps')->insert($row + ['position' => ++$position, 'created_at' => $now, 'updated_at' => $now]);
            }
        });
    }
}
