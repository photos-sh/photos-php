<?php

declare(strict_types=1);

namespace PhotosSh\Tests\Unit;

use PhotosSh\Exception\InvalidInputException;
use PhotosSh\Input;
use PHPUnit\Framework\TestCase;

final class InputTest extends TestCase
{
    public function testFromPath(): void
    {
        $path = dirname(__DIR__) . '/fixtures/tiny.png';
        $input = Input::fromPath($path);

        self::assertSame('tiny.png', $input->filename);
        self::assertSame(file_get_contents($path), $input->contents());
        self::assertSame('other.png', Input::fromPath($path, 'other.png')->filename);
    }

    public function testFromBytes(): void
    {
        $input = Input::fromBytes("\x00\x01binary");

        self::assertSame('image', $input->filename);
        self::assertSame("\x00\x01binary", $input->contents());
    }

    public function testFromStreamReadsTheRest(): void
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, 'skip-keep');
        fseek($stream, 5);

        self::assertSame('keep', Input::fromStream($stream)->contents());
        fclose($stream);
    }

    public function testFromStreamRefusesAClosedStream(): void
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        fclose($stream);

        $this->expectException(InvalidInputException::class);

        Input::fromStream($stream);
    }

    public function testAClosedStreamCannotBeRead(): void
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $input = Input::fromStream($stream);
        fclose($stream);

        $this->expectException(InvalidInputException::class);

        $input->contents();
    }

    public function testAMissingFileCannotBeRead(): void
    {
        $this->expectException(InvalidInputException::class);

        Input::fromPath(__DIR__ . '/no-such-file.png')->contents();
    }

    public function testADirectoryCannotBeRead(): void
    {
        $this->expectException(InvalidInputException::class);

        Input::fromPath(__DIR__)->contents();
    }

    public function testAnEmptyPathIsRefused(): void
    {
        $this->expectException(InvalidInputException::class);

        Input::fromPath('');
    }
}
