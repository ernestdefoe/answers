<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

// The log is the truth (and the profile's history); the totals are its sums per member and tag.
return [
    'up' => function (Builder $schema) {
        $schema->create('answers_reputation', function (Blueprint $table) {
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('tag_id');
            $table->integer('points')->default(0);

            $table->primary(['user_id', 'tag_id']);
            $table->index(['tag_id', 'points']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('tag_id')->references('id')->on('tags')->cascadeOnDelete();
        });

        $schema->create('answers_reputation_log', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('tag_id');
            $table->unsignedInteger('discussion_id')->nullable();
            $table->unsignedInteger('post_id')->nullable();
            $table->string('reason', 32);
            $table->integer('points');
            $table->dateTime('created_at');

            $table->index(['user_id', 'created_at']);
            $table->index('post_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('tag_id')->references('id')->on('tags')->cascadeOnDelete();
            $table->foreign('discussion_id')->references('id')->on('discussions')->nullOnDelete();
            $table->foreign('post_id')->references('id')->on('posts')->nullOnDelete();
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('answers_reputation_log');
        $schema->dropIfExists('answers_reputation');
    },
];
