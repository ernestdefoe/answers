<?php

namespace ErnestDefoe\Answers\Search;

use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;

/**
 * filter[answers]=top on posts: a question's answers, without the question
 * itself and without comments (those come in under each answer).
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class AnswerPostsFilter implements FilterInterface
{
    public function getFilterKey(): string
    {
        return 'answers';
    }

    public function filter(SearchState $state, array|string $value, bool $negate): void
    {
        $state->getQuery()
            ->where('posts.type', 'comment')
            ->whereNull('posts.answers_parent_id')
            ->where('posts.number', '<>', 1);
    }
}
