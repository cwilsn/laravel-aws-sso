<?php

declare(strict_types=1);

namespace LaravelAwsSso\Exceptions;

use RuntimeException;

final class UnexpectedAwsAccount extends RuntimeException implements LaravelAwsSsoException
{
    public static function make(string $profile, string $actual, string $expected): self
    {
        return new self(
            'AWS profile ['.$profile.'] authenticated to account ['.$actual.']; expected ['.$expected.'].'
        );
    }
}
