<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * One row per member who was given a journey.
 *
 * A member with no row never had one (they joined before Onramp, and no admin
 * started journeys for existing members). The cohort funnel groups by
 * `started_at`, so it is indexed.
 */
return Migration::createTable('onramp_journeys', function (Blueprint $table) {
    $table->unsignedInteger('user_id')->primary();
    $table->dateTime('started_at');
    // Every step done or skipped: graduated.
    $table->dateTime('completed_at')->nullable();
    // Put away: by the member ("I'll find my own way"), or for inactivity.
    $table->dateTime('closed_at')->nullable();
    // The last reminder sent, in days after the start (0 = none yet).
    $table->unsignedSmallInteger('reminded')->default(0);

    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
    $table->index('started_at');
    $table->index('completed_at');
});
