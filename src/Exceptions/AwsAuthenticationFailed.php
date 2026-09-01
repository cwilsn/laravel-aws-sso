<?php

declare(strict_types=1);

namespace LaravelAwsSso\Exceptions;

use LaravelAwsSso\Support\ProcessOutput;
use RuntimeException;
use Throwable;

final class AwsAuthenticationFailed extends RuntimeException implements LaravelAwsSsoException
{
    /**
     * Raw AWS CLI output, kept so a follow-up failure can reuse it verbatim
     * instead of nesting one formatted message inside another.
     */
    private string $cliOutput = '';

    public function cliOutput(): string
    {
        return $this->cliOutput;
    }

    /**
     * The `aws sts get-caller-identity` call failed, so the session is not usable.
     *
     * This is the recoverable case: the caller may follow up with an SSO login.
     */
    public static function identityUnavailable(string $profile, string $output): self
    {
        return self::withOutput(
            'AWS profile ['.$profile.'] does not have a usable IAM Identity Center session.',
            $output,
        );
    }

    /**
     * `aws sso login` itself failed, most often a missing or misconfigured profile.
     */
    public static function loginFailed(string $profile, string $output): self
    {
        return self::withOutput(implode(PHP_EOL, [
            'AWS profile ['.$profile.'] could not be authenticated.',
            '',
            'Confirm it exists and is configured for IAM Identity Center:',
            '  aws configure sso --profile '.$profile,
        ]), $output);
    }

    /**
     * The login command reported success but the follow-up identity check did not.
     */
    public static function unverifiedAfterLogin(string $profile, Throwable $previous, string $output): self
    {
        return self::withOutput(implode(PHP_EOL, [
            'Unable to authenticate AWS profile ['.$profile.'].',
            'Run `aws sso login --profile '.$profile.'` to troubleshoot the AWS CLI configuration.',
        ]), $output, $previous);
    }

    /**
     * A login is required but Artisan was invoked without interactivity.
     */
    public static function nonInteractive(string $profile): self
    {
        return new self(implode(PHP_EOL, [
            'AWS SSO authentication is required, but Artisan is running non-interactively.',
            'Run `aws sso login --profile '.$profile.'` first.',
        ]));
    }

    /**
     * STS returned something that is not a caller identity document.
     *
     * The raw payload is deliberately omitted; it is not needed to act on this.
     */
    public static function malformedIdentity(): self
    {
        return new self(
            'Unable to read the identity returned by `aws sts get-caller-identity`. '
            .'Confirm the AWS CLI is v2 and returns JSON.'
        );
    }

    private static function withOutput(string $message, string $output, ?Throwable $previous = null): self
    {
        $excerpt = ProcessOutput::excerpt($output);

        $exception = new self(
            $excerpt === '' ? $message : $message.PHP_EOL.PHP_EOL.$excerpt,
            previous: $previous,
        );

        $exception->cliOutput = $output;

        return $exception;
    }
}
