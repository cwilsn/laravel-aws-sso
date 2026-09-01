<?php

declare(strict_types=1);

namespace LaravelAwsSso\Auth;

use LaravelAwsSso\Aws\AwsIdentity;

/**
 * The outcome of an authentication attempt.
 *
 * `$reauthenticated` lets callers stay quiet on the happy path and only
 * report success when the developer actually went through a browser login.
 */
final readonly class AuthenticationResult
{
    public function __construct(
        public string $profile,
        public AwsIdentity $identity,
        public bool $reauthenticated,
    ) {}
}
