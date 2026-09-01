<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use LaravelAwsSso\Auth\AwsSsoAuthenticator;
use LaravelAwsSso\Aws\AwsIdentity;
use LaravelAwsSso\Exceptions\AwsAuthenticationFailed;
use LaravelAwsSso\Exceptions\AwsCliNotFound;
use LaravelAwsSso\Exceptions\StaticCredentialsDetected;
use LaravelAwsSso\Exceptions\UnexpectedAwsAccount;
use LaravelAwsSso\Exceptions\UnexpectedAwsRole;
use LaravelAwsSso\Support\StaticCredentials;
use LaravelAwsSso\Tests\Fixtures\FakeAwsCli;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The package's own config file, so tests exercise the shipped defaults.
 *
 * @return array<string, mixed>
 */
function packageConfig(): array
{
    /** @var array<string, mixed> $config */
    $config = require __DIR__.'/../../config/aws-sso.php';

    return $config;
}

/**
 * @param  array<string, mixed>  $config
 */
function authenticator(FakeAwsCli $cli, array $config = []): AwsSsoAuthenticator
{
    return new AwsSsoAuthenticator(
        $cli,
        new Repository(['aws-sso' => array_merge(
            packageConfig(),
            ['profile' => 'my-dev-profile', 'expected_account_id' => null, 'expected_role' => null],
            $config,
        )]),
        new StaticCredentials,
    );
}

describe('profile resolution', function (): void {
    it('prefers an explicit override', function (): void {
        expect(authenticator(new FakeAwsCli)->profile('other'))->toBe('other');
    });

    it('falls back to the configured profile', function (): void {
        expect(authenticator(new FakeAwsCli)->profile())->toBe('my-dev-profile');
    });

    it('falls back to default when nothing is configured', function (mixed $configured): void {
        expect(authenticator(new FakeAwsCli, ['profile' => $configured])->profile())->toBe('default');
    })->with([
        'null' => [null],
        'empty string' => [''],
        'whitespace' => ['   '],
    ]);

    it('ignores a blank override', function (): void {
        expect(authenticator(new FakeAwsCli)->profile('  '))->toBe('my-dev-profile');
    });

    it('trims the resolved profile', function (): void {
        expect(authenticator(new FakeAwsCli, ['profile' => '  spaced  '])->profile())->toBe('spaced');
    });
});

describe('happy path', function (): void {
    it('does not log in when the session is already valid', function (): void {
        $cli = FakeAwsCli::authenticated();

        $result = authenticator($cli)->ensureAuthenticated();

        expect($result->reauthenticated)->toBeFalse()
            ->and($result->profile)->toBe('my-dev-profile')
            ->and($cli->loginCalls)->toBe([])
            ->and($cli->identityCalls)->toBe(['my-dev-profile']);
    });

    it('writes no output when the session is already valid', function (): void {
        $output = new BufferedOutput;

        authenticator(FakeAwsCli::authenticated())->ensureAuthenticated(output: $output);

        expect($output->fetch())->toBe('');
    });

    it('passes an override profile through to the cli', function (): void {
        $cli = FakeAwsCli::authenticated();

        authenticator($cli)->ensureAuthenticated(profile: 'other-profile');

        expect($cli->identityCalls)->toBe(['other-profile']);
    });
});

describe('expired session', function (): void {
    it('logs in and re-verifies when the session has expired', function (): void {
        $cli = (new FakeAwsCli)->queueExpiredSession()->queueIdentity(FakeAwsCli::sampleIdentity());

        $result = authenticator($cli)->ensureAuthenticated();

        expect($result->reauthenticated)->toBeTrue()
            ->and($cli->loginCalls)->toBe(['my-dev-profile'])
            ->and($cli->identityCalls)->toBe(['my-dev-profile', 'my-dev-profile']);
    });

    it('announces the login before blocking on the browser', function (): void {
        $output = new BufferedOutput;
        $cli = (new FakeAwsCli)->queueExpiredSession()->queueIdentity(FakeAwsCli::sampleIdentity());

        authenticator($cli)->ensureAuthenticated(output: $output);

        expect($output->fetch())->toContain('AWS SSO session for [my-dev-profile] has expired. Signing in...');
    });

    it('fails when the login command fails', function (): void {
        $cli = (new FakeAwsCli)->queueExpiredSession();
        $cli->loginFailure = AwsAuthenticationFailed::loginFailed('my-dev-profile', 'The config profile could not be found');

        expect(fn () => authenticator($cli)->ensureAuthenticated())
            ->toThrow(AwsAuthenticationFailed::class, 'aws configure sso --profile my-dev-profile');
    });

    it('fails when the identity check still fails after a successful login', function (): void {
        $cli = (new FakeAwsCli)->queueExpiredSession()->queueExpiredSession();

        expect(fn () => authenticator($cli)->ensureAuthenticated())
            ->toThrow(AwsAuthenticationFailed::class, 'Unable to authenticate AWS profile [my-dev-profile]');

        expect($cli->loginCalls)->toBe(['my-dev-profile']);
    });

    it('reuses the raw aws output rather than nesting formatted messages', function (): void {
        $cli = (new FakeAwsCli)->queueExpiredSession()->queueExpiredSession();

        expect(fn () => authenticator($cli)->ensureAuthenticated())
            ->toThrow(function (AwsAuthenticationFailed $e): void {
                expect($e->getMessage())
                    ->toContain('The SSO session associated with this profile has expired.')
                    ->and($e->getMessage())->not->toContain('does not have a usable IAM Identity Center session')
                    ->and($e->getPrevious())->toBeInstanceOf(AwsAuthenticationFailed::class);
            });
    });
});

