<?php

declare(strict_types=1);

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use LaravelAwsSso\Auth\AwsSsoAuthenticator;
use LaravelAwsSso\Aws\AwsCli;
use LaravelAwsSso\Exceptions\AwsAuthenticationFailed;
use LaravelAwsSso\Exceptions\StaticCredentialsDetected;
use LaravelAwsSso\Listeners\EnsureAwsSsoAuthentication;
use LaravelAwsSso\Support\StaticCredentials;
use LaravelAwsSso\Tests\Fixtures\FakeAwsCli;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;

function fakeCli(FakeAwsCli $cli): FakeAwsCli
{
    app()->instance(AwsCli::class, $cli);

    return $cli;
}

/**
 * Dispatch the event Artisan fires just before a command executes.
 */
function startCommand(string $command = 'dev', ?InputInterface $input = null): BufferedOutput
{
    event(new CommandStarting($command, $input ?? new ArrayInput([]), $output = new BufferedOutput));

    return $output;
}

beforeEach(function (): void {
    $this->app['env'] = 'local';
    config(['aws-sso.profile' => 'my-dev-profile']);
});

describe('scope', function (): void {
    it('does nothing for commands outside the configured list', function (string $command): void {
        $cli = fakeCli(new FakeAwsCli);

        startCommand($command);

        expect($cli->identityCalls)->toBe([]);
    })->with(['migrate', 'route:list', 'test', 'queue:work', 'serve', '']);

    it('does nothing outside the configured environments', function (string $environment): void {
        $this->app['env'] = $environment;
        $cli = fakeCli(new FakeAwsCli);

        startCommand();

        expect($cli->identityCalls)->toBe([]);
    })->with(['production', 'staging', 'testing']);

    it('does nothing when the package is disabled', function (): void {
        config(['aws-sso.enabled' => false]);
        $cli = fakeCli(new FakeAwsCli);

        startCommand();

        expect($cli->identityCalls)->toBe([]);
    });

    it('runs for a command the developer opted into', function (): void {
        config(['aws-sso.commands' => ['dev', 'queue:work']]);
        $cli = fakeCli(FakeAwsCli::authenticated());

        startCommand('queue:work');

        expect($cli->identityCalls)->toBe(['my-dev-profile']);
    });

    it('authenticates exactly once for dev in local', function (): void {
        $cli = fakeCli(FakeAwsCli::authenticated());

        startCommand();

        expect($cli->identityCalls)->toBe(['my-dev-profile'])
            ->and($cli->loginCalls)->toBe([]);
    });

    it('does nothing when the application is not running in console', function (): void {
        $cli = fakeCli(new FakeAwsCli);

        $app = Mockery::mock(Application::class)->makePartial();
        $app->shouldReceive('runningInConsole')->andReturnFalse();
        $app->shouldReceive('environment')->andReturn('local');

        $listener = new EnsureAwsSsoAuthentication(
            app(AwsSsoAuthenticator::class),
            app(Repository::class),
            $app,
        );

        $listener->handle(new CommandStarting('dev', new ArrayInput([]), new BufferedOutput));

        expect($cli->identityCalls)->toBe([]);
    });

    it('tolerates non-list configuration values', function (): void {
        config(['aws-sso.commands' => 'dev', 'aws-sso.environments' => 'local']);
        $cli = fakeCli(new FakeAwsCli);

        startCommand();

        expect($cli->identityCalls)->toBe([]);
    });
});

describe('output', function (): void {
    it('stays silent when the session is already valid', function (): void {
        fakeCli(FakeAwsCli::authenticated());

        expect(startCommand()->fetch())->toBe('');
    });

    it('reports the identity after an interactive login', function (): void {
        fakeCli((new FakeAwsCli)->queueExpiredSession()->queueIdentity(FakeAwsCli::sampleIdentity()));

        $output = startCommand()->fetch();

        expect($output)->toContain('AWS SSO session for [my-dev-profile] has expired. Signing in...')
            ->and($output)->toContain('AWS authenticated with [my-dev-profile]: arn:aws:sts::123456789012:assumed-role/');
    });

    it('omits the arn when identity reporting is disabled', function (): void {
        config(['aws-sso.show_identity_after_login' => false]);
        fakeCli((new FakeAwsCli)->queueExpiredSession()->queueIdentity(FakeAwsCli::sampleIdentity()));

        $output = startCommand()->fetch();

        expect($output)->toContain('AWS authenticated with [my-dev-profile].')
            ->and($output)->not->toContain('assumed-role');
    });
});

describe('failures stop the command', function (): void {
    it('propagates an authentication failure', function (): void {
        $cli = fakeCli((new FakeAwsCli)->queueExpiredSession()->queueExpiredSession());

        expect(fn () => startCommand())
            ->toThrow(AwsAuthenticationFailed::class, 'Unable to authenticate AWS profile [my-dev-profile]');
    });

    it('propagates a static credential failure', function (): void {
        $this->setEnvironmentVariable(StaticCredentials::ACCESS_KEY_ID, 'AKIAEXAMPLE');
        $this->setEnvironmentVariable(StaticCredentials::SECRET_ACCESS_KEY, 'secret');
        fakeCli(FakeAwsCli::authenticated());

        expect(fn () => startCommand())->toThrow(StaticCredentialsDetected::class);
    });
});

describe('interactivity', function (): void {
    it('refuses to open a browser when --no-interaction was passed', function (string $flag): void {
        $cli = fakeCli((new FakeAwsCli)->queueExpiredSession());

        expect(fn () => startCommand('dev', new ArgvInput(['artisan', 'dev', $flag])))
            ->toThrow(AwsAuthenticationFailed::class, 'running non-interactively');

        expect($cli->loginCalls)->toBe([]);
    })->with(['--no-interaction', '-n']);

    it('refuses to open a browser when the input is already marked non-interactive', function (): void {
        $input = new ArrayInput([]);
        $input->setInteractive(false);

        fakeCli((new FakeAwsCli)->queueExpiredSession());

        expect(fn () => startCommand('dev', $input))
            ->toThrow(AwsAuthenticationFailed::class, 'running non-interactively');
    });

    it('allows a login for ordinary interactive input', function (): void {
        $cli = fakeCli((new FakeAwsCli)->queueExpiredSession()->queueIdentity(FakeAwsCli::sampleIdentity()));

        startCommand('dev', new ArgvInput(['artisan', 'dev']));

        expect($cli->loginCalls)->toBe(['my-dev-profile']);
    });
});
