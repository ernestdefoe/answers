<?php

namespace ErnestDefoe\Answers\Search;

use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;

/**
 * filter[answersDuplicateOf]=12: the questions closed as duplicates of discussion 12.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class DuplicateOfFilter implements FilterInterface
{
    public function getFilterKey(): string
    {
        return 'answersDuplicateOf';
    }

    public function filter(SearchState $state, array|string $value, bool $negate): void
    {
        $id = (int) (is_array($value) ? reset($value) : $value);
        $state->getQuery()->where('discussions.answers_duplicate_of_id', $negate ? '<>' : '=', $id);
    }
}
