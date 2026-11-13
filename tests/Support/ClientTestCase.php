<?php

declare(strict_types=1);

namespace PhotosSh\Tests\Support;

use PhotosSh\Photos;
use PhotosSh\Transport\Response;
use PHPUnit\Framework\TestCase;

abstract class ClientTestCase extends TestCase
{
    protected const KEY = 'test-key';

    protected const BASE = 'https://api.test/api';

    protected FakeTransport $transport;

    /** @var list<float> seconds the client asked to wait */
    protected array $sleeps = [];

    /**
     * A client whose transport answers with the given responses, in order,
     * and whose waits are recorded instead of slept.
     */
    protected function client(Response|\Throwable ...$responses): Photos
    {
        $this->transport = new FakeTransport(...$responses);
        $this->sleeps = [];

        return new Photos(
            apiKey: self::KEY,
            baseUrl: self::BASE,
            transport: $this->transport,
            sleep: function (float $seconds): void {
                $this->sleeps[] = $seconds;
            },
        );
    }

    protected function sent(): ParsedRequest
    {
        return new ParsedRequest($this->transport->last());
    }

    protected static function fixture(string $name): string
    {
        return dirname(__DIR__) . '/fixtures/' . $name;
    }
}
