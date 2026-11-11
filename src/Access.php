<?php

namespace ErnestDefoe\Answers;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\User\User;

/**
 * Who may do what. Each right is a permission (tag-scoped through Flarum
 * Tags), and editing others' answers, reviewing and voting to close also
 * need reputation in one of the question's Q&A tags. Moderators (the
 * answersModerate permission) need neither.
 */
class Access
{
    public function __construct(private readonly Reputation $reputation, private readonly Settings $settings)
    {
    }

    public function moderates(User $actor, Discussion $discussion): bool
    {
        return ! $actor->isGuest() && $actor->can('answersModerate', $discussion);
    }

    public function canVote(User $actor, Post $post): bool
    {
        return ! $actor->isGuest()
            && $post->user_id !== $actor->id
            && $post->hidden_at === null
            && $actor->can('answersVote', $post->discussion);
    }

    public function canAccept(User $actor, Discussion $discussion): bool
    {
        if ($actor->isGuest() || $discussion->answers_duplicate_of_id) {
            return false;
        }

        return ($discussion->user_id === $actor->id && $actor->can('reply', $discussion)) || $this->moderates($actor, $discussion);
    }

    public function canEditOthers(User $actor, Discussion $discussion): bool
    {
        return $this->earned($actor, $discussion, 'answersEditOthers', 'edit_reputation');
    }

    public function canSuggest(User $actor, Post $post): bool
    {
        return ! $actor->isGuest()
            && $post->user_id !== $actor->id
            && $post->hidden_at === null
            && $actor->can('answersSuggest', $post->discussion)
            && ! $this->canEditOthers($actor, $post->discussion);
    }

    /**
     * Old revisions can hold what an author took out on purpose, so history is for the author,
     * the members trusted to edit answers in this question, and moderators.
     */
    public function canSeeHistory(User $actor, Post $post): bool
    {
        return ! $actor->isGuest()
            && ($post->user_id === $actor->id || $this->canEditOthers($actor, $post->discussion));
    }

    public function canReview(User $actor, Discussion $discussion): bool
    {
        return $this->earned($actor, $discussion, 'answersReview', 'review_reputation');
    }

    public function canVoteToClose(User $actor, Discussion $discussion): bool
    {
        return $discussion->answers_duplicate_of_id === null && $this->earned($actor, $discussion, 'answersClose', 'review_reputation');
    }

    /** @return string|null why not, or null when a bounty may be started now. */
    public function bountyRefusal(User $actor, Discussion $discussion): ?string
    {
        if ($actor->isGuest() || ! $actor->can('answersBounty', $discussion) || $this->settings->string('bounty_currency') === 'off') {
            return 'permission';
        }
        if ($discussion->answers_bounty > 0) {
            return 'bounty_running';
        }
        if ($discussion->answers_accepted_post_id || $discussion->answers_duplicate_of_id) {
            return 'closed';
        }
        if ($discussion->created_at > Carbon::now()->subDays($this->settings->int('bounty_min_age_days'))) {
            return 'too_new';
        }

        return null;
    }

    private function earned(User $actor, Discussion $discussion, string $ability, string $threshold): bool
    {
        if ($actor->isGuest()) {
            return false;
        }
        if ($this->moderates($actor, $discussion)) {
            return true;
        }

        return $actor->can($ability, $discussion)
            && $this->reputation->best($actor, Question::tagIds($discussion)) >= $this->settings->int($threshold);
    }
}
