<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

// State, counts and score live on the row, so a discussion list costs nothing extra.
return [
    'up' => function (Builder $schema) {
        $schema->table('discussions', function (Blueprint $table) {
            $table->boolean('answers_question')->default(false);
            // 0 unanswered, 1 answered, 2 solved, 3 closed as a duplicate.
            $table->unsignedTinyInteger('answers_state')->default(0);
            $table->unsignedInteger('answers_count')->default(0);
            $table->integer('answers_score')->default(0);
            $table->unsignedInteger('answers_accepted_post_id')->nullable();
            $table->unsignedInteger('answers_duplicate_of_id')->nullable();
            $table->unsignedInteger('answers_bounty')->default(0);
            $table->dateTime('answers_bounty_ends_at')->nullable();

            $table->index(['answers_question', 'answers_state']);
            $table->foreign('answers_accepted_post_id')->references('id')->on('posts')->nullOnDelete();
            $table->foreign('answers_duplicate_of_id')->references('id')->on('discussions')->nullOnDelete();
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('discussions', function (Blueprint $table) {
            $table->dropForeign(['answers_accepted_post_id']);
            $table->dropForeign(['answers_duplicate_of_id']);
            $table->dropIndex(['answers_question', 'answers_state']);
            $table->dropColumn(['answers_question', 'answers_state', 'answers_count', 'answers_score', 'answers_accepted_post_id', 'answers_duplicate_of_id', 'answers_bounty', 'answers_bounty_ends_at']);
        });
    },
];