describe('non-interactive invocations', function (): void {
    it('refuses to open a browser when artisan is non-interactive', function (): void {
        $cli = (new FakeAwsCli)->queueExpiredSession();

        expect(fn () => authenticator($cli)->ensureAuthenticated(interactive: false))
            ->toThrow(AwsAuthenticationFailed::class, 'running non-interactively');

        expect($cli->loginCalls)->toBe([]);
    });

    it('still succeeds non-interactively when the session is valid', function (): void {
        $result = authenticator(FakeAwsCli::authenticated())->ensureAuthenticated(interactive: false);

        expect($result->reauthenticated)->toBeFalse();
    });
});

describe('aws cli availability', function (): void {
    it('fails with an actionable message when the aws cli is missing', function (): void {
        $cli = FakeAwsCli::authenticated();
        $cli->installed = false;

        expect(fn () => authenticator($cli)->ensureAuthenticated())
            ->toThrow(AwsCliNotFound::class, 'AWS CLI v2 was not found.');

        expect($cli->identityCalls)->toBe([]);
    });
});

describe('static credentials', function (): void {
    beforeEach(function (): void {
        $this->setEnvironmentVariable(StaticCredentials::ACCESS_KEY_ID, 'AKIAEXAMPLE');
        $this->setEnvironmentVariable(StaticCredentials::SECRET_ACCESS_KEY, 'super-secret-value');
    });

    it('fails closed by default', function (): void {
        $cli = FakeAwsCli::authenticated();

        expect(fn () => authenticator($cli)->ensureAuthenticated())
            ->toThrow(StaticCredentialsDetected::class, 'Static AWS credentials are configured in the environment.');

        expect($cli->identityCalls)->toBe([]);
    });

    it('never prints the credential values', function (): void {
        expect(fn () => authenticator(FakeAwsCli::authenticated())->ensureAuthenticated())
            ->toThrow(function (StaticCredentialsDetected $e): void {
                expect($e->getMessage())->not->toContain('super-secret-value')
                    ->and($e->getMessage())->not->toContain('AKIAEXAMPLE');
            });
    });

    it('warns and continues when configured to do so', function (): void {
        $output = new BufferedOutput;
        $cli = FakeAwsCli::authenticated();

        $result = authenticator($cli, ['fail_on_static_credentials' => false])
            ->ensureAuthenticated(output: $output);

        expect($result->identity->account)->toBe('123456789012')
            ->and($output->fetch())->toContain('Static AWS credentials are configured.')
            ->and($cli->identityCalls)->toBe(['my-dev-profile']);
    });
});

