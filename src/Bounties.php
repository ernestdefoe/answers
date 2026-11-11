<?php

namespace ErnestDefoe\Answers;

use Carbon\Carbon;
use ErnestDefoe\Answers\Event\BountyAwarded;
use ErnestDefoe\Answers\Event\BountyStarted;
use ErnestDefoe\Answers\Notification\BountyAwardedBlueprint;
use Flarum\Discussion\Discussion;
use Flarum\Notification\NotificationSyncer;
use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;

/**
 * Bounties, Stack Overflow's way. The amount is taken when the bounty starts
 * (reputation in the question's main Q&A tag, or Money from antoinefr/money).
 * Accepting an answer while it runs pays that answer in full. When time runs
 * out unpaid, the top-voted answer posted during the bounty with at least 2
 * votes gets half; if none qualifies, the bounty is gone.
 */
class Bounties
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Reputation $reputation,
        private readonly Settings $settings,
        private readonly Integrations $integrations,
        private readonly NotificationSyncer $notifications,
        private readonly Dispatcher $events
    ) {
    }

    /** @return string[] the currencies offered right now. */
    public function currencies(): array
    {
        $mode = $this->settings->string('bounty_currency');
        if ($mode === 'off') {
            return [];
        }
        $currencies = ['rep'];
        if ($mode === 'both' && $this->integrations->money()) {
            $currencies[] = 'money';
        }

        return $currencies;
    }

    /** The tag a reputation bounty is paid from and into: the question's first Q&A tag. */
    public function tagFor(Discussion $discussion): ?int
    {
        return Question::tagIds($discussion)[0] ?? null;
    }

    public function start(Discussion $discussion, User $actor, int $amount, string $currency, string $reason): int
    {
        if (! in_array($amount, Settings::BOUNTY_AMOUNTS, true)) {
            throw Fail::on('amount', 'invalid');
        }
        if (! in_array($currency, $this->currencies(), true)) {
            throw Fail::on('currency', 'invalid');
        }
        $tagId = $this->tagFor($discussion);

        return $this->db->transaction(function () use ($discussion, $actor, $amount, $currency, $reason, $tagId) {
            // Claim the question first, so two bounties can't start at once.
            $ends = Carbon::now()->addDays(max(1, $this->settings->int('bounty_days')));
            $claimed = $this->db->table('discussions')->where('id', $discussion->id)->where('answers_bounty', 0)
                ->update(['answers_bounty' => $amount, 'answers_bounty_ends_at' => $ends]);
            if (! $claimed) {
                throw Fail::on('bounty', 'bounty_running');
            }

            if ($currency === 'money') {
                $paid = $this->db->table('users')->where('id', $actor->id)->where('money', '>=', $amount)->decrement('money', $amount);
                if (! $paid) {
                    throw Fail::on('amount', 'not_enough');
                }
                $this->moneyChanged($actor->id);
            } else {
                if (! $tagId || $this->reputation->best($actor, [$tagId]) < $amount) {
                    throw Fail::on('amount', 'not_enough');
                }
                $this->reputation->give($actor->id, [$tagId], -$amount, 'bounty_offered', $discussion->id);
                $this->reputation->forgetMemo($actor);
            }

            $id = $this->db->table('answers_bounties')->insertGetId([
                'discussion_id' => $discussion->id, 'user_id' => $actor->id, 'tag_id' => $tagId,
                'amount' => $amount, 'currency' => $currency, 'reason' => $reason, 'status' => 'open',
                'starts_at' => Carbon::now(), 'ends_at' => $ends,
            ]);
            $discussion->answers_bounty = $amount;
            $discussion->answers_bounty_ends_at = $ends;
            $this->events->dispatch(new BountyStarted($discussion, $actor, $amount, $currency));

            return $id;
        });
    }

    /** The accepted answer takes a running bounty in full (never the bounty's own owner). */
    public function payOnAccept(Discussion $discussion, Post $post): void
    {
        $bounty = $this->open($discussion->id);
        if ($bounty && $post->user_id !== $bounty->user_id) {
            $this->award($bounty, $post, (int) $bounty->amount);
        }
    }

    /** Close every bounty whose time is up. Returns how many. */
    public function expire(): int
    {
        $done = 0;
        $due = $this->db->table('answers_bounties')->where('status', 'open')->where('ends_at', '<=', Carbon::now())->get();
        foreach ($due as $bounty) {
            $best = Post::query()
                ->where('discussion_id', $bounty->discussion_id)
                ->where('type', 'comment')
                ->whereNull('answers_parent_id')
                ->whereNull('hidden_at')
                ->where('is_private', false)
                ->where('number', '>', 1)
                ->where('created_at', '>=', $bounty->starts_at)
                ->where('answers_score', '>=', 2)
                ->when($bounty->user_id, fn ($q) => $q->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '<>', $bounty->user_id)))
                ->orderByDesc('answers_score')->orderBy('created_at')
                ->first();

            if ($best && $best->user_id) {
                $this->award($bounty, $best, intdiv((int) $bounty->amount, 2));
            } else {
                $this->close($bounty, 'expired');
            }
            $done++;
        }

        return $done;
    }

    /**
     * Pay a bounty, once. The claim (open → awarded) is a conditional update, and only the caller whose
     * claim changed the row pays: an accept during the expiry run, or two accepts at once, can't both pay.
     */
    private function award(object $bounty, Post $post, int $amount): void
    {
        $paid = $this->db->transaction(function () use ($bounty, $post, $amount) {
            if (! $this->close($bounty, 'awarded', $post, $amount)) {
                return false;
            }
            if ($bounty->currency === 'money') {
                $this->db->table('users')->where('id', $post->user_id)->increment('money', $amount);
            } elseif ($bounty->tag_id) {
                $this->reputation->give($post->user_id, [(int) $bounty->tag_id], $amount, 'bounty', (int) $bounty->discussion_id, $post->id);
            }

            return true;
        });
        if (! $paid) {
            return;
        }

        if ($bounty->currency === 'money') {
            $this->moneyChanged($post->user_id);
        }
        if ($post->user) {
            $this->notifications->sync(new BountyAwardedBlueprint($post, $amount, $bounty->currency), [$post->user]);
        }
        $this->events->dispatch(new BountyAwarded($post, $amount, $bounty->currency));
    }

    /** @return bool whether this call closed it (false: someone else already had). */
    private function close(object $bounty, string $status, ?Post $post = null, int $amount = 0): bool
    {
        $claimed = $this->db->table('answers_bounties')->where('id', $bounty->id)->where('status', 'open')->update([
            'status' => $status, 'awarded_post_id' => $post?->id, 'awarded_user_id' => $post?->user_id, 'awarded_amount' => $amount,
        ]);
        if ($claimed) {
            $this->db->table('discussions')->where('id', $bounty->discussion_id)->update(['answers_bounty' => 0, 'answers_bounty_ends_at' => null]);
        }

        return $claimed === 1;
    }

    public function open(int $discussionId): ?object
    {
        return $this->db->table('answers_bounties')->where('discussion_id', $discussionId)->where('status', 'open')->first();
    }

    private function moneyChanged(?int $userId): void
    {
        if ($userId && class_exists(\AntoineFr\Money\Event\MoneyUpdated::class)) {
            $this->events->dispatch(new \AntoineFr\Money\Event\MoneyUpdated(User::query()->find($userId)));
        }
    }
}
