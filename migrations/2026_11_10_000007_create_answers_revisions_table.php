<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('answers_revisions', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('post_id');
    $table->unsignedInteger('user_id')->nullable();
    $table->mediumText('content');
    $table->string('summary', 200)->nullable();
    $table->dateTime('created_at');

    $table->index('post_id');
    $table->foreign('post_id')->references('id')->on('posts')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
});
