<?php

declare(strict_types=1);

namespace LaravelAwsSso\Exceptions;

use RuntimeException;

final class StaticCredentialsDetected extends RuntimeException implements LaravelAwsSsoException
{
    /**
     * Build the exception for a profile that is being shadowed by static credentials.
     *
     * Never include the credential values themselves, only the variable names.
     */
    public static function make(string $profile): self
    {
        return new self(implode(PHP_EOL, [
            'Static AWS credentials are configured in the environment.',
            'AWS SDKs prefer AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY over the SSO profile ['.$profile.'].',
            'Remove those variables from your .env so the SDK can use the profile.',
            'Set `aws-sso.fail_on_static_credentials` to false to downgrade this to a warning.',
        ]));
    }
}
