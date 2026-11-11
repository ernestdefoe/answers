<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

// A comment is a real post whose parent is the answer (or the question) it sits under.
return [
    'up' => function (Builder $schema) {
        $schema->table('posts', function (Blueprint $table) {
            $table->unsignedInteger('answers_parent_id')->nullable();
            $table->integer('answers_score')->default(0);
            $table->unsignedSmallInteger('answers_revisions')->default(0);

            $table->foreign('answers_parent_id')->references('id')->on('posts')->cascadeOnDelete();
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('posts', function (Blueprint $table) {
            $table->dropForeign(['answers_parent_id']);
            $table->dropColumn(['answers_parent_id', 'answers_score', 'answers_revisions']);
        });
    },
];
