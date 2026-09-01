<?php

declare(strict_types=1);

namespace LaravelAwsSso\Aws;

use LaravelAwsSso\Exceptions\AwsAuthenticationFailed;

/**
 * The only seam through which this package talks to the AWS CLI.
 */
interface AwsCli
{
    /**
     * Whether the `aws` executable can be run at all.
     */
    public function isInstalled(): bool;

    /**
     * Resolve the identity the profile currently authenticates as.
     *
     * @throws AwsAuthenticationFailed when the profile has no usable session
     */
    public function identity(string $profile): AwsIdentity;

    /**
     * Start an interactive IAM Identity Center browser login.
     *
     * @param  (callable(string, string): void)|null  $onOutput  receives CLI output when the process is not attached to a TTY
     *
     * @throws AwsAuthenticationFailed when the login command fails
     */
    public function login(string $profile, ?callable $onOutput = null): void;
}
