<?php

namespace ErnestDefoe\Onramp;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member's journey.
 *
 * @property int $user_id
 * @property Carbon $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $closed_at
 * @property int $reminded
 * @property User|null $user
 */
class Journey extends AbstractModel
{
    protected $table = 'onramp_journeys';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'closed_at' => 'datetime',
        'reminded' => 'integer',
    ];

    public function isOpen(): bool
    {
        return $this->completed_at === null && $this->closed_at === null;
    }

    public function status(): string
    {
        return $this->completed_at ? 'graduated' : ($this->closed_at ? 'closed' : 'open');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
