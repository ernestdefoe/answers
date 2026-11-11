<?php

use Flarum\Database\Migration;

return Migration::addColumns('tags', [
    'answers_enabled' => ['boolean', 'default' => false],
]);
