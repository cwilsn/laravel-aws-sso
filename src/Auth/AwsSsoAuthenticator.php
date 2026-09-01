<?php

declare(strict_types=1);

namespace LaravelAwsSso\Auth;

use Illuminate\Contracts\Config\Repository as Config;
use LaravelAwsSso\Aws\AwsCli;
use LaravelAwsSso\Aws\AwsIdentity;
use LaravelAwsSso\Exceptions\AwsAuthenticationFailed;
use LaravelAwsSso\Exceptions\AwsCliNotFound;
use LaravelAwsSso\Exceptions\InvalidGuardrailConfiguration;
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
final readonly class AwsSsoAuthenticator
{
    public function __construct(
        private AwsCli $cli,
        private Config $config,
        private StaticCredentials $staticCredentials,
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
     * @throws InvalidGuardrailConfiguration
     */
    public function ensureAuthenticated(
        ?string $profile = null,
        ?OutputInterface $output = null,
        bool $interactive = true,
    ): AuthenticationResult {
        $profile = $this->profile($profile);

        $shadowed = $this->guardStaticCredentials($profile, $output);

        if (! $this->cli->isInstalled()) {
            throw AwsCliNotFound::make();
        }

        try {
            $identity = $this->cli->identity($profile);
            $reauthenticated = false;
        } catch (AwsAuthenticationFailed) {
            $identity = $this->reauthenticate($profile, $output, $interactive);
            $reauthenticated = true;
        }

        $this->verify($identity, $profile);

        return new AuthenticationResult($profile, $identity, $reauthenticated, $shadowed);
    }

    /**
     * Sign the profile back in and confirm the new session is usable.
     *
     * @throws AwsAuthenticationFailed
     */
    private function reauthenticate(string $profile, ?OutputInterface $output, bool $interactive): AwsIdentity
    {
        if (! $interactive) {
            throw AwsAuthenticationFailed::nonInteractive($profile);
        }

        $output?->writeln("<comment>AWS SSO session for [{$profile}] has expired. Signing in...</comment>");

        $this->cli->login($profile, $this->streamTo($output));

        try {
            return $this->cli->identity($profile);
        } catch (AwsAuthenticationFailed $e) {
            throw AwsAuthenticationFailed::unverifiedAfterLogin($profile, $e, $e->cliOutput());
        }
    }

    /**
     * Apply the optional account and role guardrails to an identity.
     *
     * @throws UnexpectedAwsAccount
     * @throws UnexpectedAwsRole
     * @throws InvalidGuardrailConfiguration
     */
    public function verify(AwsIdentity $identity, string $profile): void
    {
        $expectedAccount = $this->expected('aws-sso.expected_account_id');

        if ($expectedAccount !== null && $identity->account !== $expectedAccount) {
            throw UnexpectedAwsAccount::make($profile, $identity->account, $expectedAccount);
        }

        $expectedRole = $this->expected('aws-sso.expected_role');

        if ($expectedRole !== null && ! $this->roleMatches($identity, $expectedRole)) {
            throw UnexpectedAwsRole::make($profile, $identity, $expectedRole);
        }
    }

    /**
     * Whether an identity is running as exactly the expected permission set.
     *
     * The comparison is against the role component of the ARN alone, never the
     * whole ARN. A substring test over the ARN would also match the account id
     * and the session name, and would accept any broader permission set whose
     * name merely starts with the expected one — `LaravelDeveloperAdmin`
     * contains `LaravelDeveloper`. Both spellings of the same role are
     * accepted: the Identity Center permission set name and the generated
     * `AWSReservedSSO_<permission set>_<suffix>` role name.
     */
    private function roleMatches(AwsIdentity $identity, string $expected): bool
    {
        return $expected === $identity->permissionSetName()
            || $expected === $identity->roleName();
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
     * Decide whether to continue when static credentials shadow the profile.
     *
     * Returns true when the run is continuing even though the AWS SDK will
     * resolve environment credentials rather than the profile, so callers can
     * report the identity honestly instead of implying the application is
     * running as the profile.
     *
     * @throws StaticCredentialsDetected
     * @throws InvalidGuardrailConfiguration
     */
    private function guardStaticCredentials(string $profile, ?OutputInterface $output): bool
    {
        if (! $this->staticCredentials->detected()) {
            return false;
        }

        if ($this->config->get('aws-sso.fail_on_static_credentials', true)) {
            throw StaticCredentialsDetected::make($profile);
        }

        // A guardrail that cannot be enforced fails closed. Verifying the
        // profile here would assert about an identity the application is not
        // going to use, which is worse than no guardrail at all because it
        // reads as a passing check.
        if ($this->guardrailsConfigured()) {
            throw StaticCredentialsDetected::shadowingGuardrails($profile);
        }

        $output?->writeln('<comment>'.$this->staticCredentials->shadowWarning($profile).'</comment>');

        return true;
    }

    /**
     * Whether either identity guardrail is switched on.
     *
     * Public so a caller that verifies an identity directly can tell whether a
     * guardrail is in play before trusting the result.
     *
     * @throws InvalidGuardrailConfiguration
     */
    public function guardrailsConfigured(): bool
    {
        return $this->expected('aws-sso.expected_account_id') !== null
            || $this->expected('aws-sso.expected_role') !== null;
    }

    /**
     * Read a guardrail value, treating an unusable one as a configuration error.
     *
     * `null` and `false` are the two ways of spelling "guardrail off"; anything
     * else that cannot become a comparable string is a mistake. Returning null
     * for it would silently disable a security control, so it throws instead.
     *
     * @throws InvalidGuardrailConfiguration
     */
    private function expected(string $key): ?string
    {
        $value = $this->config->get($key);

        if ($value === null || $value === false) {
            return null;
        }

        if (! is_scalar($value) || is_bool($value)) {
            throw InvalidGuardrailConfiguration::make($key, get_debug_type($value));
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
