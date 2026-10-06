<?php

declare(strict_types=1);

namespace LaravelAwsSso\Tests\Fixtures;

use LaravelAwsSso\Aws\AwsCli;
use LaravelAwsSso\Aws\AwsIdentity;
use LaravelAwsSso\Exceptions\AwsAuthenticationFailed;
use LaravelAwsSso\Exceptions\AwsIdentityTimedOut;
use LogicException;
use RuntimeException;

/**
 * A scriptable AWS CLI double.
 *
 * Identity results are queued so a test can express "expired, then valid"
 * without reaching for a mocking framework.
 */
final class FakeAwsCli implements AwsCli
{
    public bool $installed = true;

    /** @var list<string> */
    public array $loginCalls = [];

    /** @var list<string> */
    public array $identityCalls = [];

    public ?AwsAuthenticationFailed $loginFailure = null;

    /** @var list<AwsIdentity|AwsAuthenticationFailed|AwsIdentityTimedOut> */
    private array $identityResults = [];

    public static function authenticated(?AwsIdentity $identity = null): self
    {
        return (new self)->queueIdentity($identity ?? self::sampleIdentity());
    }

    public static function sampleIdentity(
        string $account = '123456789012',
        string $role = 'AWSReservedSSO_LaravelDeveloper_0a1b2c3d4e5f',
    ): AwsIdentity {
        return new AwsIdentity(
            'AROAEXAMPLEID:developer',
            $account,
            "arn:aws:sts::{$account}:assumed-role/{$role}/developer",
        );
    }

    public function queueIdentity(AwsIdentity|AwsAuthenticationFailed|AwsIdentityTimedOut $result): self
    {
        $this->identityResults[] = $result;

        return $this;
    }

    public function queueExpiredSession(): self
    {
        return $this->queueIdentity(
            AwsAuthenticationFailed::identityUnavailable('fake', 'The SSO session associated with this profile has expired.')
        );
    }

    public function queueIdentityTimeout(): self
    {
        return $this->queueIdentity(
            AwsIdentityTimedOut::make('my-dev-profile', 15, new RuntimeException('Process timed out.'))
        );
    }

    public function isInstalled(): bool
    {
        return $this->installed;
    }

    public function identity(string $profile): AwsIdentity
    {
        $this->identityCalls[] = $profile;

        $result = array_shift($this->identityResults);

        if ($result === null) {
            throw new LogicException('FakeAwsCli::identity() was called more times than results were queued.');
        }

        if ($result instanceof AwsAuthenticationFailed || $result instanceof AwsIdentityTimedOut) {
            throw $result;
        }

        return $result;
    }

    public function login(string $profile, ?callable $onOutput = null): void
    {
        $this->loginCalls[] = $profile;

        if ($this->loginFailure !== null) {
            throw $this->loginFailure;
        }
    }
}
