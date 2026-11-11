<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->create('answers_reviews', function (Blueprint $table) {
            $table->increments('id');
            // low_quality | first_answer | suggested_edit
            $table->string('type', 16);
            $table->unsignedInteger('post_id');
            $table->unsignedInteger('discussion_id');
            $table->unsignedInteger('user_id')->nullable();
            // The suggested text (suggested edits only).
            $table->mediumText('content')->nullable();
            $table->string('summary', 200)->nullable();
            // open | approved | rejected | ok | hidden
            $table->string('status', 16)->default('open');
            $table->unsignedInteger('resolved_user_id')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('resolved_at')->nullable();

            $table->index(['status', 'type']);
            $table->index('post_id');
            $table->foreign('post_id')->references('id')->on('posts')->cascadeOnDelete();
            $table->foreign('discussion_id')->references('id')->on('discussions')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('resolved_user_id')->references('id')->on('users')->nullOnDelete();
        });

        $schema->create('answers_review_votes', function (Blueprint $table) {
            $table->unsignedInteger('review_id');
            $table->unsignedInteger('user_id');
            // ok | hide | approve | reject
            $table->string('verdict', 8);
            $table->dateTime('created_at');

            $table->primary(['review_id', 'user_id']);
            $table->foreign('review_id')->references('id')->on('answers_reviews')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        $schema->create('answers_close_votes', function (Blueprint $table) {
            $table->unsignedInteger('discussion_id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('duplicate_of_id');
            $table->dateTime('created_at');

            $table->primary(['discussion_id', 'user_id']);
            $table->foreign('discussion_id')->references('id')->on('discussions')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('duplicate_of_id')->references('id')->on('discussions')->cascadeOnDelete();
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('answers_close_votes');
        $schema->dropIfExists('answers_review_votes');
        $schema->dropIfExists('answers_reviews');
    },
];
