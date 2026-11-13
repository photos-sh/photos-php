<?php

declare(strict_types=1);

namespace PhotosSh\Tests\Support;

/**
 * PHP's built-in web server running tests/fixtures/server.php on a free
 * local port, for tests that need a real HTTP peer.
 */
final class LocalServer
{
    public readonly string $url;

    public readonly string $stateDir;

    /** @var resource */
    private $process;

    public function __construct()
    {
        $port = self::freePort();
        $this->url = 'http://127.0.0.1:' . $port;
        $this->stateDir = sys_get_temp_dir() . '/photos-sh-php-' . bin2hex(random_bytes(6));
        mkdir($this->stateDir, 0o700, true);

        $process = proc_open(
            [\PHP_BINARY, '-S', '127.0.0.1:' . $port, dirname(__DIR__) . '/fixtures/server.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['PHOTOS_TEST_STATE_DIR' => $this->stateDir] + getenv(),
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start the PHP built-in server.');
        }
        $this->process = $process;

        $deadline = microtime(true) + 5.0;
        while (true) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
            if (is_resource($socket)) {
                fclose($socket);

                return;
            }
            if (microtime(true) > $deadline) {
                $this->stop();

                throw new \RuntimeException('The PHP built-in server did not start: ' . $error);
            }
            usleep(20_000);
        }
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        foreach (glob($this->stateDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->stateDir);
    }

    /**
     * A local port nothing listens on (it was free a moment ago).
     */
    public static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new \RuntimeException('Could not find a free port.');
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
