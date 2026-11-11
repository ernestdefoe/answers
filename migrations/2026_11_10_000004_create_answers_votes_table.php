<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('answers_votes', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('post_id');
    $table->unsignedInteger('user_id');
    $table->tinyInteger('value');
    $table->dateTime('created_at');

    $table->unique(['post_id', 'user_id']);
    $table->index('user_id');
    $table->foreign('post_id')->references('id')->on('posts')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});
