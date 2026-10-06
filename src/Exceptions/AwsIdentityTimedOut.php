<?php

declare(strict_types=1);

namespace LaravelAwsSso\Exceptions;

use RuntimeException;
use Throwable;

final class AwsIdentityTimedOut extends RuntimeException implements LaravelAwsSsoException
{
    public static function make(string $profile, int $seconds, Throwable $previous): self
    {
        return new self(
            "AWS identity check for profile [{$profile}] timed out after {$seconds} seconds. "
            .'Authentication status could not be determined.',
            previous: $previous,
        );
    }
}
