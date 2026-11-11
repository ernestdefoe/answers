<?php

use Flarum\Database\Migration;

return Migration::addColumns('users', [
    'answers_accepted_count' => ['integer', 'unsigned' => true, 'default' => 0],
    'answers_solved_count' => ['integer', 'unsigned' => true, 'default' => 0],
]);
