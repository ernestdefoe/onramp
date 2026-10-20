<?php

namespace ErnestDefoe\Onramp\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Onramp\Journeys;
use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

class JourneyTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-likes', 'ernestdefoe-onramp');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'carol', 'email' => 'carol@machine.local', 'is_email_confirmed' => 1, 'joined_at' => Carbon::now()->subDays(2), 'avatar_url' => null],
                ['id' => 4, 'username' => 'dave', 'email' => 'dave@machine.local', 'is_email_confirmed' => 1, 'joined_at' => Carbon::now()->subYears(2), 'avatar_url' => null],
            ],
            Discussion::class => [
                ['id' => 1, 'title' => 'Open', 'created_at' => Carbon::now(), 'user_id' => 4, 'first_post_id' => 1, 'comment_count' => 1, 'last_post_number' => 1],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'created_at' => Carbon::now(), 'user_id' => 4, 'type' => 'comment', 'content' => '<t><p>Start</p></t>'],
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

        // Guests have no session to carry a CSRF token; the route under test
        // is what decides, not the token check in front of it.
        $response = $this->send($actor ? $request : $request->withAttribute('bypassCsrfToken', true));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    private function reply(int $actor): array
    {
        [$status, $body] = $this->json('POST', '/api/posts', $actor, ['data' => [
            'type' => 'posts',
            'attributes' => ['content' => 'A reply'],
            'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '1']]],
        ]]);
        $this->assertSame(201, $status, json_encode($body));

        return $body;
    }

    private function user(int $id): User
    {
        $this->app();

        return User::query()->findOrFail($id);
    }

    private function journeys(): Journeys
    {
        return $this->app()->getContainer()->make(Journeys::class);
    }

    private function step(string $type): int
    {
        return (int) $this->database()->table('onramp_steps')->where('type', $type)->value('id');
    }

    #[Test]
    public function the_seeded_journey_is_there_on_install(): void
    {
        $this->app();

        $this->assertSame(
            ['avatar', 'read', 'like_given', 'reply', 'discussion'],
            $this->database()->table('onramp_steps')->orderBy('position')->pluck('type')->all()
        );
    }

    #[Test]
    public function a_member_without_a_journey_costs_nothing_and_sees_nothing(): void
    {
        [$status, $body] = $this->json('GET', '/api/users/2', 2);

        $this->assertSame(200, $status);
        $this->assertArrayHasKey('onramp', $body['data']['attributes']);
        $this->assertNull($body['data']['attributes']['onramp']);
    }

    #[Test]
    public function a_reply_completes_the_reply_step_and_the_response_says_so(): void
    {
        $user = $this->user(3);
        $this->journeys()->start($user);

        $body = $this->reply(3);

        $reply = $this->step('reply');
        $this->assertNotNull($this->database()->table('onramp_progress')->where(['user_id' => 3, 'step_id' => $reply])->value('completed_at'));
        $this->assertSame([$reply], $body['meta']['onramp']['completed']);
        $this->assertFalse($body['meta']['onramp']['graduated']);
        $this->assertSame('open', $body['meta']['onramp']['state']['status']);
        $this->assertSame(1, $body['meta']['onramp']['state']['done']);
    }

    #[Test]
    public function another_member_replying_rewrites_nothing(): void
    {
        $this->journeys()->start($this->user(3));

        // Dave has no journey: his reply is an ordinary response.
        $body = $this->reply(4);

        $this->assertArrayNotHasKey('onramp', $body['meta'] ?? []);
    }

    #[Test]
    public function the_journey_rides_on_the_members_own_user_only(): void
    {
        $this->journeys()->start($this->user(3));

        [, $own] = $this->json('GET', '/api/users/3', 3);
        [, $other] = $this->json('GET', '/api/users/3', 4);
        [, $guest] = $this->json('GET', '/api/users/3');

        $this->assertSame('open', $own['data']['attributes']['onramp']['status']);
        $this->assertCount(5, $own['data']['attributes']['onramp']['steps']);
        $this->assertArrayNotHasKey('onramp', $other['data']['attributes'] ?? []);
        $this->assertArrayNotHasKey('onramp', $guest['data']['attributes'] ?? []);
    }

    #[Test]
    public function starting_ticks_what_the_member_already_did(): void
    {
        // Carol replied before Onramp existed.
        $this->database()->table('posts')->insert(['id' => 2, 'discussion_id' => 1, 'number' => 2, 'created_at' => Carbon::now(), 'user_id' => 3, 'type' => 'comment', 'content' => '<t><p>Old</p></t>']);

        $this->journeys()->start($this->user(3), Journeys::SILENT);

        $this->assertNotNull($this->database()->table('onramp_progress')->where(['user_id' => 3, 'step_id' => $this->step('reply')])->value('completed_at'));
        // Silent: no alert for it.
        $this->assertSame(0, $this->database()->table('notifications')->where('user_id', 3)->count());
    }

    #[Test]
    public function a_step_is_paid_once_even_if_the_post_is_deleted_and_made_again(): void
    {
        $this->journeys()->start($this->user(3));
        $first = $this->reply(3);
        $this->database()->table('posts')->where('id', $first['data']['id'])->delete();

        $second = $this->reply(3);

        $this->assertArrayNotHasKey('onramp', $second['meta'] ?? []);
        $this->assertSame(1, $this->database()->table('onramp_progress')->where('user_id', 3)->whereNotNull('completed_at')->count(), json_encode($this->database()->table('onramp_progress')->get()));
    }

    #[Test]
    public function the_member_can_skip_an_optional_step_but_not_a_required_one(): void
    {
        $this->journeys()->start($this->user(3));

        [$ok] = $this->json('POST', '/api/onramp/skip', 3, ['step' => $this->step('avatar')]);
        [$refused] = $this->json('POST', '/api/onramp/skip', 3, ['step' => $this->step('reply')]);
        [, $body] = $this->json('GET', '/api/onramp', 3);

        $this->assertSame(200, $ok);
        $this->assertSame(422, $refused);
        $avatar = collect($body['data']['steps'])->firstWhere('type', 'avatar');
        $this->assertNotNull($avatar['skippedAt'], json_encode([$body, $this->database()->table('onramp_progress')->get()]));

        [, $undone] = $this->json('POST', '/api/onramp/unskip', 3, ['step' => $this->step('avatar')]);
        $this->assertNull(collect($undone['data']['steps'])->firstWhere('type', 'avatar')['skippedAt']);
    }

    #[Test]
    public function finishing_every_step_graduates_once(): void
    {
        $user = $this->user(3);
        $this->journeys()->start($user);

        foreach (['avatar', 'read', 'like_given', 'discussion'] as $type) {
            $this->json('POST', '/api/onramp/skip', 3, ['step' => $this->step($type)]);
        }

        $body = $this->reply(3);

        $this->assertTrue($body['meta']['onramp']['graduated']);
        $this->assertSame('graduated', $body['meta']['onramp']['state']['status']);
        $this->assertFalse((bool) $this->database()->table('users')->where('id', 3)->value('onramp_open'));
        $this->assertSame(1, $this->database()->table('notifications')->where(['user_id' => 3, 'type' => 'onrampGraduated'])->count());

        // A graduate's user no longer carries a journey.
        [, $own] = $this->json('GET', '/api/users/3', 3);
        $this->assertNull($own['data']['attributes']['onramp']);
    }

    #[Test]
    public function a_like_received_alerts_the_member_because_they_were_not_watching(): void
    {
        $this->database()->table('onramp_steps')->insert(['type' => 'like_received', 'position' => 9, 'is_enabled' => true, 'points' => 0, 'is_required' => false]);
        $this->database()->table('posts')->insert(['id' => 2, 'discussion_id' => 1, 'number' => 2, 'created_at' => Carbon::now(), 'user_id' => 3, 'type' => 'comment', 'content' => '<t><p>Mine</p></t>']);
        $this->journeys()->start($this->user(3));

        [$status] = $this->json('PATCH', '/api/posts/2', 4, ['data' => ['type' => 'posts', 'id' => '2', 'attributes' => ['isLiked' => true]]]);

        $this->assertSame(200, $status);
        $this->assertSame(1, $this->database()->table('notifications')->where(['user_id' => 3, 'type' => 'onrampStepCompleted'])->count());
    }

    #[Test]
    public function the_actors_own_user_in_the_same_response_carries_the_fresh_journey(): void
    {
        $this->journeys()->start($this->user(3));

        // A like returns the post with its likers — Carol among them.
        $response = $this->send($this->request('PATCH', '/api/posts/1', [
            'authenticatedAs' => 3,
            'json' => ['data' => ['type' => 'posts', 'id' => '1', 'attributes' => ['isLiked' => true]]],
        ])->withQueryParams(['include' => 'likes']));
        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $self = collect($body['included'] ?? [])->first(fn ($r) => $r['type'] === 'users' && $r['id'] === '3');
        $this->assertNotNull($self, 'Carol is in the response');
        $this->assertSame(1, $self['attributes']['onramp']['done']);
        $this->assertSame(1, $body['meta']['onramp']['state']['done']);
    }

    #[Test]
    public function new_members_start_a_journey_when_they_register(): void
    {
        $this->setting('allow_sign_up', '1');

        [$status, $dbg] = $this->json('POST', '/register', null, ['username' => 'newbie', 'password' => 'too-obscure', 'email' => 'newbie@machine.local']);

        $this->assertSame(201, $status, json_encode($dbg));
        $id = $this->database()->table('users')->where('username', 'newbie')->value('id');
        $this->assertTrue($this->database()->table('onramp_journeys')->where('user_id', $id)->exists());
        $this->assertTrue((bool) $this->database()->table('users')->where('id', $id)->value('onramp_open'));
    }

    #[Test]
    public function a_visit_completes_only_the_matching_visit_step(): void
    {
        $this->database()->table('onramp_steps')->insert(['type' => 'visit', 'config' => json_encode(['path' => '/d/1-rules']), 'position' => 9, 'is_enabled' => true, 'points' => 0, 'is_required' => false]);
        $this->journeys()->start($this->user(3));
        $visit = $this->step('visit');

        [, $wrong] = $this->json('POST', '/api/onramp/visit', 3, ['path' => '/d/2']);
        [, $right] = $this->json('POST', '/api/onramp/visit', 3, ['path' => '/d/1-open/4']);

        $this->assertSame([], $wrong['meta']['completed']);
        $this->assertSame([$visit], $right['meta']['completed']);
    }

    #[Test]
    public function guests_get_nothing_from_the_member_endpoints(): void
    {
        [$journey, $dbg] = $this->json('GET', '/api/onramp');
        [$skip] = $this->json('POST', '/api/onramp/skip', null, ['step' => 1]);
        [$member] = $this->json('GET', '/api/onramp/members/3');

        $this->assertSame(401, $journey, json_encode($dbg));
        $this->assertSame(401, $skip);
        $this->assertContains($member, [401, 403]);
    }

    #[Test]
    public function members_cannot_see_others_journeys_or_touch_the_admin_endpoints(): void
    {
        $this->journeys()->start($this->user(3));

        [$member] = $this->json('GET', '/api/onramp/members/3', 4);
        [$manage] = $this->json('POST', '/api/onramp/members/3', 4, ['action' => 'reset']);
        [$steps] = $this->json('GET', '/api/onramp-admin/steps', 4);
        [$funnel] = $this->json('GET', '/api/onramp-admin/funnel', 4);
        [$admin] = $this->json('GET', '/api/onramp/members/3', 1);

        $this->assertSame(403, $member);
        $this->assertSame(403, $manage);
        $this->assertSame(403, $steps);
        $this->assertSame(403, $funnel);
        $this->assertSame(200, $admin);
    }
}
