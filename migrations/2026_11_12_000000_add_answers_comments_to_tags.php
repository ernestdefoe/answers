<?php

use Flarum\Database\Migration;

return Migration::addColumns('tags', [
    'answers_comments' => ['boolean', 'default' => true],
]);
