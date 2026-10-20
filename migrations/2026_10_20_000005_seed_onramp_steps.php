<?php

use Carbon\Carbon;
use Illuminate\Database\Schema\Builder;

/**
 * A journey that works the moment Onramp is enabled: five steps, all core or
 * bundled with Flarum, in the order a newcomer usually takes them.
 *
 * Seeded only into an empty table, so re-running migrations never duplicates
 * an admin's own journey. Rolling back leaves the admin's steps alone too.
 */
return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();

        if ($db->table('onramp_steps')->exists()) {
            return;
        }

        $now = Carbon::now();
        $steps = [
            ['type' => 'avatar', 'config' => null, 'points' => 5, 'is_required' => false],
            ['type' => 'read', 'config' => json_encode(['count' => 3]), 'points' => 5, 'is_required' => false],
            ['type' => 'like_given', 'config' => null, 'points' => 5, 'is_required' => false],
            ['type' => 'reply', 'config' => null, 'points' => 10, 'is_required' => true],
            ['type' => 'discussion', 'config' => null, 'points' => 10, 'is_required' => false],
        ];

        foreach ($steps as $i => $step) {
            $db->table('onramp_steps')->insert($step + [
                'is_enabled' => true,
                'position' => $i,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    },
    'down' => function (Builder $schema) {
        // The steps table itself is dropped by its own migration.
    },
];
