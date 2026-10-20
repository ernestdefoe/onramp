<?php

namespace ErnestDefoe\Onramp;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * A step of the journey.
 *
 * @property int $id
 * @property string $type
 * @property array<string, mixed>|null $config
 * @property string|null $title
 * @property string|null $description
 * @property string|null $icon
 * @property string|null $button_label
 * @property string|null $button_url
 * @property int $points
 * @property int|null $badge_id
 * @property bool $is_required
 * @property bool $is_enabled
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Step extends AbstractModel
{
    protected $table = 'onramp_steps';

    public $timestamps = true;

    protected $casts = [
        'config' => 'array',
        'points' => 'integer',
        'badge_id' => 'integer',
        'is_required' => 'boolean',
        'is_enabled' => 'boolean',
        'position' => 'integer',
    ];

    /** @return array<string, mixed> */
    public function settings(): array
    {
        return $this->config ?? [];
    }
}
