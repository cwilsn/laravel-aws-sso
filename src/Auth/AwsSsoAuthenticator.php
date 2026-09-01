<?php

declare(strict_types=1);

namespace LaravelAwsSso\Auth;

use Illuminate\Contracts\Config\Repository as Config;
use LaravelAwsSso\Aws\AwsCli;
use LaravelAwsSso\Aws\AwsIdentity;
use LaravelAwsSso\Exceptions\AwsAuthenticationFailed;
use LaravelAwsSso\Exceptions\AwsCliNotFound;
use LaravelAwsSso\Exceptions\StaticCredentialsDetected;
use LaravelAwsSso\Exceptions\UnexpectedAwsAccount;
use LaravelAwsSso\Exceptions\UnexpectedAwsRole;
use LaravelAwsSso\Support\StaticCredentials;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Makes sure the configured AWS profile has a usable IAM Identity Center session.
 *
 * This class owns the decision making. It delegates every process invocation to
 * {@see AwsCli} and never touches credential files, token caches, or the
 * developer's AWS configuration.
 */
final class AwsSsoAuthenticator
{
    public function __construct(
        private readonly AwsCli $cli,
        private readonly Config $config,
        private readonly StaticCredentials $staticCredentials,
    ) {}

    /**
     * Resolve the AWS profile to use, falling back to `default`.
     */
    public function profile(?string $override = null): string
    {
        foreach ([$override, $this->config->get('aws-sso.profile')] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return 'default';
    }

    /**
     * Guarantee an authenticated, policy-compliant AWS session.
     *
     * Output, when given, is used only for the notices that have to appear
     * before a blocking browser login. Success reporting is left to the caller.
     *
     * @throws AwsCliNotFound
     * @throws StaticCredentialsDetected
     * @throws AwsAuthenticationFailed
     * @throws UnexpectedAwsAccount
     * @throws UnexpectedAwsRole
     */
    public function ensureAuthenticated(
        ?string $profile = null,
        ?OutputInterface $output = null,
        bool $interactive = true,
    ): AuthenticationResult {
        $profile = $this->profile($profile);

        $this->guardStaticCredentials($profile, $output);

        if (! $this->cli->isInstalled()) {
            throw AwsCliNotFound::make();
        }

        $reauthenticated = false;

        try {
            $identity = $this->cli->identity($profile);
        } catch (AwsAuthenticationFailed) {
            if (! $interactive) {
                throw AwsAuthenticationFailed::nonInteractive($profile);
            }

            $output?->writeln("<comment>AWS SSO session for [{$profile}] has expired. Signing in...</comment>");

            $this->cli->login($profile, $this->streamTo($output));

            $reauthenticated = true;

            try {
                $identity = $this->cli->identity($profile);
            } catch (AwsAuthenticationFailed $e) {
                throw AwsAuthenticationFailed::unverifiedAfterLogin($profile, $e, $e->cliOutput());
            }
        }

        $this->verify($identity, $profile);

        return new AuthenticationResult($profile, $identity, $reauthenticated);
    }

    /**
     * Apply the optional account and role guardrails to an identity.
     *
     * @throws UnexpectedAwsAccount
     * @throws UnexpectedAwsRole
     */
    public function verify(AwsIdentity $identity, string $profile): void
    {
        $expectedAccount = $this->expected('aws-sso.expected_account_id');

        if ($expectedAccount !== null && $identity->account !== $expectedAccount) {
            throw UnexpectedAwsAccount::make($profile, $identity->account, $expectedAccount);
        }

        $expectedRole = $this->expected('aws-sso.expected_role');

        // Identity Center generates role names such as
        // `AWSReservedSSO_LaravelDeveloper_0a1b2c3d4e5f`, so the permission set
        // name is matched as a substring of the assumed-role ARN.
        if ($expectedRole !== null && ! str_contains($identity->arn, $expectedRole)) {
            throw UnexpectedAwsRole::make($profile, $identity->arn, $expectedRole);
        }
    }

    /**
     * Forward AWS CLI output to the console when the process has no TTY of its own.
     *
     * @return (callable(string, string): void)|null
     */
    private function streamTo(?OutputInterface $output): ?callable
    {
        if ($output === null) {
            return null;
        }

        return static function (string $type, string $chunk) use ($output): void {
            // Raw, so AWS CLI output is never mistaken for console markup.
            $output->write($chunk, false, OutputInterface::OUTPUT_RAW);
        };
    }

    /**
     * @throws StaticCredentialsDetected
     */
    private function guardStaticCredentials(string $profile, ?OutputInterface $output): void
    {
        if (! $this->staticCredentials->detected()) {
            return;
        }

        if ($this->config->get('aws-sso.fail_on_static_credentials', true)) {
            throw StaticCredentialsDetected::make($profile);
        }

        $output?->writeln(
            '<comment>Static AWS credentials are configured. Remove '
            .StaticCredentials::ACCESS_KEY_ID.' and '.StaticCredentials::SECRET_ACCESS_KEY
            ." so the AWS SDK can use [{$profile}].</comment>"
        );
    }

    private function expected(string $key): ?string
    {
        $value = $this->config->get($key);

        if (! is_scalar($value) || is_bool($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
