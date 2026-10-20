<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * A member's steps: done (and paid) or skipped.
 *
 * 🚨 A completed row is never deleted by anything the member does. Deleting
 * the post that completed "reply to a discussion" does not untick it, so a
 * step is paid exactly once and cannot be farmed by posting and deleting.
 */
return Migration::createTable('onramp_progress', function (Blueprint $table) {
    $table->unsignedInteger('user_id');
    $table->unsignedInteger('step_id');
    $table->dateTime('completed_at')->nullable();
    $table->dateTime('skipped_at')->nullable();

    $table->primary(['user_id', 'step_id']);
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
    $table->foreign('step_id')->references('id')->on('onramp_steps')->cascadeOnDelete();
    // The funnel: completions per step.
    $table->index(['step_id', 'completed_at']);
});
