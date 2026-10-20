<?php

use Flarum\Database\Migration;

/**
 * 🚨 Why a column on users, not just the journeys table.
 *
 * The member's own journey rides on the user the page already loads. Read from
 * onramp_journeys, that is a query on every page for every signed-in member —
 * including the graduates and old-timers who will never see Onramp again. With
 * this flag, everyone without an open journey costs nothing: the field is
 * null before any query runs.
 */
return Migration::addColumns('users', [
    'onramp_open' => ['boolean', 'default' => false],
]);
