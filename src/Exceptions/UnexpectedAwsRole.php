<?php

declare(strict_types=1);

namespace LaravelAwsSso\Exceptions;

use LaravelAwsSso\Aws\AwsIdentity;
use RuntimeException;

final class UnexpectedAwsRole extends RuntimeException implements LaravelAwsSsoException
{
    public static function make(string $profile, AwsIdentity $identity, string $expected): self
    {
        return new self(implode(PHP_EOL, [
            'AWS profile ['.$profile.'] authenticated as an unexpected role.',
            'Expected permission set or role: '.$expected,
            'Actual permission set or role: '.self::describe($identity),
            'Actual identity: '.$identity->arn,
        ]));
    }

    /**
     * Name what the identity actually is, so a near miss is obvious.
     *
     * An identity that is not an assumed role — an IAM user or the account
     * root — can never satisfy a role guardrail, and saying so is more useful
     * than reporting an empty role name.
     */
    private static function describe(AwsIdentity $identity): string
    {
        return $identity->permissionSetName()
            ?? $identity->roleName()
            ?? 'none (this identity is not an assumed role)';
    }
}
