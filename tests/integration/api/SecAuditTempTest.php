<?php

namespace ErnestDefoe\Onramp\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Onramp\Api\StepInput;
use ErnestDefoe\Onramp\Http\EvaluateAfterRequest;
use ErnestDefoe\Onramp\Journeys;
use ErnestDefoe\Onramp\Presenter;
use ErnestDefoe\Onramp\Tracker;
use Flarum\Api\Client;
use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class SecAuditTempTest extends TestCase
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
            ],
            Discussion::class => [
                ['id' => 1, 'title' => 'Open', 'created_at' => Carbon::now(), 'user_id' => 2, 'first_post_id' => 1, 'comment_count' => 1, 'last_post_number' => 1],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'created_at' => Carbon::now(), 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>Start</p></t>'],
                ['id' => 2, 'discussion_id' => 1, 'number' => 2, 'created_at' => Carbon::now(), 'user_id' => 3, 'type' => 'comment', 'content' => '<t><p>Old</p></t>'],
            ],
        ]);
    }

    private function status(string $method, string $path, ?int $actor = null, ?array $body = null): int
    {
        $options = $actor ? ['authenticatedAs' => $actor] : [];
        if ($body !== null) $options['json'] = $body;
        $r = $this->request($method, $path, $options);
        return $this->send($actor ? $r : $r->withAttribute('bypassCsrfToken', true))->getStatusCode();
    }

    #[Test]
    public function access_matrix(): void
    {
        $out = [
            'guest GET members/3' => $this->status('GET', '/api/onramp/members/3'),
            'guest GET members/999' => $this->status('GET', '/api/onramp/members/999'),
            'member GET members/3' => $this->status('GET', '/api/onramp/members/3', 2),
            'member GET members/999' => $this->status('GET', '/api/onramp/members/999', 2),
            'member POST members/3 complete' => $this->status('POST', '/api/onramp/members/3', 2, ['action' => 'complete', 'step' => 1]),
            'member GET admin steps' => $this->status('GET', '/api/onramp-admin/steps', 2),
            'member POST import' => $this->status('POST', '/api/onramp-admin/import', 2, ['steps' => []]),
            'member POST order' => $this->status('POST', '/api/onramp-admin/order', 2, ['ids' => [1]]),
            'member GET funnel' => $this->status('GET', '/api/onramp-admin/funnel', 2),
            'member POST start-existing' => $this->status('POST', '/api/onramp-admin/start-existing', 2, ['days' => 30]),
            'guest GET onramp' => $this->status('GET', '/api/onramp'),
            'guest POST visit' => $this->status('POST', '/api/onramp/visit', null, ['path' => '/']),
        ];
        fwrite(STDERR, "\nACCESS ".json_encode($out, JSON_PRETTY_PRINT)."\n");
        $this->assertTrue(true);
    }

    #[Test]
    public function islink_fuzz(): void
    {
        $input = $this->app()->getContainer()->make(StepInput::class);
        $m = new \ReflectionMethod($input, 'isLink');
        $cases = ["/\\evil.com", "//evil.com", "/\t/evil.com", "/\n/evil.com", "\x01javascript:alert(1)", "java\nscript:alert(1)",
            " javascript:alert(1)", "javascript:alert(1)", "https://evil.com", "HTTPS://x", "https://a\x01b", "/%2F%2Fevil.com", "data:text/html,x", "https:evil.com", "/ok"];
        $res = [];
        foreach ($cases as $c) {
            $res[json_encode($c)] = $m->invoke($input, trim($c));
        }
        fwrite(STDERR, "\nISLINK ".json_encode($res, JSON_PRETTY_PRINT)."\n");
        $this->assertTrue(true);
    }

    #[Test]
    public function rewrite_turns_empty_objects_into_arrays(): void
    {
        $c = $this->app()->getContainer();
        $tracker = new class($c->make(Journeys::class), $c->make(\ErnestDefoe\Onramp\Config::class), $c->make(\Psr\Log\LoggerInterface::class)) extends Tracker {
            public function flush(): ?array { return ['completed' => [], 'graduated' => true]; }
        };
        $mw = new EvaluateAfterRequest($tracker, $c->make(Presenter::class));
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $r): ResponseInterface {
                return new JsonResponse(json_decode('{"data":{"type":"x","id":"1","attributes":{"prefs":{}},"relationships":{}},"meta":{}}'), 200, ['Content-Type' => 'application/vnd.api+json']);
            }
        };
        $req = (new ServerRequest())->withAttribute('actor', User::query()->find(3));
        $body = (string) $mw->process($req, $handler)->getBody();
        fwrite(STDERR, "\nREWRITE ".$body."\n");
        $this->assertTrue(true);
    }

    #[Test]
    public function nested_api_client_drops_outer_touches(): void
    {
        $c = $this->app()->getContainer();
        $journeys = $c->make(Journeys::class);
        $carol = User::query()->find(3);
        // Start without evaluating: insert row directly.
        $this->database()->table('onramp_journeys')->insert(['user_id' => 3, 'started_at' => Carbon::now(), 'reminded' => 0]);
        $this->database()->table('users')->where('id', 3)->update(['onramp_open' => true]);

        $tracker = $c->make(Tracker::class);
        $tracker->begin($carol);
        $tracker->touch(3, 'reply');
        // An inner API call as the same actor, like a forum page's preload.
        $c->make(Client::class)->withActor($carol)->get('/');
        $mine = $tracker->flush();
        $tracker->end();

        $done = $journeys->completedIds($carol);
        fwrite(STDERR, "\nNESTED mine=".json_encode($mine).' completed='.json_encode($done)."\n");
        $this->assertTrue(true);
    }
}
