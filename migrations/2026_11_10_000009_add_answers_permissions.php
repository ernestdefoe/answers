<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

return Migration::addPermissions([
    'discussion.answersVote' => Group::MEMBER_ID,
    'discussion.answersSuggest' => Group::MEMBER_ID,
    'discussion.answersEditOthers' => Group::MEMBER_ID,
    'discussion.answersReview' => Group::MEMBER_ID,
    'discussion.answersClose' => Group::MEMBER_ID,
    'discussion.answersBounty' => Group::MEMBER_ID,
    'discussion.answersModerate' => Group::MODERATOR_ID,
]);
