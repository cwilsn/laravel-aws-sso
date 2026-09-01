<?php

declare(strict_types=1);

namespace LaravelAwsSso\Exceptions;

use RuntimeException;

final class UnexpectedAwsRole extends RuntimeException implements LaravelAwsSsoException
{
    public static function make(string $profile, string $arn, string $expected): self
    {
        return new self(implode(PHP_EOL, [
            'AWS profile ['.$profile.'] authenticated as an unexpected role.',
            'Expected role to contain: '.$expected,
            'Actual identity: '.$arn,
        ]));
    }
}
