<?php

namespace ErnestDefoe\Answers\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Answers\Import;
use ErnestDefoe\Answers\Question;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Tags\Tag;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use PHPUnit\Framework\Attributes\Test;

/** The optional partners: FoF Best Answer (import), FoF Gamification (vote mirror), Money (bounties). */
class IntegrationsTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private function seed(): void
    {
        $old = Carbon::now()->subDays(5);
        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'ana', 'email' => 'ana@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'ben', 'email' => 'ben@machine.local', 'is_email_confirmed' => 1],
            ],
            Tag::class => [
                ['id' => 1, 'name' => 'Plugins', 'slug' => 'plugins', 'position' => 0],
            ],
            Discussion::class => [
                ['id' => 1, 'title' => 'A question', 'user_id' => 2, 'created_at' => $old, 'last_posted_at' => $old, 'first_post_id' => 1, 'comment_count' => 3, 'last_post_number' => 3, 'is_private' => false],
            ],
            'discussion_tag' => [['discussion_id' => 1, 'tag_id' => 1]],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>Q</p></t>', 'created_at' => $old, 'is_private' => false],
                ['id' => 2, 'discussion_id' => 1, 'number' => 2, 'user_id' => 3, 'type' => 'comment', 'content' => '<t><p>A</p></t>', 'created_at' => $old, 'is_private' => false],
                ['id' => 3, 'discussion_id' => 1, 'number' => 3, 'user_id' => 4, 'type' => 'comment', 'content' => '<t><p>B</p></t>', 'created_at' => $old, 'is_private' => false],
            ],
        ]);
    }

    private function db(): ConnectionInterface
    {
        return resolve(ConnectionInterface::class);
    }

    #[Test]
    public function best_answer_tags_and_answers_are_imported_once(): void
    {
        $this->extension('flarum-tags', 'fof-best-answer', 'ernestdefoe-answers');
        $this->seed();
        $this->app();
        $this->db()->table('tags')->where('id', 1)->update(['is_qna' => true]);
        $this->db()->table('discussions')->where('id', 1)->update(['best_answer_post_id' => 3, 'best_answer_user_id' => 2]);

        $result = resolve(Import::class)->run();
        $this->assertSame(['tags' => 1, 'questions' => 1, 'accepted' => 1], $result);

        $d = $this->db()->table('discussions')->where('id', 1)->first();
        $this->assertSame(3, (int) $d->answers_accepted_post_id);
        $this->assertSame(Question::SOLVED, (int) $d->answers_state);
        $this->assertSame(15, (int) $this->db()->table('answers_reputation')->where('user_id', 4)->value('points'));
        $this->assertSame(0, $this->db()->table('notifications')->count(), 'an import alerts nobody');

        // Again: nothing changes.
        $this->assertSame(0, resolve(Import::class)->run()['accepted']);
        $this->assertSame(15, (int) $this->db()->table('answers_reputation')->where('user_id', 4)->value('points'));

        // The asker takes the acceptance back in Answers; a later import doesn't put it back.
        Carbon::setTestNow(Carbon::now()->addMinute());
        $this->send($this->request('POST', '/api/answers/discussions/1/accept', ['authenticatedAs' => 2, 'json' => ['postId' => null]]));
        $this->assertSame(0, resolve(Import::class)->run()['accepted']);
        $this->assertNull($this->db()->table('discussions')->where('id', 1)->value('answers_accepted_post_id'));

        // A tag that becomes Q&A after that run: its old best answers are imported on the next one.
        $this->db()->table('tags')->insert(['id' => 2, 'name' => 'Themes', 'slug' => 'themes', 'position' => 1, 'is_qna' => true]);
        $this->db()->table('discussions')->insert(['id' => 2, 'title' => 'Old question', 'slug' => 'old-question', 'user_id' => 2, 'created_at' => Carbon::now()->subYear(), 'last_posted_at' => Carbon::now()->subYear(),
            'comment_count' => 2, 'last_post_number' => 2, 'is_private' => false]);
        $this->db()->table('discussion_tag')->insert(['discussion_id' => 2, 'tag_id' => 2]);
        $this->db()->table('posts')->insert([
            ['id' => 4, 'discussion_id' => 2, 'number' => 1, 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>Q</p></t>', 'created_at' => Carbon::now()->subYear(), 'is_private' => false],
            ['id' => 5, 'discussion_id' => 2, 'number' => 2, 'user_id' => 3, 'type' => 'comment', 'content' => '<t><p>A</p></t>', 'created_at' => Carbon::now()->subYear(), 'is_private' => false],
        ]);
        $this->db()->table('discussions')->where('id', 2)->update(['first_post_id' => 4, 'best_answer_post_id' => 5, 'best_answer_user_id' => 2, 'best_answer_set_at' => Carbon::now()->subYear()]);
        $this->assertSame(1, resolve(Import::class)->run()['accepted']);
        $this->assertSame(5, (int) $this->db()->table('discussions')->where('id', 2)->value('answers_accepted_post_id'));
        Carbon::setTestNow();

        // The admin button does the same, admins only.
        $response = $this->send($this->request('POST', '/api/answers/import', ['authenticatedAs' => 2]));
        $this->assertSame(403, $response->getStatusCode());
        $response = $this->send($this->request('POST', '/api/answers/import', ['authenticatedAs' => 1]));
        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function votes_are_mirrored_into_gamification(): void
    {
        $this->extension('flarum-tags', 'fof-gamification', 'ernestdefoe-answers');
        $this->seed();
        $this->app();
        $this->db()->table('tags')->where('id', 1)->update(['answers_enabled' => true]);
        resolve(Question::class)->refreshFlags([1]);

        $response = $this->send($this->request('POST', '/api/answers/posts/2/vote', ['authenticatedAs' => 4, 'json' => ['value' => 1]]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(1, (int) $this->db()->table('post_votes')->where('post_id', 2)->where('user_id', 4)->value('value'));
        $this->assertSame(1, (int) $this->db()->table('users')->where('id', 3)->value('votes'));

        $this->send($this->request('POST', '/api/answers/posts/2/vote', ['authenticatedAs' => 4, 'json' => ['value' => 0]]));
        $this->assertSame(0, (int) $this->db()->table('users')->where('id', 3)->value('votes'));
    }

    #[Test]
    public function bounties_can_be_paid_in_money(): void
    {
        $driver = getenv('DB_DRIVER') ?: 'mysql';
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            // antoinefr/money's migration adds a NOT NULL column without a default, which only MySQL accepts.
            $this->markTestSkipped('Money installs on MySQL only.');
        }
        $this->extension('flarum-tags', 'antoinefr-money', 'ernestdefoe-answers');
        $this->seed();
        $this->app();
        $this->db()->table('tags')->where('id', 1)->update(['answers_enabled' => true]);
        resolve(Question::class)->refreshFlags([1]);
        $this->db()->table('users')->where('id', 2)->update(['money' => 120]);

        $bounty = fn (int $amount) => $this->send($this->request('POST', '/api/answers/discussions/1/bounty', ['authenticatedAs' => 2, 'json' => ['amount' => $amount, 'currency' => 'money']]));
        $this->assertSame(422, $bounty(200)->getStatusCode());
        $this->assertSame(0, (int) $this->db()->table('discussions')->where('id', 1)->value('answers_bounty'), 'a refused bounty claims nothing');
        $this->assertSame(200, $bounty(100)->getStatusCode());
        $this->assertSame(20.0, (float) $this->db()->table('users')->where('id', 2)->value('money'));

        $this->send($this->request('POST', '/api/answers/discussions/1/accept', ['authenticatedAs' => 2, 'json' => ['postId' => 3]]));
        $this->assertSame(100.0, (float) $this->db()->table('users')->where('id', 4)->value('money'));
    }

    #[Test]
    public function answers_held_for_approval_stay_out_of_the_review_queue_until_approved(): void
    {
        $this->extension('flarum-tags', 'flarum-flags', 'flarum-approval', 'ernestdefoe-answers');
        $this->extend((new \Flarum\Extend\ThrottleApi())->remove('postTimeout'));
        $this->seed();
        $this->prepareDatabase(['answers_reputation' => [['user_id' => 3, 'tag_id' => 1, 'points' => 600]]]);
        $this->app();
        $this->db()->table('tags')->where('id', 1)->update(['answers_enabled' => true]);
        resolve(Question::class)->refreshFlags([1]);
        // Members' replies need approval.
        $this->db()->table('group_permission')->where('permission', 'discussion.replyWithoutApproval')->delete();

        $response = $this->send($this->request('POST', '/api/posts', ['authenticatedAs' => 2, 'json' => ['data' => ['type' => 'posts',
            'attributes' => ['content' => 'Buy cheap pills here'], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '1']]]]]]));
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $id = (int) json_decode((string) $response->getBody(), true)['data']['id'];
        $this->assertTrue((bool) $this->db()->table('posts')->where('id', $id)->value('is_private'));
        $this->assertSame(0, $this->db()->table('answers_reviews')->where('post_id', $id)->count(), 'nothing queued while held');

        // Even an item that was queued for a private post never reaches a reviewer.
        $this->db()->table('answers_reviews')->insert(['type' => 'low_quality', 'post_id' => $id, 'discussion_id' => 1, 'user_id' => 2, 'status' => 'open', 'created_at' => Carbon::now()]);
        $queue = json_decode((string) $this->send($this->request('GET', '/api/answers/reviews', ['authenticatedAs' => 3]))->getBody(), true);
        $this->assertSame([], $queue['items']);
        $this->db()->table('answers_reviews')->where('post_id', $id)->delete();

        // Approved: now it's an answer, and it's queued.
        $response = $this->send($this->request('PATCH', '/api/posts/'.$id, ['authenticatedAs' => 1, 'json' => ['data' => ['type' => 'posts', 'id' => (string) $id, 'attributes' => ['isApproved' => true]]]]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertGreaterThan(0, $this->db()->table('answers_reviews')->where('post_id', $id)->count());
        $this->assertSame(3, (int) $this->db()->table('discussions')->where('id', 1)->value('answers_count'));
    }

    #[Test]
    public function a_split_moves_comments_with_their_answer_and_unsolves_the_question(): void
    {
        $this->extension('flarum-tags', 'fof-split', 'ernestdefoe-answers');
        $this->extend((new \Flarum\Extend\ThrottleApi())->remove('postTimeout'));
        $this->seed();
        $this->app();
        $this->db()->table('tags')->where('id', 1)->update(['answers_enabled' => true]);
        resolve(Question::class)->refreshFlags([1]);

        // A comment under answer 3, posted later (so it's outside a split of post 3 alone), then accept 3.
        $response = $this->send($this->request('POST', '/api/posts', ['authenticatedAs' => 2, 'json' => ['data' => ['type' => 'posts',
            'attributes' => ['content' => 'Thanks, that worked', 'answersParentId' => 3], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '1']]]]]]));
        $comment = (int) json_decode((string) $response->getBody(), true)['data']['id'];
        $this->send($this->request('POST', '/api/answers/discussions/1/accept', ['authenticatedAs' => 2, 'json' => ['postId' => 3]]));
        $this->assertSame(Question::SOLVED, (int) $this->db()->table('discussions')->where('id', 1)->value('answers_state'));
        $this->send($this->request('POST', '/api/answers/posts/3/vote', ['authenticatedAs' => 3, 'json' => ['value' => 1]]));
        $this->assertSame(25, (int) $this->db()->table('answers_reputation')->where('user_id', 4)->value('points'));

        $response = $this->send($this->request('POST', '/api/split', ['authenticatedAs' => 1, 'json' => ['title' => 'Split off', 'start_post_id' => 3, 'end_post_number' => 3]]));
        $this->assertLessThan(300, $response->getStatusCode(), (string) $response->getBody());

        $new = (int) $this->db()->table('posts')->where('id', 3)->value('discussion_id');
        $this->assertNotSame(1, $new);
        $this->assertSame($new, (int) $this->db()->table('posts')->where('id', $comment)->value('discussion_id'), 'the comment followed its answer');
        $this->assertNull($this->db()->table('discussions')->where('id', 1)->value('answers_accepted_post_id'));
        $this->assertSame(Question::ANSWERED, (int) $this->db()->table('discussions')->where('id', 1)->value('answers_state'));

        // Un-voting the moved answer takes back what the vote gave (only the vote is left after the unaccept).
        $this->assertSame(10, (int) $this->db()->table('answers_reputation')->where('user_id', 4)->value('points'));
        $this->send($this->request('POST', '/api/answers/posts/3/vote', ['authenticatedAs' => 3, 'json' => ['value' => 0]]));
        $this->assertSame(0, (int) $this->db()->table('answers_reputation')->where('user_id', 4)->value('points'));
        // The original lost the comment's author as a participant (only the asker and answer 2's author are left).
        $this->assertSame(2, (int) $this->db()->table('discussions')->where('id', 1)->value('participant_count'));
    }
}
