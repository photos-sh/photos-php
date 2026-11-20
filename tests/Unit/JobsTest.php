<?php

declare(strict_types=1);

namespace PhotosSh\Tests\Unit;

use PhotosSh\Exception\ApiException;
use PhotosSh\Exception\InvalidInputException;
use PhotosSh\Exception\NotFoundException;
use PhotosSh\Exception\WaitTimeoutException;
use PhotosSh\Input;
use PhotosSh\Photos;
use PhotosSh\Tests\Support\ClientTestCase;
use PhotosSh\Tests\Support\FakeTransport;
use PhotosSh\Transport\Response;

final class JobsTest extends ClientTestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function job(string $status = 'queued', int $done = 0): array
    {
        return [
            'id' => 'job_abc',
            'operation' => 'remove-background',
            'params' => 'format=png',
            'status' => $status,
            'created_at' => 1_700_000_000,
            'items' => [['input' => 'https://example.com/1.jpg', 'status' => $done > 0 ? 'done' : 'pending']],
            'counts' => ['total' => 1, 'done' => $done, 'failed' => 0, 'pending' => 1 - $done],
        ];
    }

    public function testCreateWithUrlsSendsJson(): void
    {
        $photos = $this->client(FakeTransport::json(self::job(), 202, ['Location' => '/api/v1/jobs/job_abc']));

        $job = $photos->createJob(
            'remove-background',
            ['https://example.com/1.jpg', 'http://example.com/2.jpg'],
            params: ['format' => 'png', 'crop' => true, 'padding' => 8, 'model' => 'muse', 'background' => null],
            webhookUrl: 'https://example.com/hooks/photos',
        );

        $sent = $this->sent();
        self::assertSame('POST', $sent->method);
        self::assertSame('/api/v1/jobs', $sent->path);
        self::assertSame('', $sent->query);
        self::assertSame('application/json', $sent->request->headers['Content-Type']);
        self::assertSame(
            '{"operation":"remove-background",'
            . '"inputs":["https://example.com/1.jpg","http://example.com/2.jpg"],'
            . '"params":{"format":"png","crop":true,"padding":8,"model":"muse"},'
            . '"webhook_url":"https://example.com/hooks/photos"}',
            $sent->request->body,
        );
        self::assertSame('job_abc', $job['id']);
        self::assertSame('queued', $job['status']);
        self::assertSame(1, $job['counts']['total']);
    }

    public function testCreateWithUrlsLeavesOutWhatIsNotSet(): void
    {
        $photos = $this->client(FakeTransport::json(self::job(), 202));

        $photos->createJob('ocr', ['https://example.com/1.jpg']);

        self::assertSame('{"operation":"ocr","inputs":["https://example.com/1.jpg"]}', $this->sent()->request->body);
    }

    public function testCreateTakesTheEditingToolsAsOperations(): void
    {
        $photos = $this->client(FakeTransport::json(self::job(), 202));

        $photos->createJob('watermark', ['https://example.com/1.jpg'], params: ['text' => '© Shop', 'tile' => true]);

        self::assertSame(
            '{"operation":"watermark","inputs":["https://example.com/1.jpg"],"params":{"text":"© Shop","tile":true}}',
            $this->sent()->request->body,
        );
    }

    public function testCreateSendsParamsThatRepeatAsAQueryString(): void
    {
        $photos = $this->client(
            FakeTransport::json(self::job(), 202),
            FakeTransport::json(self::job(), 202),
            FakeTransport::json(self::job(), 202),
        );
        $params = ['point' => ['160,130', '170.5,200'], 'exclude' => ['20,20'], 'box' => null, 'mask' => true, 'padding' => 4];
        $query = 'point=160%2C130&point=170.5%2C200&exclude=20%2C20&mask=true&padding=4';

        // With URLs the body is JSON, and a JSON object cannot repeat a key.
        $photos->createJob('select', ['https://example.com/1.jpg'], params: $params);

        $sent = $this->sent();
        self::assertSame('application/json', $sent->request->headers['Content-Type']);
        self::assertSame(
            '{"operation":"select","inputs":["https://example.com/1.jpg"],"params":"' . $query . '"}',
            $sent->request->body,
        );

        // A list of one is still a list.
        $photos->createJob('select', ['https://example.com/1.jpg'], params: ['point' => ['24,24']]);

        self::assertSame(
            '{"operation":"select","inputs":["https://example.com/1.jpg"],"params":"point=24%2C24"}',
            $this->sent()->request->body,
        );

        // With uploads the params are a query string anyway.
        $photos->createJob('select', [self::fixture('tiny.png')], params: $params);

        $sent = $this->sent();
        self::assertSame(['operation', 'params', 'image'], $sent->partNames());
        self::assertSame('select', $sent->parts('operation')[0]['content']);
        self::assertSame($query, $sent->parts('params')[0]['content']);
    }

    public function testCreateLeavesOutAnEmptyList(): void
    {
        $photos = $this->client(FakeTransport::json(self::job(), 202), FakeTransport::json(self::job(), 202));

        $photos->createJob('select', ['https://example.com/1.jpg'], params: ['point' => '24,24', 'exclude' => []]);

        self::assertSame(
            '{"operation":"select","inputs":["https://example.com/1.jpg"],"params":{"point":"24,24"}}',
            $this->sent()->request->body,
        );

        $photos->createJob('ocr', [self::fixture('tiny.png')], params: ['exclude' => []]);

        self::assertSame(['operation', 'image'], $this->sent()->partNames());
    }

    public function testCreateWithARecipe(): void
    {
        $photos = $this->client(FakeTransport::json(self::job(), 202));

        $photos->createJob('run', ['https://example.com/1.jpg'], recipe: "[resize]\nwidth = 100\n");

        $body = json_decode((string) $this->sent()->request->body, true);
        self::assertIsArray($body);
        self::assertSame("[resize]\nwidth = 100\n", $body['recipe']);
        self::assertArrayNotHasKey('params', $body);
    }

    public function testCreateWithUploadsSendsMultipart(): void
    {
        $photos = $this->client(FakeTransport::json(self::job(), 202));

        $photos->createJob(
            'resize',
            [self::fixture('tiny.png'), 'https://example.com/2.jpg', Input::fromBytes('three', 'three.jpg')],
            params: ['width' => 100, 'enlarge' => false, 'background' => '#fff'],
            recipe: 'toml',
            webhookUrl: 'https://example.com/hook',
        );

        $sent = $this->sent();
        self::assertSame('/api/v1/jobs', $sent->path);
        self::assertSame('', $sent->query);
        self::assertStringStartsWith('multipart/form-data; boundary=', $sent->request->headers['Content-Type']);
        self::assertSame(['operation', 'params', 'recipe', 'webhook_url', 'image', 'url', 'image'], $sent->partNames());
        self::assertSame('resize', $sent->parts('operation')[0]['content']);
        self::assertSame('width=100&enlarge=false&background=%23fff', $sent->parts('params')[0]['content']);
        self::assertSame('toml', $sent->parts('recipe')[0]['content']);
        self::assertSame('recipe.toml', $sent->parts('recipe')[0]['filename']);
        self::assertSame('https://example.com/hook', $sent->parts('webhook_url')[0]['content']);
        self::assertSame('https://example.com/2.jpg', $sent->parts('url')[0]['content']);
        self::assertSame(['tiny.png', 'three.jpg'], array_column($sent->parts('image'), 'filename'));
        self::assertSame('three', $sent->parts('image')[1]['content']);
    }

    public function testCreateNeedsAnInput(): void
    {
        $photos = $this->client();

        $this->expectException(InvalidInputException::class);

        $photos->createJob('resize', []);
    }

    public function testGet(): void
    {
        $photos = $this->client(FakeTransport::json(self::job('running')));

        $job = $photos->getJob('job_abc');

        self::assertSame('GET', $this->sent()->method);
        self::assertSame('/api/v1/jobs/job_abc', $this->sent()->path);
        self::assertSame('running', $job['status']);
    }

    public function testGetEscapesTheId(): void
    {
        $photos = $this->client(FakeTransport::json(self::job()));

        $photos->getJob('../account?x=1');

        self::assertSame(self::BASE . '/v1/jobs/..%2Faccount%3Fx%3D1', $this->transport->last()->url);
    }

    public function testGetAMissingJob(): void
    {
        $photos = $this->client(FakeTransport::error(404, 'not_found', 'no such job'));

        try {
            $photos->getJob('job_nope');
            self::fail('Expected an ApiException.');
        } catch (NotFoundException $e) {
            self::assertSame(404, $e->status);
            self::assertSame(ApiException::NOT_FOUND, $e->errorCode);
        }
    }

    public function testList(): void
    {
        $photos = $this->client(FakeTransport::json([
            ['id' => 'job_2', 'operation' => 'ocr', 'status' => 'completed', 'created_at' => 2, 'finished_at' => 3, 'counts' => []],
            ['id' => 'job_1', 'operation' => 'ocr', 'status' => 'queued', 'created_at' => 1, 'finished_at' => null, 'counts' => []],
        ]));

        $jobs = $photos->listJobs();

        self::assertSame('/api/v1/jobs', $this->sent()->path);
        self::assertSame('GET', $this->sent()->method);
        self::assertSame(['job_2', 'job_1'], array_column($jobs, 'id'));
        self::assertNull($jobs[1]['finished_at']);
    }

    public function testListWhenEmpty(): void
    {
        $photos = $this->client(FakeTransport::json([]));

        self::assertSame([], $photos->listJobs());
    }

    public function testDelete(): void
    {
        $photos = $this->client(new Response(204));

        $photos->deleteJob('job_abc');

        self::assertSame('DELETE', $this->sent()->method);
        self::assertSame('/api/v1/jobs/job_abc', $this->sent()->path);
        self::assertNull($this->sent()->request->body);
    }

    public function testDeleteAMissingJob(): void
    {
        $photos = $this->client(FakeTransport::error(404, 'not_found', 'no such job'));

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('no such job');

        $photos->deleteJob('job_nope');
    }

    public function testItemResult(): void
    {
        $photos = $this->client(FakeTransport::image('item-bytes'));

        $item = $photos->jobItemResult('job_abc', 3);

        self::assertSame('GET', $this->sent()->method);
        self::assertSame('/api/v1/jobs/job_abc/items/3', $this->sent()->path);
        self::assertTrue($item->isImage());
        self::assertSame('item-bytes', $item->image()->bytes);
        self::assertSame(640, $item->image()->width);
    }

    public function testWaitPollsUntilCompleted(): void
    {
        $photos = $this->client(
            FakeTransport::json(self::job('queued')),
            FakeTransport::json(self::job('running')),
            FakeTransport::json(self::job('completed', 1)),
        );

        $job = $photos->waitForJob('job_abc');

        self::assertSame('completed', $job['status']);
        self::assertSame(1, $job['counts']['done']);
        self::assertCount(3, $this->transport->requests);
        self::assertSame([2.0, 2.0], $this->sleeps);
        self::assertSame('/api/v1/jobs/job_abc', $this->sent()->path);
    }

    public function testWaitReturnsAtOnceWhenAlreadyCompleted(): void
    {
        $photos = $this->client(FakeTransport::json(self::job('completed', 1)));

        $photos->waitForJob('job_abc', pollInterval: 0.5);

        self::assertCount(1, $this->transport->requests);
        self::assertSame([], $this->sleeps);
    }

    public function testWaitUsesThePollInterval(): void
    {
        $photos = $this->client(FakeTransport::json(self::job()), FakeTransport::json(self::job('completed', 1)));

        $photos->waitForJob('job_abc', pollInterval: 0.25);

        self::assertSame([0.25], $this->sleeps);
    }

    public function testWaitTimesOut(): void
    {
        $responses = [];
        for ($i = 0; $i < 50; ++$i) {
            $responses[] = FakeTransport::json(self::job('running'));
        }
        $this->transport = new FakeTransport(...$responses);
        // The real clock and real (tiny) sleeps.
        $photos = new Photos(self::KEY, self::BASE, transport: $this->transport);

        $started = microtime(true);
        try {
            $photos->waitForJob('job_abc', pollInterval: 0.01, timeout: 0.05);
            self::fail('Expected a WaitTimeoutException.');
        } catch (WaitTimeoutException $e) {
            self::assertStringContainsString('job_abc', $e->getMessage());
            self::assertSame('running', $e->job['status'] ?? null);
            self::assertGreaterThan(1, count($this->transport->requests));
            self::assertLessThan(2.0, microtime(true) - $started);
        }
    }
}
