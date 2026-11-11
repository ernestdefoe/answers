<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

// Who caused a reputation change (the voter, for votes): taking a vote back removes that voter's row.
return [
    'up' => function (Builder $schema) {
        $schema->table('answers_reputation_log', function (Blueprint $table) {
            $table->unsignedInteger('actor_id')->nullable();
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('answers_reputation_log', function (Blueprint $table) {
            $table->dropColumn('actor_id');
        });
    },
];
