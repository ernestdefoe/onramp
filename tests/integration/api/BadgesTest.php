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

/**
 * FoF Badges: a step's own badge, the journey's badge, and Onramp's two
 * triggers in FoF Badges' editor.
 */
class BadgesTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('fof-badges', 'ernestdefoe-onramp');
        $this->setting('ernestdefoe-onramp.reward_badge', '2');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'carol', 'email' => 'carol@machine.local', 'is_email_confirmed' => 1, 'avatar_url' => null, 'joined_at' => Carbon::now()],
                ['id' => 4, 'username' => 'dave', 'email' => 'dave@machine.local', 'is_email_confirmed' => 1, 'avatar_url' => null, 'joined_at' => Carbon::now()->subYears(2)],
            ],
            Discussion::class => [
                ['id' => 1, 'title' => 'Open', 'created_at' => Carbon::now(), 'user_id' => 4, 'first_post_id' => 1, 'comment_count' => 1, 'last_post_number' => 1],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'created_at' => Carbon::now(), 'user_id' => 4, 'type' => 'comment', 'content' => '<t><p>Start</p></t>'],
            ],
            'fof_badges' => [
                ['id' => 1, 'name' => 'First words', 'slug' => 'first-words', 'icon' => 'fas fa-comment', 'is_active' => true],
                ['id' => 2, 'name' => 'Graduate', 'slug' => 'graduate', 'icon' => 'fas fa-graduation-cap', 'is_active' => true],
                ['id' => 3, 'name' => 'On the road', 'slug' => 'on-the-road', 'icon' => 'fas fa-road', 'is_active' => true,
                    'trigger_config' => json_encode(['logic' => 'AND', 'conditions' => [['metric' => 'onramp_steps_completed', 'operator' => '>=', 'value' => 1]]])],
                ['id' => 4, 'name' => 'Finished', 'slug' => 'finished', 'icon' => 'fas fa-flag', 'is_active' => true,
                    'trigger_config' => json_encode(['logic' => 'AND', 'conditions' => [['metric' => 'onramp_graduated', 'operator' => '>=', 'value' => 1]]])],
            ],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'postWithoutThrottle'],
            ],
        ]);
    }

    /**
     * The graduating reply here awards FOUR distinct badges at once (the
     * step's, the journey's, and one per Onramp trigger) — each is FoF
     * Badges' own award with its own "badge earned" alert and its own badge
     * lookup. That is one per badge earned, not per item on a page, and a
     * real member earns them over days, not in one request.
     */
    protected function allowedRepeatedQueries(): array
    {
        // Matched without the table's opening quote, so CI's table prefixes
        // match too — once per identifier quote: MySQL's backtick, everyone
        // else's double quote.
        $shapes = [];
        foreach (['"', '`'] as $q) {
            $shapes[] = "notifications$q ({$q}created_at$q, {$q}data$q";
            $shapes[] = "fof_badges$q.{$q}id$q = ? limit";
        }

        return $shapes;
    }

    private function holds(int $user, int $badge): bool
    {
        return $this->database()->table('fof_badge_user')->where(['user_id' => $user, 'badge_id' => $badge])->exists();
    }

    #[Test]
    public function steps_and_the_journey_pay_their_badges_and_trigger_onramps_metrics(): void
    {
        $this->app();
        $this->database()->table('onramp_steps')->delete();
        $this->database()->table('onramp_steps')->insert(['id' => 10, 'type' => 'reply', 'position' => 0, 'is_enabled' => true, 'points' => 0, 'badge_id' => 1, 'is_required' => true]);

        $this->app()->getContainer()->make(Journeys::class)->start(User::query()->find(3));

        $response = $this->send($this->request('POST', '/api/posts', ['authenticatedAs' => 3, 'json' => ['data' => [
            'type' => 'posts',
            'attributes' => ['content' => 'Hello'],
            'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '1']]],
        ]]]));
        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertTrue($body['meta']['onramp']['graduated']);
        $this->assertTrue($this->holds(3, 1), 'the step badge');
        $this->assertTrue($this->holds(3, 2), 'the journey badge');
        $this->assertTrue($this->holds(3, 3), 'the steps-completed trigger');
        $this->assertTrue($this->holds(3, 4), 'the graduated trigger');
        // Nobody else.
        $this->assertSame(0, $this->database()->table('fof_badge_user')->where('user_id', '!=', 3)->count());
    }

    #[Test]
    public function the_badge_shows_on_the_step_the_member_sees(): void
    {
        $this->app();
        $this->database()->table('onramp_steps')->where('type', 'reply')->update(['badge_id' => 1]);
        $this->app()->getContainer()->make(Journeys::class)->start(User::query()->find(3));

        $response = $this->send($this->request('GET', '/api/onramp', ['authenticatedAs' => 3]));
        $body = json_decode((string) $response->getBody(), true);

        $reply = collect($body['data']['steps'])->firstWhere('type', 'reply');
        $this->assertSame('First words', $reply['badge']['name']);
        $this->assertSame('Graduate', $body['data']['reward']['badge']['name']);
    }
}
