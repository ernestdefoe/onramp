<?php

namespace ErnestDefoe\Onramp;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * One member's state on one step.
 *
 * @property int $user_id
 * @property int $step_id
 * @property Carbon|null $completed_at
 * @property Carbon|null $skipped_at
 */
class Progress extends AbstractModel
{
    protected $table = 'onramp_progress';

    // Composite key: written and read through the query builder by
    // (user_id, step_id), never saved as a model.
    protected $primaryKey = null;

    public $incrementing = false;

    protected $casts = [
        'completed_at' => 'datetime',
        'skipped_at' => 'datetime',
    ];
}
