<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * The journey, as the admin wrote it: one row per step, in order.
 *
 * `type` names a step type (see src/StepType); a type whose extension is not
 * enabled is skipped everywhere, so a step never blocks graduation because
 * the thing it counts was switched off.
 */
return Migration::createTable('onramp_steps', function (Blueprint $table) {
    $table->increments('id');
    $table->string('type', 60);
    // Per-type settings, e.g. {"count": 3, "tag": 5, "path": "/d/1-rules"}.
    $table->text('config')->nullable();

    // Empty means "use the type's own wording", translated for each reader.
    $table->string('title', 150)->nullable();
    $table->text('description')->nullable();
    $table->string('icon', 100)->nullable();
    $table->string('button_label', 100)->nullable();
    $table->string('button_url', 255)->nullable();

    $table->integer('points')->default(0);
    // fof/badges is optional, so no foreign key: a deleted badge is a step
    // that simply stops awarding one.
    $table->unsignedInteger('badge_id')->nullable();

    $table->boolean('is_required')->default(false);
    $table->boolean('is_enabled')->default(true);
    $table->integer('position')->default(0);

    $table->timestamps();
});
