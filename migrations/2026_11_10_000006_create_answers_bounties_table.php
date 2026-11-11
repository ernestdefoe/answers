<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('answers_bounties', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('discussion_id');
    $table->unsignedInteger('user_id')->nullable();
    $table->unsignedInteger('tag_id')->nullable();
    $table->unsignedInteger('amount');
    // rep | money
    $table->string('currency', 8);
    $table->string('reason', 32);
    // open | awarded | expired
    $table->string('status', 16)->default('open');
    $table->dateTime('starts_at');
    $table->dateTime('ends_at');
    $table->unsignedInteger('awarded_post_id')->nullable();
    $table->unsignedInteger('awarded_user_id')->nullable();
    $table->unsignedInteger('awarded_amount')->default(0);

    $table->index(['status', 'ends_at']);
    $table->index('discussion_id');
    $table->foreign('discussion_id')->references('id')->on('discussions')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
    $table->foreign('tag_id')->references('id')->on('tags')->nullOnDelete();
    $table->foreign('awarded_post_id')->references('id')->on('posts')->nullOnDelete();
    $table->foreign('awarded_user_id')->references('id')->on('users')->nullOnDelete();
});
