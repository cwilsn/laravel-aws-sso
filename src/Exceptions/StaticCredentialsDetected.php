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

    /**
     * Static credentials are present while an identity guardrail is configured.
     *
     * The guardrails describe the identity the application must run as, but the
     * AWS SDK resolves environment credentials ahead of the profile. Verifying
     * the profile would assert against an identity the application is not going
     * to use, so this case fails closed even when
     * `fail_on_static_credentials` is off.
     */
    public static function shadowingGuardrails(string $profile): self
    {
        return new self(implode(PHP_EOL, [
            'Static AWS credentials are configured in the environment, so the account and role guardrails cannot be enforced.',
            'AWS SDKs prefer AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY over the SSO profile ['.$profile.'],',
            'which means the guardrails would check an identity the application will not use.',
            'Remove those variables from your .env, or unset `aws-sso.expected_account_id` and `aws-sso.expected_role`.',
            '`aws-sso.fail_on_static_credentials` does not downgrade this; a guardrail that cannot be enforced is an error.',
        ]));
    }
}
