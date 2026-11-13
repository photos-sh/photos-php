<?php

declare(strict_types=1);

namespace PhotosSh\Exception;

/**
 * {@see \PhotosSh\Photos::waitForJob()} gave up: the job did not complete in
 * time. The job itself keeps running.
 */
class WaitTimeoutException extends PhotosException
{
    /**
     * @param array<string, mixed> $job the job as last seen
     */
    public function __construct(string $message, public readonly array $job = [])
    {
        parent::__construct($message);
    }
}
