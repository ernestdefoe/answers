<?php

namespace ErnestDefoe\Answers\Search;

use ErnestDefoe\Answers\Integrations;
use ErnestDefoe\Answers\Question;
use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;

/**
 * filter[answers]=question|unanswered|answered|open|solved|duplicate|bounty|mine
 *
 * "mine" is the unanswered-for-you feed: unanswered questions in the tags
 * the viewer follows (FoF Follow Tags), or in every Q&A tag when they follow
 * none or the extension is off.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class AnswersFilter implements FilterInterface
{
    public function __construct(private readonly Integrations $integrations)
    {
    }

    public function getFilterKey(): string
    {
        return 'answers';
    }

    public function filter(SearchState $state, array|string $value, bool $negate): void
    {
        $value = is_array($value) ? (string) reset($value) : $value;
        $query = $state->getQuery();
        $method = $negate ? 'whereNot' : 'where';

        $query->$method(function ($query) use ($value, $state) {
            $query->where('discussions.answers_question', true);

            match ($value) {
                'unanswered', 'mine' => $query->where('discussions.answers_state', Question::UNANSWERED),
                'answered' => $query->where('discussions.answers_state', Question::ANSWERED),
                'open' => $query->whereIn('discussions.answers_state', [Question::UNANSWERED, Question::ANSWERED]),
                'solved' => $query->where('discussions.answers_state', Question::SOLVED),
                'duplicate' => $query->where('discussions.answers_state', Question::DUPLICATE),
                'bounty' => $query->where('discussions.answers_bounty', '>', 0),
                default => null,
            };

            if ($value === 'mine' && $this->integrations->followTags() && ! $state->getActor()->isGuest()) {
                $followed = fn ($sub) => $sub->select('tag_id')->from('tag_user')
                    ->where('user_id', $state->getActor()->id)->whereIn('subscription', ['follow', 'lurk']);
                // Only narrows when they follow something; following nothing leaves every Q&A tag.
                $query->where(fn ($q) => $q
                    ->whereNotExists(fn ($sub) => $followed($sub->selectRaw('1')))
                    ->orWhereIn('discussions.id', fn ($sub) => $sub->select('discussion_id')->from('discussion_tag')->whereIn('tag_id', $followed)));
            }
        });
    }
}
