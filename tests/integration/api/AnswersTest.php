<?php

namespace ErnestDefoe\Answers\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Answers\Bounties;
use ErnestDefoe\Answers\Question;
use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Post\Post;
use Flarum\Tags\Tag;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use PHPUnit\Framework\Attributes\Test;

class AnswersTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-10 15:00:00', 'UTC'));

        $this->extension('flarum-tags', 'ernestdefoe-answers');
        $this->extend((new \Flarum\Extend\ThrottleApi())->remove('postTimeout'));

        $old = Carbon::now()->subDays(5);
        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'ana', 'email' => 'ana@machine.local', 'is_email_confirmed' => 1, 'joined_at' => $old],
                ['id' => 4, 'username' => 'ben', 'email' => 'ben@machine.local', 'is_email_confirmed' => 1, 'joined_at' => $old],
                ['id' => 5, 'username' => 'mod', 'email' => 'mod@machine.local', 'is_email_confirmed' => 1, 'joined_at' => $old],
                ['id' => 6, 'username' => 'cy', 'email' => 'cy@machine.local', 'is_email_confirmed' => 1, 'joined_at' => $old],
                ['id' => 7, 'username' => 'dee', 'email' => 'dee@machine.local', 'is_email_confirmed' => 1, 'joined_at' => $old],
            ],
            'group_user' => [
                ['user_id' => 5, 'group_id' => Group::MODERATOR_ID],
            ],
            Tag::class => [
                ['id' => 1, 'name' => 'Plugins', 'slug' => 'plugins', 'position' => 0, 'answers_enabled' => true],
                ['id' => 2, 'name' => 'General', 'slug' => 'general', 'position' => 1, 'answers_enabled' => false],
            ],
            Discussion::class => [
                ['id' => 1, 'title' => 'Migration fails on PostgreSQL after a rename', 'user_id' => 2, 'created_at' => $old, 'last_posted_at' => $old, 'first_post_id' => 1, 'comment_count' => 3, 'last_post_number' => 3, 'last_posted_user_id' => 4, 'is_private' => false],
                ['id' => 2, 'title' => 'Hello there everyone at the forum', 'user_id' => 2, 'created_at' => $old, 'last_posted_at' => $old, 'first_post_id' => 4, 'comment_count' => 2, 'last_post_number' => 2, 'is_private' => false],
                ['id' => 3, 'title' => 'Migration fails on PostgreSQL once again', 'user_id' => 4, 'created_at' => $old, 'last_posted_at' => $old, 'first_post_id' => 6, 'comment_count' => 1, 'last_post_number' => 1, 'last_posted_user_id' => 4, 'is_private' => false],
            ],
            'discussion_tag' => [
                ['discussion_id' => 1, 'tag_id' => 1],
                ['discussion_id' => 2, 'tag_id' => 2],
                ['discussion_id' => 3, 'tag_id' => 1],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>My migration renames a column and the index migration fails.</p></t>', 'created_at' => $old, 'is_private' => false],
                ['id' => 2, 'discussion_id' => 1, 'number' => 2, 'user_id' => 3, 'type' => 'comment', 'content' => '<t><p>Do both changes in one migration so the rename is visible.</p></t>', 'created_at' => $old, 'is_private' => false],
                ['id' => 3, 'discussion_id' => 1, 'number' => 3, 'user_id' => 4, 'type' => 'comment', 'content' => '<t><p>Read the schema again in the second migration before indexing.</p></t>', 'created_at' => $old, 'is_private' => false],
                ['id' => 4, 'discussion_id' => 2, 'number' => 1, 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>Hi all</p></t>', 'created_at' => $old, 'is_private' => false],
                ['id' => 5, 'discussion_id' => 2, 'number' => 2, 'user_id' => 3, 'type' => 'comment', 'content' => '<t><p>Welcome</p></t>', 'created_at' => $old, 'is_private' => false],
                ['id' => 6, 'discussion_id' => 3, 'number' => 1, 'user_id' => 4, 'type' => 'comment', 'content' => '<t><p>Same thing, renaming then indexing fails.</p></t>', 'created_at' => $old, 'is_private' => false],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Boot the app and let Answers read the fixtures' tags (fixtures skip every event). */
    private function boot(): void
    {
        $this->app();
        resolve(Question::class)->refreshFlags([1, 2, 3]);
    }

    private function db(): ConnectionInterface
    {
        return resolve(ConnectionInterface::class);
    }

    /** @return array{int, mixed} */
    private function api(string $method, string $path, ?int $as, array $body = [], array $query = []): array
    {
        $options = $as ? ['authenticatedAs' => $as] : [];
        if ($method !== 'GET') {
            $options['json'] = $body;
        }
        $request = $this->request($method, '/api'.$path, $options);
        if ($query) {
            $request = $request->withQueryParams($query);
        }
        $response = $this->send($request);

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    private function reply(int $as, int $discussion, string $content, ?int $parent = null): array
    {
        $attributes = ['content' => $content];
        if ($parent) {
            $attributes['answersParentId'] = $parent;
        }

        return $this->api('POST', '/posts', $as, ['data' => ['type' => 'posts', 'attributes' => $attributes,
            'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => (string) $discussion]]]]]);
    }

    private function rep(int $user, int $tag = 1): int
    {
        return (int) $this->db()->table('answers_reputation')->where('user_id', $user)->where('tag_id', $tag)->value('points');
    }

    private function discussion(int $id): object
    {
        return $this->db()->table('discussions')->where('id', $id)->first();
    }

    #[Test]
    public function questions_are_counted_and_comments_are_not_answers(): void
    {
        $this->boot();
        $d = $this->discussion(1);
        $this->assertTrue((bool) $d->answers_question);
        $this->assertSame(2, (int) $d->answers_count);
        $this->assertSame(Question::ANSWERED, (int) $d->answers_state);
        $this->assertFalse((bool) $this->discussion(2)->answers_question);
        $this->assertSame(Question::UNANSWERED, (int) $this->discussion(3)->answers_state);

        [$status, $body] = $this->reply(6, 1, 'Which Flarum version are you on? It matters here.', 2);
        $this->assertSame(201, $status, json_encode($body));
        $this->assertSame(2, $body['data']['attributes']['answersParentId']);
        $this->assertSame(2, (int) $this->discussion(1)->answers_count);

        [$status] = $this->reply(6, 1, 'A third answer that is long enough to not be flagged at all.');
        $this->assertSame(201, $status);
        $this->assertSame(3, (int) $this->discussion(1)->answers_count);

        // A parent from another discussion, or a comment as a parent, is refused.
        [$status] = $this->reply(6, 1, 'Nope', 5);
        $this->assertSame(422, $status);
        $comment = (int) $this->db()->table('posts')->whereNotNull('answers_parent_id')->value('id');
        [$status] = $this->reply(6, 1, 'Nope', $comment);
        $this->assertSame(422, $status);
        // Comments have a length cap.
        [$status] = $this->reply(6, 1, str_repeat('long ', 200), 2);
        $this->assertSame(422, $status);

        // A plain discussion ignores the field.
        [$status, $body] = $this->reply(6, 2, 'Hi from a normal thread, with a parent that means nothing', 4);
        $this->assertSame(201, $status);
        $this->assertNull($this->db()->table('posts')->where('id', $body['data']['id'])->value('answers_parent_id'));

        // Hiding an answer takes it out of the count.
        [$status] = $this->api('PATCH', '/posts/3', 1, ['data' => ['type' => 'posts', 'id' => '3', 'attributes' => ['isHidden' => true]]]);
        $this->assertSame(200, $status);
        $this->assertSame(2, (int) $this->discussion(1)->answers_count);
    }

    #[Test]
    public function a_new_discussion_in_a_qa_tag_is_a_question(): void
    {
        $this->boot();
        [$status, $body] = $this->api('POST', '/discussions', 4, ['data' => ['type' => 'discussions',
            'attributes' => ['title' => 'How do I add a settings page?', 'content' => 'I tried the registry and nothing showed up.'],
            'relationships' => ['tags' => ['data' => [['type' => 'tags', 'id' => '1']]]]]]);
        $this->assertSame(201, $status, json_encode($body));
        $this->assertTrue($body['data']['attributes']['answersQuestion']);
        $this->assertSame(0, (int) $this->discussion((int) $body['data']['id'])->answers_count);

        // Moving it out of the tag makes it a discussion again.
        $id = $body['data']['id'];
        [$status] = $this->api('PATCH', '/discussions/'.$id, 1, ['data' => ['type' => 'discussions', 'id' => $id,
            'relationships' => ['tags' => ['data' => [['type' => 'tags', 'id' => '2']]]]]]);
        $this->assertSame(200, $status);
        $this->assertFalse((bool) $this->discussion((int) $id)->answers_question);
    }

    #[Test]
    public function votes_move_the_score_and_reputation_per_tag(): void
    {
        $this->boot();
        [$status, $body] = $this->api('POST', '/answers/posts/2/vote', 4, ['value' => 1]);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame(1, $body['score']);
        $this->assertSame(10, $this->rep(3));

        // Changing to a downvote takes the +10 back, then costs the author 2 and the voter 1.
        [, $body] = $this->api('POST', '/answers/posts/2/vote', 4, ['value' => -1]);
        $this->assertSame(-1, $body['score']);
        $this->assertSame(-2, $this->rep(3));
        $this->assertSame(-1, $this->rep(4));

        // Taking it back leaves nothing, in the totals or the history.
        $this->api('POST', '/answers/posts/2/vote', 4, ['value' => 0]);
        $this->assertSame(0, $this->rep(3));
        $this->assertSame(0, $this->rep(4));
        $this->assertSame(0, $this->db()->table('answers_reputation_log')->count());

        // Question upvotes are worth 5, and mirror onto the discussion.
        $this->api('POST', '/answers/posts/1/vote', 3, ['value' => 1]);
        $this->assertSame(5, $this->rep(2));
        $this->assertSame(1, (int) $this->discussion(1)->answers_score);

        // Never your own post, never a guest, never a downvote on a comment.
        [$status] = $this->api('POST', '/answers/posts/2/vote', 3, ['value' => 1]);
        $this->assertSame(403, $status);
        [$status] = $this->api('POST', '/answers/posts/2/vote', null, ['value' => 1]);
        $this->assertContains($status, [400, 401, 403]);
        [, $body] = $this->reply(6, 1, 'A comment under the answer, short.', 2);
        [$status] = $this->api('POST', '/answers/posts/'.$body['data']['id'].'/vote', 4, ['value' => -1]);
        $this->assertSame(422, $status);
        [$status] = $this->api('POST', '/answers/posts/'.$body['data']['id'].'/vote', 4, ['value' => 1]);
        $this->assertSame(200, $status);
        $this->assertSame(0, $this->rep(6));

        // Not in a question: not found.
        [$status] = $this->api('POST', '/answers/posts/5/vote', 4, ['value' => 1]);
        $this->assertSame(404, $status);
    }

    #[Test]
    public function the_asker_accepts_an_answer(): void
    {
        $this->boot();
        [$status] = $this->api('POST', '/answers/discussions/1/accept', 4, ['postId' => 2]);
        $this->assertSame(403, $status);
        [$status] = $this->api('POST', '/answers/discussions/1/accept', 2, ['postId' => 1]);
        $this->assertSame(422, $status);

        [$status, $body] = $this->api('POST', '/answers/discussions/1/accept', 2, ['postId' => 2]);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame(Question::SOLVED, $body['state']);
        $this->assertSame(15, $this->rep(3));
        $this->assertSame(2, $this->rep(2));
        $this->assertSame(1, (int) $this->db()->table('users')->where('id', 3)->value('answers_accepted_count'));
        $this->assertSame(1, (int) $this->db()->table('users')->where('id', 2)->value('answers_solved_count'));
        $this->assertSame(1, $this->db()->table('notifications')->where('type', 'answersAccepted')->where('user_id', 3)->count());

        // Switching to another answer moves the points; un-accepting takes them all back.
        $this->api('POST', '/answers/discussions/1/accept', 2, ['postId' => 3]);
        $this->assertSame(0, $this->rep(3));
        $this->assertSame(15, $this->rep(4));
        $this->assertSame(1, (int) $this->db()->table('users')->where('id', 2)->value('answers_solved_count'));
        $this->api('POST', '/answers/discussions/1/accept', 2, ['postId' => null]);
        $this->assertSame(0, $this->rep(4));
        $this->assertSame(0, $this->rep(2));
        $this->assertSame(0, (int) $this->db()->table('users')->where('id', 2)->value('answers_solved_count'));
        $this->assertSame(Question::ANSWERED, (int) $this->discussion(1)->answers_state);

        // A moderator may accept too; deleting the accepted answer un-solves the question.
        $this->api('POST', '/answers/discussions/1/accept', 5, ['postId' => 3]);
        $this->assertSame(Question::SOLVED, (int) $this->discussion(1)->answers_state);
        [$status] = $this->api('DELETE', '/posts/3', 1);
        $this->assertSame(204, $status);
        $this->assertSame(Question::ANSWERED, (int) $this->discussion(1)->answers_state);
        $this->assertSame(0, $this->rep(4));
        $this->assertSame(1, (int) $this->discussion(1)->answers_count);
    }

    #[Test]
    public function bounties_pay_the_accepted_answer_or_half_to_the_top_answer(): void
    {
        $this->boot();
        $this->db()->table('answers_reputation')->insert(['user_id' => 2, 'tag_id' => 1, 'points' => 120]);

        [$status] = $this->api('POST', '/answers/discussions/1/bounty', 2, ['amount' => 200, 'currency' => 'rep']);
        $this->assertSame(422, $status, 'not enough reputation');
        [$status] = $this->api('POST', '/answers/discussions/1/bounty', 2, ['amount' => 75, 'currency' => 'rep']);
        $this->assertSame(422, $status, 'not an offered amount');
        [$status, $body] = $this->api('POST', '/answers/discussions/1/bounty', 2, ['amount' => 100, 'currency' => 'rep']);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame(20, $this->rep(2));
        $this->assertSame(100, (int) $this->discussion(1)->answers_bounty);
        [$status] = $this->api('POST', '/answers/discussions/1/bounty', 2, ['amount' => 50, 'currency' => 'rep']);
        $this->assertSame(422, $status, 'one at a time');

        [, $list] = $this->api('GET', '/discussions', 4, [], ['filter' => ['answers' => 'bounty']]);
        $this->assertSame(['1'], array_column($list['data'], 'id'));

        // Accepting pays in full (plus the usual 15).
        $this->api('POST', '/answers/discussions/1/accept', 2, ['postId' => 3]);
        $this->assertSame(115, $this->rep(4));
        $this->assertSame(0, (int) $this->discussion(1)->answers_bounty);
        $this->assertSame('awarded', $this->db()->table('answers_bounties')->value('status'));

        // Discussion 3: time runs out. Only answers posted during the bounty with 2+ votes qualify; half is paid.
        $this->db()->table('answers_reputation')->where('user_id', 4)->update(['points' => 500]);
        [$status] = $this->api('POST', '/answers/discussions/3/bounty', 4, ['amount' => 100, 'currency' => 'rep']);
        $this->assertSame(200, $status);
        [, $a] = $this->reply(3, 3, 'Answer posted during the bounty, long enough to stay out of review.');
        [, $b] = $this->reply(6, 3, 'Another answer posted during the bounty, also long enough here.');
        $this->api('POST', '/answers/posts/'.$a['data']['id'].'/vote', 2, ['value' => 1]);
        $this->api('POST', '/answers/posts/'.$a['data']['id'].'/vote', 7, ['value' => 1]);
        $this->api('POST', '/answers/posts/'.$b['data']['id'].'/vote', 2, ['value' => 1]);

        Carbon::setTestNow(Carbon::now()->addDays(8));
        $before = $this->rep(3);
        $this->assertSame(1, resolve(Bounties::class)->expire());
        $this->assertSame($before + 50, $this->rep(3));
        $this->assertSame(0, (int) $this->discussion(3)->answers_bounty);
    }

    #[Test]
    public function an_unclaimed_bounty_is_lost(): void
    {
        $this->boot();
        $this->db()->table('answers_reputation')->insert(['user_id' => 2, 'tag_id' => 1, 'points' => 100]);
        $this->api('POST', '/answers/discussions/1/bounty', 2, ['amount' => 50, 'currency' => 'rep']);
        Carbon::setTestNow(Carbon::now()->addDays(8));
        resolve(Bounties::class)->expire();
        $this->assertSame('expired', $this->db()->table('answers_bounties')->value('status'));
        $this->assertSame(50, $this->rep(2));
    }

    #[Test]
    public function three_trusted_votes_close_a_duplicate_and_a_moderator_reopens_it(): void
    {
        $this->boot();
        foreach ([3, 6, 7] as $user) {
            $this->db()->table('answers_reputation')->insert(['user_id' => $user, 'tag_id' => 1, 'points' => 600]);
        }
        [$status] = $this->api('POST', '/answers/discussions/3/close', 4, ['duplicateOf' => 1]);
        $this->assertSame(403, $status, 'no reputation');

        [, $body] = $this->api('POST', '/answers/discussions/3/close', 3, ['duplicateOf' => 1]);
        $this->assertSame(1, $body['votes']);
        $this->api('POST', '/answers/discussions/3/close', 6, ['duplicateOf' => 1]);
        [, $body] = $this->api('POST', '/answers/discussions/3/close', 7, ['duplicateOf' => 1]);
        $this->assertSame(1, $body['duplicateOfId']);
        $this->assertSame(Question::DUPLICATE, (int) $this->discussion(3)->answers_state);

        // No new answers on a closed duplicate; comments still work.
        [$status] = $this->reply(3, 3, 'An answer to a closed question should be refused here.');
        $this->assertSame(422, $status);
        [$status] = $this->reply(3, 3, 'Comment still fine.', 6);
        $this->assertSame(201, $status);

        [, $list] = $this->api('GET', '/discussions', 4, [], ['filter' => ['answersDuplicateOf' => '1']]);
        $this->assertSame(['3'], array_column($list['data'], 'id'));

        [$status] = $this->api('DELETE', '/answers/discussions/3/close', 3);
        $this->assertSame(403, $status);
        [$status] = $this->api('DELETE', '/answers/discussions/3/close', 5);
        $this->assertSame(200, $status);
        $this->assertSame(Question::UNANSWERED, (int) $this->discussion(3)->answers_state);

        // A moderator closes in one go.
        [, $body] = $this->api('POST', '/answers/discussions/3/close', 5, ['duplicateOf' => 1]);
        $this->assertSame(1, $body['duplicateOfId']);
    }

    #[Test]
    public function trusted_members_edit_answers_with_full_history(): void
    {
        $this->boot();
        $edit = fn (int $as, int $post, string $content) => $this->api('PATCH', '/posts/'.$post, $as, ['data' => ['type' => 'posts', 'id' => (string) $post, 'attributes' => ['content' => $content]]]);

        [$status] = $edit(6, 2, 'Improved without the reputation.');
        $this->assertSame(403, $status);

        $this->db()->table('answers_reputation')->insert(['user_id' => 6, 'tag_id' => 1, 'points' => 1200]);
        [$status, $body] = $edit(6, 2, 'Do both changes in one migration so the rename is visible. Also: let Laravel name the index.');
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame(2, $body['data']['attributes']['answersRevisions']);

        // Never the question or a comment, even with the reputation.
        [$status] = $edit(6, 1, 'Edited question');
        $this->assertSame(403, $status);

        [$status, $body] = $this->api('GET', '/answers/posts/2/revisions', 4);
        $this->assertSame(200, $status);
        $this->assertSame([], $body['revisions'], 'history is for the author, trusted editors and moderators');
        [$status, $body] = $this->api('GET', '/answers/posts/2/revisions', 3);
        $this->assertCount(2, $body['revisions'], 'the author sees it');
        [$status, $body] = $this->api('GET', '/answers/posts/2/revisions', 6);
        $this->assertSame('ana', $body['revisions'][0]['user']['username']);
        $this->assertSame('cy', $body['revisions'][1]['user']['username'], 'and so does a trusted editor');
        [, $body] = $this->api('GET', '/answers/posts/2/revisions', 4);
        $this->assertNotNull($body['source'], 'a member who may suggest an edit starts from the source');
        [$status] = $this->api('GET', '/answers/similar', null, [], ['q' => 'Migration fails on PostgreSQL']);
        $this->assertSame(401, $status, 'similar questions are for members writing one');
        [, $body] = $this->api('GET', '/answers/posts/2/revisions', null);
        $this->assertNull($body['source'], 'guests get no source');

        // Roll back to the original: a third revision.
        [$status, $body] = $this->api('POST', '/answers/posts/2/revisions/'.$this->db()->table('answers_revisions')->min('id').'/restore', 6);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame(3, $body['revisions']);
        $this->assertStringContainsString('rename is visible.</t>', (string) $this->db()->table('posts')->where('id', 2)->value('content'));
        $this->assertStringNotContainsString('index', (string) $this->db()->table('posts')->where('id', 2)->value('content'));
    }

    #[Test]
    public function suggested_edits_go_through_review(): void
    {
        $this->boot();
        [$status, $body] = $this->api('GET', '/answers/posts/2/revisions', 4);
        $this->assertSame(200, $status);
        $this->assertStringContainsString('Do both changes', $body['source']);

        [$status] = $this->api('POST', '/answers/posts/2/suggest', 4, ['content' => $body['source'].' Flarum runs each migration on its own.', 'summary' => 'why']);
        $this->assertSame(201, $status);
        [$status] = $this->api('POST', '/answers/posts/2/suggest', 4, ['content' => 'again']);
        $this->assertSame(422, $status, 'one open suggestion each');

        foreach ([6, 7] as $user) {
            $this->db()->table('answers_reputation')->insert(['user_id' => $user, 'tag_id' => 1, 'points' => 600]);
        }
        [$status, $body] = $this->api('GET', '/answers/reviews', 4);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame([], $body['items'], 'no reputation, nothing to review');

        [, $queue] = $this->api('GET', '/answers/reviews', 6, [], ['type' => 'suggested_edit']);
        $this->assertCount(1, $queue['items']);
        $item = $queue['items'][0];
        $this->assertSame('ben', $item['suggester']['username']);

        [, $body] = $this->api('POST', '/answers/reviews/'.$item['id'], 6, ['verdict' => 'approve']);
        $this->assertSame('open', $body['status']);
        [, $body] = $this->api('POST', '/answers/reviews/'.$item['id'], 7, ['verdict' => 'approve']);
        $this->assertSame('approved', $body['status']);

        $this->assertStringContainsString('on its own', (string) $this->db()->table('posts')->where('id', 2)->value('content'));
        $last = $this->db()->table('answers_revisions')->orderByDesc('id')->first();
        $this->assertSame(4, (int) $last->user_id);
        $this->assertSame('why', $last->summary);

        // The suggester can't judge their own suggestion, the author can't judge their own post.
        // Dee suggests an edit to Ben's answer: neither may judge it; Ana may.
        $this->db()->table('answers_reputation')->insert(['user_id' => 3, 'tag_id' => 1, 'points' => 600]);
        $this->db()->table('answers_reputation')->insert(['user_id' => 4, 'tag_id' => 1, 'points' => 600]);
        [$status] = $this->api('POST', '/answers/posts/3/suggest', 7, ['content' => 'Read the schema again before indexing, please.']);
        $this->assertSame(201, $status);
        foreach ([4, 7] as $user) {
            [, $queue] = $this->api('GET', '/answers/reviews', $user);
            $this->assertSame([], $queue['items'], 'not their own post or suggestion');
        }
        [, $queue] = $this->api('GET', '/answers/reviews', 3, [], ['type' => 'suggested_edit']);
        $this->assertCount(1, $queue['items']);
        $id = $queue['items'][0]['id'];
        [$status] = $this->api('POST', '/answers/reviews/'.$id, 4, ['verdict' => 'approve']);
        $this->assertContains($status, [403, 404]);
    }

    #[Test]
    public function first_and_short_answers_are_queued_for_review(): void
    {
        $this->boot();
        $this->reply(7, 1, 'Use one migration.');
        $types = $this->db()->table('answers_reviews')->where('user_id', 7)->pluck('type')->sort()->values()->all();
        $this->assertSame(['first_answer', 'low_quality'], $types);

        // A second answer by the same member is not a first answer.
        $this->reply(7, 3, 'A second answer that is long enough to pass the length rule easily.');
        $this->assertSame(1, $this->db()->table('answers_reviews')->where('user_id', 7)->where('type', 'first_answer')->count());

        // A moderator settles an item alone; hiding hides the answer.
        $item = $this->db()->table('answers_reviews')->where('type', 'low_quality')->first();
        [, $body] = $this->api('POST', '/answers/reviews/'.$item->id, 5, ['verdict' => 'hide']);
        $this->assertSame('hidden', $body['status']);
        $this->assertNotNull($this->db()->table('posts')->where('id', $item->post_id)->value('hidden_at'));
        $this->assertSame(2, (int) $this->discussion(1)->answers_count, 'the hidden answer left the count');
    }

    #[Test]
    public function lists_filter_by_state_and_threads_sort_answers_by_votes(): void
    {
        $this->boot();
        $this->api('POST', '/answers/posts/3/vote', 3, ['value' => 1]);
        $this->reply(6, 1, 'A comment under answer two.', 2);

        $ids = fn (string $state) => array_column($this->api('GET', '/discussions', 4, [], ['filter' => ['answers' => $state]])[1]['data'], 'id');
        $this->assertSame(['3'], $ids('unanswered'));
        $this->assertSame(['1'], $ids('answered'));
        $this->assertEqualsCanonicalizing(['1', '3'], $ids('question'));
        $this->assertSame([], $ids('solved'));

        [$status, $body] = $this->api('GET', '/posts', 4, [], ['filter' => ['discussion' => '1', 'answers' => 'top'], 'sort' => '-answersScore,createdAt', 'include' => 'answersComments,answersComments.user']);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame(['3', '2'], array_column($body['data'], 'id'));
        $this->assertSame(1, $body['data'][0]['attributes']['answersScore']);
        $this->assertSame(0, $body['data'][0]['attributes']['answersVote']);
        $this->assertSame(10, $body['data'][0]['attributes']['answersAuthorRep']);
        $this->assertCount(1, $body['data'][1]['relationships']['answersComments']['data']);

        // A plain discussion carries none of it.
        [, $body] = $this->api('GET', '/discussions/2', 4);
        $this->assertArrayNotHasKey('answersQuestion', $body['data']['attributes']);
    }

    #[Test]
    public function similar_solved_questions_are_suggested_while_asking(): void
    {
        $this->boot();
        $this->api('POST', '/answers/discussions/1/accept', 2, ['postId' => 2]);
        [$status, $body] = $this->api('GET', '/answers/similar', 4, [], ['q' => 'Migration fails on PostgreSQL']);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame([1], array_column($body['data'], 'id'), 'only solved questions');
    }

    #[Test]
    public function an_admin_switches_a_tag_to_qa(): void
    {
        $this->boot();
        [$status] = $this->api('PATCH', '/tags/2', 4, ['data' => ['type' => 'tags', 'id' => '2', 'attributes' => ['answersEnabled' => true]]]);
        $this->assertContains($status, [403, 422, 400]);
        [$status, $body] = $this->api('PATCH', '/tags/2', 1, ['data' => ['type' => 'tags', 'id' => '2', 'attributes' => ['answersEnabled' => true]]]);
        $this->assertSame(200, $status, json_encode($body));
        $d = $this->discussion(2);
        $this->assertTrue((bool) $d->answers_question);
        $this->assertSame(1, (int) $d->answers_count);
    }

    #[Test]
    public function the_top_helpers_and_a_members_reputation(): void
    {
        $this->boot();
        $this->api('POST', '/answers/posts/2/vote', 4, ['value' => 1]);
        $this->api('POST', '/answers/discussions/1/accept', 2, ['postId' => 2]);

        [$status, $body] = $this->api('GET', '/answers/helpers', null, [], ['tag' => 'plugins']);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame('ana', $body['data'][0]['username']);
        $this->assertSame(25, $body['data'][0]['points']);

        [$status, $body] = $this->api('GET', '/answers/users/3/reputation', 4);
        $this->assertSame(200, $status);
        $this->assertSame(25, $body['tags'][0]['points']);
        $this->assertSame(['accepted', 'upvote'], array_column($body['log'], 'reason'));
    }

    #[Test]
    public function deleting_a_voter_takes_their_votes_off(): void
    {
        $this->boot();
        $this->api('POST', '/answers/posts/2/vote', 6, ['value' => 1]);
        $this->api('POST', '/answers/posts/1/vote', 6, ['value' => 1]);
        $this->assertSame(10, $this->rep(3));
        [$status] = $this->api('DELETE', '/users/6', 1);
        $this->assertSame(204, $status);
        $this->assertSame(0, (int) $this->db()->table('posts')->where('id', 2)->value('answers_score'));
        $this->assertSame(0, (int) $this->discussion(1)->answers_score);
        $this->assertSame(0, $this->rep(3));
    }

    #[Test]
    public function lists_and_threads_cost_the_same_for_3_or_15_items(): void
    {
        $this->boot();
        $count = function (callable $fn): int {
            $db = $this->db();
            $db->flushQueryLog();
            $db->enableQueryLog();
            $fn();
            $log = array_values(array_filter($db->getQueryLog(), fn ($q) => ! str_contains($q['query'], 'access_tokens')));
            if (getenv('QLOG')) {
                fwrite(STDERR, "\n---\n".implode("\n", array_column($log, 'query')));
            }
            $n = count($log);
            $db->disableQueryLog();

            return $n;
        };

        $thread = fn () => $this->api('GET', '/posts', 4, [], ['filter' => ['discussion' => '1', 'answers' => 'top'], 'sort' => '-answersScore', 'include' => 'user,answersComments,answersComments.user']);
        $list = fn () => $this->api('GET', '/discussions', 4, [], ['filter' => ['answers' => 'question'], 'include' => 'user,tags']);
        $before = [$count($thread), $count($list)];

        for ($i = 0; $i < 12; $i++) {
            // Same people as before, so only the number of items changes.
            [, $body] = $this->reply(4, 1, 'Another answer number '.$i.' that is long enough to skip review.');
            $this->reply(4, 1, 'Comment on '.$i, (int) $body['data']['id']);
            $this->api('POST', '/answers/posts/'.$body['data']['id'].'/vote', 3, ['value' => 1]);
            $this->api('POST', '/discussions', 4, ['data' => ['type' => 'discussions', 'attributes' => ['title' => 'Question number '.$i.' here', 'content' => 'Body of question '.$i],
                'relationships' => ['tags' => ['data' => [['type' => 'tags', 'id' => '1']]]]]]);
        }

        $this->assertSame($before, [$count($thread), $count($list)]);
    }

    private function driver(): string
    {
        return $this->db()->getDriverName();
    }

    #[Test]
    public function a_vote_locks_its_post_and_reads_the_old_vote_under_the_lock(): void
    {
        $this->boot();
        if ($this->driver() === 'sqlite') {
            $this->markTestSkipped('SQLite has no row locks (it serialises writers instead).');
        }
        $this->api('POST', '/answers/posts/2/vote', 4, ['value' => -1]);
        $this->db()->enableQueryLog();
        $this->api('POST', '/answers/posts/2/vote', 4, ['value' => 1]);
        $log = array_column($this->db()->getQueryLog(), 'query');

        // The post row is locked before the old vote is read, and the reputation writes come after both.
        $lock = array_keys(array_filter($log, fn ($q) => str_contains($q, 'answers_score') && stripos($q, 'for update') !== false));
        $read = array_keys(array_filter($log, fn ($q) => str_starts_with($q, 'select') && str_contains($q, 'answers_votes')));
        $rep = array_keys(array_filter($log, fn ($q) => str_contains($q, 'answers_reputation')));
        $this->assertCount(1, $lock);
        $this->assertLessThan($read[0], $lock[0]);
        $this->assertLessThan(min($rep), $read[0]);
        $this->assertSame(10, $this->rep(3));
        $this->assertSame(0, $this->rep(4), 'the voter\'s downvote cost came back when the vote changed');
    }

    #[Test]
    public function a_bounty_is_paid_once_even_when_two_payers_read_it_open(): void
    {
        $this->boot();
        $this->db()->table('answers_reputation')->insert(['user_id' => 2, 'tag_id' => 1, 'points' => 200]);
        $this->api('POST', '/answers/discussions/1/bounty', 2, ['amount' => 100, 'currency' => 'rep']);
        $bounty = $this->db()->table('answers_bounties')->first();
        $post = Post::query()->find(3);

        // Two payers (an accept and the expiry run, or two accepts) holding the same open bounty.
        $award = new \ReflectionMethod(Bounties::class, 'award');
        $award->invoke(resolve(Bounties::class), $bounty, $post, 100);
        $award->invoke(resolve(Bounties::class), $bounty, $post, 100);

        $this->assertSame(100, $this->rep(4), 'paid once');
        $this->assertSame(1, $this->db()->table('answers_reputation_log')->where('reason', 'bounty')->count());
    }

    #[Test]
    public function an_accept_reads_the_current_acceptance_under_a_lock_not_from_a_stale_copy(): void
    {
        $this->boot();
        // A second request still holding the question as it was before the first accept.
        $stale = Discussion::query()->with('tags')->find(1);
        $this->api('POST', '/answers/discussions/1/accept', 2, ['postId' => 2]);

        resolve(\ErnestDefoe\Answers\Accept::class)->set($stale, Post::query()->find(3), User::query()->find(2));

        $this->assertSame(0, $this->rep(3), 'the first acceptance was taken back');
        $this->assertSame(15, $this->rep(4));
        $this->assertSame(0, (int) $this->db()->table('users')->where('id', 3)->value('answers_accepted_count'));
        $this->assertSame(1, (int) $this->db()->table('users')->where('id', 2)->value('answers_solved_count'));
    }
}
