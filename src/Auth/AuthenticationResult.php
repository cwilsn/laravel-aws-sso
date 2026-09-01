<?php

declare(strict_types=1);

namespace LaravelAwsSso\Auth;

use LaravelAwsSso\Aws\AwsIdentity;

/**
 * The outcome of an authentication attempt.
 *
 * `$reauthenticated` lets callers stay quiet on the happy path and only
 * report success when the developer actually went through a browser login.
 *
 * `$shadowedByStaticCredentials` marks the case where the profile authenticated
 * but the AWS SDK will resolve environment credentials instead. `$identity` is
 * then the profile's identity, not the one the application will run as, and it
 * must not be reported as though it were.
 */
final readonly class AuthenticationResult
{
    public function __construct(
        public string $profile,
        public AwsIdentity $identity,
        public bool $reauthenticated,
        public bool $shadowedByStaticCredentials = false,
    ) {}
}
