<?php

namespace ErnestDefoe\Onramp\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Onramp\Journeys;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class AdminTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-likes', 'flarum-tags', 'ernestdefoe-onramp');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'carol', 'email' => 'carol@machine.local', 'is_email_confirmed' => 1, 'avatar_url' => null, 'joined_at' => Carbon::now()->subDays(5)],
                ['id' => 4, 'username' => 'dave', 'email' => 'dave@machine.local', 'is_email_confirmed' => 1, 'avatar_url' => null, 'joined_at' => Carbon::now()->subDays(10)],
                ['id' => 5, 'username' => 'erin', 'email' => 'erin@machine.local', 'is_email_confirmed' => 1, 'avatar_url' => null, 'joined_at' => Carbon::now()->subYears(3)],
            ],
            Discussion::class => [
                ['id' => 1, 'title' => 'Open', 'created_at' => Carbon::now(), 'user_id' => 5, 'first_post_id' => 1, 'comment_count' => 2, 'last_post_number' => 2],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'created_at' => Carbon::now(), 'user_id' => 5, 'type' => 'comment', 'content' => '<t><p>Start</p></t>'],
                // Dave already replied before Onramp was installed.
                ['id' => 2, 'discussion_id' => 1, 'number' => 2, 'created_at' => Carbon::now(), 'user_id' => 4, 'type' => 'comment', 'content' => '<t><p>Old reply</p></t>'],
            ],
        ]);
    }

    private function json(string $method, string $path, ?int $actor = null, ?array $body = null, array $query = []): array
    {
        $options = $actor ? ['authenticatedAs' => $actor] : [];
        if ($body !== null) {
            $options['json'] = $body;
        }

        $response = $this->send($this->request($method, $path, $options)->withQueryParams($query));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    #[Test]
    public function the_listing_carries_the_catalogue_with_availability(): void
    {
        [$status, $body] = $this->json('GET', '/api/onramp-admin/steps', 1);

        $this->assertSame(200, $status);
        $this->assertCount(5, $body['data']);
        $types = collect($body['meta']['types'])->keyBy('key');
        $this->assertTrue($types['like_given']['available']);
        // fof/polls is installed in vendor but not enabled here.
        $this->assertFalse($types['poll_vote']['available']);
        $this->assertSame(['count', 'tag'], $types['reply']['fields']);
    }

    #[Test]
    public function an_admin_adds_edits_reorders_and_deletes_steps(): void
    {
        [$created, $body] = $this->json('POST', '/api/onramp-admin/steps', 1, ['attributes' => [
            'type' => 'reply', 'config' => ['count' => 3], 'title' => 'Say three things', 'points' => 15, 'is_required' => true,
        ]]);
        $this->assertSame(200, $created);
        $new = collect($body['data'])->firstWhere('title', 'Say three things');
        $this->assertSame(['count' => 3], $new['config']);
        $this->assertSame(15, $new['points']);
        $this->assertTrue($new['required']);

        [, $edited] = $this->json('PATCH', '/api/onramp-admin/steps/'.$new['id'], 1, ['attributes' => ['title' => '', 'is_enabled' => false]]);
        $row = collect($edited['data'])->firstWhere('id', $new['id']);
        $this->assertNull($row['title']);
        $this->assertFalse($row['enabled']);
        // A partial edit leaves the rest alone.
        $this->assertSame(['count' => 3], $row['config']);

        $ids = collect($edited['data'])->pluck('id')->reverse()->values()->all();
        [, $ordered] = $this->json('POST', '/api/onramp-admin/order', 1, ['ids' => $ids]);
        $this->assertSame($ids, collect($ordered['data'])->pluck('id')->all());

        [$deleted] = $this->json('DELETE', '/api/onramp-admin/steps/'.$new['id'], 1);
        $this->assertSame(204, $deleted);
        $this->assertFalse($this->database()->table('onramp_steps')->where('id', $new['id'])->exists());
    }

    #[Test]
    public function unsafe_links_and_unknown_types_are_refused(): void
    {
        [$js] = $this->json('POST', '/api/onramp-admin/steps', 1, ['attributes' => ['type' => 'reply', 'button_url' => 'javascript:alert(1)']]);
        [$proto] = $this->json('POST', '/api/onramp-admin/steps', 1, ['attributes' => ['type' => 'reply', 'button_url' => '//evil.example/x']]);
        [$path] = $this->json('POST', '/api/onramp-admin/steps', 1, ['attributes' => ['type' => 'visit', 'config' => ['path' => 'data:text/html,hi']]]);
        [$type] = $this->json('POST', '/api/onramp-admin/steps', 1, ['attributes' => ['type' => 'poll_vote']]);
        [$count] = $this->json('POST', '/api/onramp-admin/steps', 1, ['attributes' => ['type' => 'reply', 'config' => ['count' => 5000]]]);
        [$ok] = $this->json('POST', '/api/onramp-admin/steps', 1, ['attributes' => ['type' => 'reply', 'button_url' => 'https://example.com/guide']]);

        $this->assertSame([422, 422, 422, 422, 422, 200], [$js, $proto, $path, $type, $count, $ok]);
    }

    #[Test]
    public function an_import_keeps_what_fits_and_never_carries_badge_ids(): void
    {
        [$status, $body] = $this->json('POST', '/api/onramp-admin/import', 1, ['replace' => true, 'steps' => [
            ['type' => 'avatar', 'points' => 5, 'badgeId' => 7],
            ['type' => 'poll_vote'],              // not on this forum: left out
            ['type' => 'nonsense'],               // not a type at all: left out
            ['type' => 'read', 'config' => ['count' => 4], 'required' => true],
        ]]);

        $this->assertSame(200, $status);
        $this->assertSame(['avatar', 'read'], collect($body['data'])->pluck('type')->all());
        $this->assertNull($body['data'][0]['badgeId']);
        $this->assertSame(['count' => 4], $body['data'][1]['config']);
    }

    #[Test]
    public function starting_existing_members_goes_in_batches_and_is_silent(): void
    {
        [$status, $body] = $this->json('POST', '/api/onramp-admin/start-existing', 1, ['days' => 30]);

        $this->assertSame(200, $status);
        // Carol (5 days), Dave (10 days) and the normal user from the fixture
        // joined recently; Erin (3 years) did not.
        $started = $this->database()->table('onramp_journeys')->pluck('user_id')->all();
        $this->assertContains(3, $started);
        $this->assertContains(4, $started);
        $this->assertNotContains(5, $started);
        $this->assertSame(0, $body['data']['remaining']);

        // Dave's old reply ticks his reply step — without an alert.
        $reply = $this->database()->table('onramp_steps')->where('type', 'reply')->value('id');
        $this->assertNotNull($this->database()->table('onramp_progress')->where(['user_id' => 4, 'step_id' => $reply])->value('completed_at'));
        $this->assertSame(0, $this->database()->table('notifications')->count());

        // Again: nobody is started twice.
        [, $again] = $this->json('POST', '/api/onramp-admin/start-existing', 1, ['days' => 30]);
        $this->assertSame(0, $again['data']['started']);
    }

    #[Test]
    public function the_funnel_counts_each_step_and_the_stuck(): void
    {
        $this->app();
        $journeys = $this->app()->getContainer()->make(Journeys::class);
        foreach ([3, 4] as $id) {
            $journeys->start(User::query()->find($id), Journeys::SILENT);
        }

        [$status, $body] = $this->json('GET', '/api/onramp-admin/funnel', 1, null, ['weeks' => 4]);

        $this->assertSame(200, $status);
        $data = $body['data'];
        $this->assertSame(2, $data['totals']['started']);
        $reply = collect($data['steps'])->firstWhere('type', 'reply');
        $this->assertSame(1, $reply['reached']);
        $this->assertSame(0.5, $reply['rate']);
        $this->assertNotNull($reply['medianHours']);
        // Both are stuck at the first step, the avatar.
        $this->assertSame(2, collect($data['steps'])->firstWhere('type', 'avatar')['stuck']);
        $this->assertCount(4, $data['cohorts']);
    }

    #[Test]
    public function reminders_go_once_per_day_chosen_and_stale_journeys_are_put_away(): void
    {
        $this->setting('ernestdefoe-onramp.close_after', 20);
        $this->app();
        $this->database()->table('onramp_journeys')->insert([
            ['user_id' => 3, 'started_at' => Carbon::now()->subDays(8), 'reminded' => 3],   // due day 7
            ['user_id' => 4, 'started_at' => Carbon::now()->subDays(25), 'reminded' => 0],  // stale: closed, not reminded
            ['user_id' => 5, 'started_at' => Carbon::now()->subDays(1), 'reminded' => 0],   // not due yet
        ]);
        $this->database()->table('users')->whereIn('id', [3, 4, 5])->update(['onramp_open' => true]);

        $this->runCommand();
        $this->runCommand();

        $this->assertSame(7, (int) $this->database()->table('onramp_journeys')->where('user_id', 3)->value('reminded'));
        $this->assertSame(1, $this->database()->table('notifications')->where(['user_id' => 3, 'type' => 'onrampReminder'])->count());
        $this->assertNotNull($this->database()->table('onramp_journeys')->where('user_id', 4)->value('closed_at'));
        $this->assertFalse((bool) $this->database()->table('users')->where('id', 4)->value('onramp_open'));
        $this->assertSame(0, $this->database()->table('notifications')->whereIn('user_id', [4, 5])->count());
    }

    private function runCommand(): void
    {
        $this->app()->getContainer()->make(\ErnestDefoe\Onramp\Console\RemindCommand::class)->run(new ArrayInput([]), new BufferedOutput());
    }

    #[Test]
    public function a_tag_scoped_step_only_counts_posts_in_that_tag(): void
    {
        $this->app();
        $this->database()->table('tags')->insert(['id' => 9, 'name' => 'Intro', 'slug' => 'intro', 'position' => 1]);
        // Discussion, then post, then the link between them: MySQL and
        // PostgreSQL enforce both foreign keys.
        $this->database()->table('discussions')->insert(['id' => 2, 'title' => 'Hi', 'slug' => 'hi', 'created_at' => Carbon::now(), 'user_id' => 3, 'comment_count' => 1, 'last_post_number' => 1]);
        $this->database()->table('posts')->insert(['id' => 3, 'discussion_id' => 2, 'number' => 1, 'created_at' => Carbon::now(), 'user_id' => 3, 'type' => 'comment', 'content' => '<t><p>Hi</p></t>']);
        $this->database()->table('discussions')->where('id', 2)->update(['first_post_id' => 3]);
        $this->database()->table('onramp_steps')->delete();
        $this->database()->table('onramp_steps')->insert(['id' => 50, 'type' => 'discussion', 'config' => json_encode(['tag' => 9]), 'position' => 0, 'is_enabled' => true, 'points' => 0, 'is_required' => false]);

        $journeys = $this->app()->getContainer()->make(Journeys::class);
        $journeys->start(User::query()->find(3), Journeys::SILENT);
        $this->assertFalse($this->database()->table('onramp_progress')->where(['user_id' => 3, 'step_id' => 50])->whereNotNull('completed_at')->exists());

        $this->database()->table('discussion_tag')->insert(['discussion_id' => 2, 'tag_id' => 9]);
        $journeys->evaluate(User::query()->find(3));
        $this->assertTrue($this->database()->table('onramp_progress')->where(['user_id' => 3, 'step_id' => 50])->whereNotNull('completed_at')->exists());
    }
}
