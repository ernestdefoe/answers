<?php

namespace ErnestDefoe\Answers;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * A member is being deleted: their votes cascade away with the user row, so
 * first take each one off its post's score and take back the reputation it
 * gave. Their own reputation rows go with them through the foreign keys.
 */
class Forget
{
    public function __construct(private readonly ConnectionInterface $db, private readonly Reputation $reputation)
    {
    }

    public function user(User $user): void
    {
        $this->db->table('answers_votes')
            ->join('posts', 'posts.id', '=', 'answers_votes.post_id')
            ->where('answers_votes.user_id', $user->id)
            ->orderBy('answers_votes.id')
            ->select('answers_votes.id', 'answers_votes.post_id', 'answers_votes.value', 'posts.user_id', 'posts.discussion_id', 'posts.answers_parent_id')
            ->chunk(500, function ($votes) {
                foreach ($votes as $vote) {
                    $this->db->table('posts')->where('id', $vote->post_id)->decrement('answers_score', (int) $vote->value);
                    if ($vote->answers_parent_id === null) {
                        $this->reputation->takeBack($vote->user_id, $vote->value > 0 ? 'upvote' : 'downvote', $vote->discussion_id, $vote->post_id, $user->id);
                    }
                }
            });

        // Questions' scores mirror their first post.
        $this->db->table('discussions')
            ->whereIn('first_post_id', $this->db->table('answers_votes')->where('user_id', $user->id)->select('post_id'))
            ->update(['answers_score' => $this->db->raw('(select '.$this->db->getQueryGrammar()->wrap('posts.answers_score').' from '.$this->db->getQueryGrammar()->wrapTable('posts').' where '.$this->db->getQueryGrammar()->wrap('posts.id').' = '.$this->db->getQueryGrammar()->wrap('discussions.first_post_id').')')]);
    }
}
