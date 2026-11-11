<?php

use Flarum\Database\Migration;

// A suggested edit remembers the text it was written against, so it can't be applied over a newer edit.
return Migration::addColumns('answers_reviews', [
    'base_hash' => ['string', 'length' => 40, 'nullable' => true],
]);
