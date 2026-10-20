<?php

namespace ErnestDefoe\Onramp\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Onramp\Api\StepInput;
use ErnestDefoe\Onramp\Journeys;
use ErnestDefoe\Onramp\Tracker;
use Flarum\Discussion\Discussion;
use Flarum\Foundation\ValidationException;
use Flarum\Group\Group;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/** The findings of the pre-release security audit, each kept closed. */
class SecurityTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'ernestdefoe-onramp');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'carol', 'email' => 'carol@machine.local', 'is_email_confirmed' => 1, 'avatar_url' => null, 'joined_at' => Carbon::now()],
            ],
            'tags' => [
                ['id' => 9, 'name' => 'Staff Secret Room', 'slug' => 'staff-secret', 'position' => 1, 'is_restricted' => true],
            ],
            Discussion::class => [
                ['id' => 1, 'title' => 'Open', 'created_at' => Carbon::now(), 'user_id' => 2, 'first_post_id' => 1, 'comment_count' => 1, 'last_post_number' => 1],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'created_at' => Carbon::now(), 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>Start</p></t>'],
            ],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'postWithoutThrottle'],
            ],
        ]);
    }

    private function json(string $method, string $path, ?int $actor = null, ?array $body = null): array
    {
        $options = $actor ? ['authenticatedAs' => $actor] : [];
        if ($body !== null) {
            $options['json'] = $body;
        }

        $request = $this->request($method, $path, $options);
        $response = $this->send($actor ? $request : $request->withAttribute('bypassCsrfToken', true));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true), (string) $response->getBody()];
    }

    #[Test]
    public function a_restricted_tags_name_never_reaches_a_member_who_cannot_see_it(): void
    {
        $this->app();
        $this->database()->table('onramp_steps')->insert(['type' => 'discussion', 'config' => json_encode(['tag' => 9]), 'position' => 9, 'is_enabled' => true, 'points' => 0, 'is_required' => false]);
        $this->app()->getContainer()->make(Journeys::class)->start(User::query()->find(3));

        [, , $member] = $this->json('GET', '/api/onramp', 3);
        [, , $own] = $this->json('GET', '/api/users/3', 3);

        $this->assertStringNotContainsString('Staff Secret', $member);
        $this->assertStringNotContainsString('staff-secret', $member);
        $this->assertStringNotContainsString('Staff Secret', $own);
    }

    #[Test]
    public function the_staff_endpoint_does_not_say_which_ids_exist(): void
    {
        [$guestReal] = $this->json('GET', '/api/onramp/members/3');
        [$guestMissing] = $this->json('GET', '/api/onramp/members/999');
        [$memberReal] = $this->json('GET', '/api/onramp/members/3', 2);
        [$memberMissing] = $this->json('GET', '/api/onramp/members/999', 2);
        [$postMissing] = $this->json('POST', '/api/onramp/members/999', 2, ['action' => 'reset']);

        $this->assertSame($guestReal, $guestMissing);
        $this->assertSame(403, $memberReal);
        $this->assertSame(403, $memberMissing);
        $this->assertSame(403, $postMissing);
    }

    #[Test]
    public function an_inner_request_does_not_lose_what_the_outer_one_touched(): void
    {
        $this->app();
        $tracker = $this->app()->getContainer()->make(Tracker::class);
        $carol = User::query()->find(3);
        $this->app()->getContainer()->make(Journeys::class)->start($carol);
        $this->database()->table('posts')->insert(['id' => 2, 'discussion_id' => 1, 'number' => 2, 'created_at' => Carbon::now(), 'user_id' => 3, 'type' => 'comment', 'content' => '<t><p>Mine</p></t>']);

        $tracker->begin($carol);
        $tracker->touch(3, 'reply');
        // An extension calling Flarum's own API client mid-request.
        $tracker->begin($carol);
        $this->assertFalse($tracker->outermost());
        $tracker->end();
        $this->assertTrue($tracker->outermost());

        $mine = $tracker->flush();
        $tracker->end();

        $this->assertNotNull($mine, 'the outer request still flushes what it touched');
        $this->assertSame(['reply'], array_map(fn ($s) => $s->type, $mine['completed']));
    }

    #[Test]
    public function the_rewritten_response_keeps_empty_objects_as_objects(): void
    {
        $this->app();
        $this->app()->getContainer()->make(Journeys::class)->start(User::query()->find(3));

        [$status, , $raw] = $this->json('POST', '/api/posts', 3, ['data' => [
            'type' => 'posts',
            'attributes' => ['content' => 'A reply'],
            'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '1']]],
        ]]);

        $this->assertSame(201, $status);
        $this->assertStringContainsString('"onramp":{"completed"', $raw, 'the response was rewritten');
        $this->assertStringNotContainsString('"relationships":[]', $raw);
        $this->assertStringNotContainsString('"preferences":[]', $raw);
    }

    #[Test]
    public function links_a_browser_would_read_as_another_site_are_refused(): void
    {
        $this->app();
        $input = $this->app()->getContainer()->make(StepInput::class);

        foreach (['/\\evil.com', "/\t/evil.com", "/\n/evil.com", '//evil.com', "https://a\x01b", 'https:evil.com', 'javascript:alert(1)', 'https:///evil'] as $bad) {
            try {
                $input->normalise(['type' => 'reply', 'button_url' => $bad]);
                $this->fail('accepted '.json_encode($bad));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        foreach (['/d/1-welcome', '/p/2-rules', 'https://example.com/guide?a=1#b'] as $good) {
            $this->assertSame($good, $input->normalise(['type' => 'reply', 'button_url' => $good])['button_url']);
        }
    }
}
