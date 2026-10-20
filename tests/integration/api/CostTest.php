<?php

namespace ErnestDefoe\Onramp\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Onramp\Journeys;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use PHPUnit\Framework\Attributes\Test;

/**
 * 🚨 The promise in extend.php, measured: nobody without an open journey pays
 * a single query for Onramp, on any page — guests, graduates, members who
 * never had one. Only a member mid-journey carries it, at a fixed cost that
 * does not grow with the page.
 */
class CostTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-likes', 'ernestdefoe-onramp');

        $users = [$this->normalUser()];
        $discussions = [];
        $posts = [];

        // A page of discussions by ten DISTINCT authors, so anything resolved
        // per author would show as a repeat.
        for ($i = 1; $i <= 10; $i++) {
            $users[] = ['id' => 10 + $i, 'username' => "author$i", 'email' => "author$i@machine.local", 'is_email_confirmed' => 1, 'avatar_url' => null, 'joined_at' => Carbon::now()];
            $discussions[] = ['id' => $i, 'title' => "D$i", 'created_at' => Carbon::now()->subMinutes($i), 'user_id' => 10 + $i, 'first_post_id' => $i, 'comment_count' => 1, 'last_post_number' => 1, 'last_posted_at' => Carbon::now()->subMinutes($i), 'last_posted_user_id' => 10 + $i];
            $posts[] = ['id' => $i, 'discussion_id' => $i, 'number' => 1, 'created_at' => Carbon::now()->subMinutes($i), 'user_id' => 10 + $i, 'type' => 'comment', 'content' => '<t><p>Hi</p></t>'];
        }

        $this->prepareDatabase([User::class => $users, Discussion::class => $discussions, Post::class => $posts]);
    }

    /** @return list<string> every query a request ran */
    private function queries(string $path, ?int $actor): array
    {
        $this->app();
        $db = $this->app()->getContainer()->make(ConnectionInterface::class);
        $db->flushQueryLog();
        $db->enableQueryLog();

        $response = $this->send($this->request('GET', $path, $actor ? ['authenticatedAs' => $actor] : []));
        $this->assertSame(200, $response->getStatusCode());

        $log = array_map(fn ($q) => $q['query'], $db->getQueryLog());
        $db->disableQueryLog();

        return $log;
    }

    private function onramp(array $queries): array
    {
        return array_values(array_filter($queries, fn (string $q) => str_contains($q, 'onramp')));
    }

    #[Test]
    public function a_guest_costs_nothing(): void
    {
        $this->assertSame([], $this->onramp($this->queries('/api/discussions?include=user', null)));
    }

    #[Test]
    public function a_member_who_never_had_a_journey_costs_nothing(): void
    {
        $this->assertSame([], $this->onramp($this->queries('/api/discussions?include=user', 2)));
        $this->assertSame([], $this->onramp($this->queries('/api/users/2', 2)));
    }

    #[Test]
    public function a_graduate_costs_nothing(): void
    {
        $this->app();
        $this->database()->table('onramp_journeys')->insert(['user_id' => 2, 'started_at' => Carbon::now()->subDays(3), 'completed_at' => Carbon::now()]);

        $this->assertSame([], $this->onramp($this->queries('/api/discussions?include=user', 2)));
        $this->assertSame([], $this->onramp($this->queries('/api/users/2', 2)));
    }

    #[Test]
    public function a_member_mid_journey_pays_a_fixed_few_on_their_own_user_only(): void
    {
        $this->app();
        $this->app()->getContainer()->make(Journeys::class)->start(User::query()->find(2));

        // Listing other people's discussions: their users are not the actor,
        // so the field is not even asked for.
        $this->assertSame([], $this->onramp($this->queries('/api/discussions?include=user', 2)));

        // The member's own user: the journey, its steps, their progress.
        $own = $this->onramp($this->queries('/api/users/2', 2));
        $this->assertCount(3, $own, implode("\n", $own));
    }
}