describe('identity guardrails', function (): void {
    it('accepts a matching account', function (): void {
        $result = authenticator(FakeAwsCli::authenticated(), ['expected_account_id' => '123456789012'])
            ->ensureAuthenticated();

        expect($result->identity->account)->toBe('123456789012');
    });

    it('rejects a mismatched account', function (): void {
        expect(fn () => authenticator(FakeAwsCli::authenticated(), ['expected_account_id' => '999999999999'])->ensureAuthenticated())
            ->toThrow(UnexpectedAwsAccount::class, 'authenticated to account [123456789012]; expected [999999999999]');
    });

    it('compares account ids as strings', function (): void {
        $result = authenticator(FakeAwsCli::authenticated(), ['expected_account_id' => 123456789012])
            ->ensureAuthenticated();

        expect($result->identity->account)->toBe('123456789012');
    });

    it('accepts a role name contained in the assumed-role arn', function (): void {
        $result = authenticator(FakeAwsCli::authenticated(), ['expected_role' => 'LaravelDeveloper'])
            ->ensureAuthenticated();

        expect($result->identity->arn)->toContain('LaravelDeveloper');
    });

    it('rejects an unexpected role', function (): void {
        $cli = FakeAwsCli::authenticated(FakeAwsCli::sampleIdentity(role: 'AWSReservedSSO_AdministratorAccess_9f8e7d'));

        expect(fn () => authenticator($cli, ['expected_role' => 'LaravelDeveloper'])->ensureAuthenticated())
            ->toThrow(UnexpectedAwsRole::class, 'Expected role to contain: LaravelDeveloper');
    });

    it('matches the role case sensitively', function (): void {
        expect(fn () => authenticator(FakeAwsCli::authenticated(), ['expected_role' => 'laraveldeveloper'])->ensureAuthenticated())
            ->toThrow(UnexpectedAwsRole::class);
    });

    it('skips guardrails that are not configured', function (mixed $blank): void {
        $result = authenticator(FakeAwsCli::authenticated(), [
            'expected_account_id' => $blank,
            'expected_role' => $blank,
        ])->ensureAuthenticated();

        expect($result->identity->account)->toBe('123456789012');
    })->with([
        'null' => [null],
        'empty string' => [''],
        'whitespace' => ['   '],
    ]);

    it('runs guardrails after an interactive login too', function (): void {
        $cli = (new FakeAwsCli)
            ->queueExpiredSession()
            ->queueIdentity(FakeAwsCli::sampleIdentity(account: '999999999999'));

        expect(fn () => authenticator($cli, ['expected_account_id' => '123456789012'])->ensureAuthenticated())
            ->toThrow(UnexpectedAwsAccount::class);
    });

    it('can verify an identity without authenticating', function (): void {
        $authenticator = authenticator(new FakeAwsCli, ['expected_account_id' => '123456789012']);

        $authenticator->verify(new AwsIdentity('u', '123456789012', 'arn'), 'my-dev-profile');

        expect(fn () => $authenticator->verify(new AwsIdentity('u', '1', 'arn'), 'my-dev-profile'))
            ->toThrow(UnexpectedAwsAccount::class);
    });
});

describe('malformed guardrail configuration', function (): void {
    it('ignores guardrail values that are not usable strings', function (mixed $value): void {
        $result = authenticator(FakeAwsCli::authenticated(), [
            'expected_account_id' => $value,
            'expected_role' => $value,
        ])->ensureAuthenticated();

        expect($result->identity->account)->toBe('123456789012');
    })->with([
        'true' => [true],
        'false' => [false],
        'array' => [['123456789012']],
        'object' => [new stdClass],
    ]);

    it('coerces a numeric account id from the config file', function (): void {
        $result = authenticator(FakeAwsCli::authenticated(), ['expected_account_id' => 123456789012])
            ->ensureAuthenticated();

        expect($result->profile)->toBe('my-dev-profile');
    });

    it('trims whitespace around a configured guardrail', function (): void {
        $result = authenticator(FakeAwsCli::authenticated(), [
            'expected_account_id' => '  123456789012  ',
            'expected_role' => "\tLaravelDeveloper\n",
        ])->ensureAuthenticated();

        expect($result->identity->account)->toBe('123456789012');
    });
});

describe('static credential configuration', function (): void {
    it('treats a missing fail_on_static_credentials key as fail closed', function (): void {
        $this->setEnvironmentVariable(StaticCredentials::ACCESS_KEY_ID, 'AKIAEXAMPLE');
        $this->setEnvironmentVariable(StaticCredentials::SECRET_ACCESS_KEY, 'secret');

        $authenticator = new AwsSsoAuthenticator(
            FakeAwsCli::authenticated(),
            new Repository(['aws-sso' => ['profile' => 'my-dev-profile']]),
            new StaticCredentials,
        );

        expect(fn () => $authenticator->ensureAuthenticated())
            ->toThrow(StaticCredentialsDetected::class);
    });

    it('checks static credentials before it ever looks for the aws cli', function (): void {
        $this->setEnvironmentVariable(StaticCredentials::ACCESS_KEY_ID, 'AKIAEXAMPLE');
        $this->setEnvironmentVariable(StaticCredentials::SECRET_ACCESS_KEY, 'secret');

        $cli = new FakeAwsCli;
        $cli->installed = false;

        // The static credential message is the actionable one, so it must not be
        // masked by an unrelated "install the AWS CLI" failure.
        expect(fn () => authenticator($cli)->ensureAuthenticated())
            ->toThrow(StaticCredentialsDetected::class);
    });
});
