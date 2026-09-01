<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Sleep;
use LaravelAwsSso\Aws\AwsCli;
use LaravelAwsSso\Exceptions\AwsAuthenticationFailed;
use LaravelAwsSso\Support\StaticCredentials;
use LaravelAwsSso\Tests\Fixtures\FakeAwsCli;
use Symfony\Component\Console\Output\BufferedOutput;

function watchCli(FakeAwsCli $cli): FakeAwsCli
{
    test()->swap(AwsCli::class, $cli);

    return $cli;
}

/**
 * Run the continuous watcher with fake sleeps, then interrupt its intentionally
 * endless loop after the requested number of intervals.
 */
function runWatcherUntilSleep(int $stopAfter): string
{
    $sleeps = 0;
    $stop = new RuntimeException('The watcher test reached its stopping point.');
    $output = new BufferedOutput;

    Sleep::fake();
    Sleep::whenFakingSleep(function () use (&$sleeps, $stop, $stopAfter): void {
        if (++$sleeps === $stopAfter) {
            throw $stop;
        }
    });

    try {
        app(Kernel::class)->call('aws-sso:watch', [], $output);
    } catch (RuntimeException $e) {
        expect($e)->toBe($stop);
    } finally {
        Sleep::fake(false);
    }

    return $output->fetch();
}

beforeEach(function (): void {
    $this->app['env'] = 'local';
    config([
        'aws-sso.profile' => 'my-dev-profile',
        'aws-sso.monitor' => true,
    ]);
});

it('checks a valid session without logging in', function (): void {
    $cli = watchCli(FakeAwsCli::authenticated());

    $this->artisan('aws-sso:watch', ['--once' => true])->assertSuccessful();

    expect($cli->identityCalls)->toBe(['my-dev-profile'])
        ->and($cli->loginCalls)->toBe([]);
});

it('logs in immediately when the watcher tab starts with an expired session', function (): void {
    $cli = watchCli(
        (new FakeAwsCli)
            ->queueExpiredSession()
            ->queueIdentity(FakeAwsCli::sampleIdentity()),
    );

    $this->artisan('aws-sso:watch', ['--once' => true])
        ->expectsOutputToContain('AWS SSO session for [my-dev-profile] has expired. Signing in...')
        ->expectsOutputToContain('AWS authenticated with [my-dev-profile]')
        ->assertSuccessful();

    expect($cli->identityCalls)->toBe(['my-dev-profile', 'my-dev-profile'])
        ->and($cli->loginCalls)->toBe(['my-dev-profile']);
});

it('waits for a tab restart instead of opening a browser during periodic checks', function (): void {
    $cli = watchCli(
        (new FakeAwsCli)
            ->queueIdentity(FakeAwsCli::sampleIdentity())
            ->queueExpiredSession()
            ->queueExpiredSession(),
    );

    $output = runWatcherUntilSleep(3);

    expect($output)
        ->toContain('AWS SSO session [my-dev-profile] is no longer usable.')
        ->toContain('Select the aws-sso tab and press r to sign in.')
        ->toContain('php artisan aws-sso:login')
        ->and(substr_count($output, 'press r to sign in.'))->toBe(1)
        ->and($cli->identityCalls)->toBe([
            'my-dev-profile',
            'my-dev-profile',
            'my-dev-profile',
        ])
        ->and($cli->loginCalls)->toBe([]);
});

it('notices when a waiting session is renewed in another terminal', function (): void {
    $cli = watchCli(
        (new FakeAwsCli)
            ->queueIdentity(FakeAwsCli::sampleIdentity())
            ->queueExpiredSession()
            ->queueIdentity(FakeAwsCli::sampleIdentity()),
    );

    $output = runWatcherUntilSleep(3);

    expect($output)
        ->toContain('press r to sign in.')
        ->toContain('AWS SSO session [my-dev-profile] is usable again.')
        ->and($cli->loginCalls)->toBe([]);
});

it('waits after an abandoned login instead of opening another browser', function (): void {
    $cli = watchCli(
        (new FakeAwsCli)
            ->queueExpiredSession()
            ->queueExpiredSession(),
    );
    $cli->loginFailure = AwsAuthenticationFailed::loginFailed(
        'my-dev-profile',
        'The device authorization timed out',
    );

    $output = runWatcherUntilSleep(2);

    expect($output)
        ->toContain('could not be authenticated')
        ->toContain('press r to sign in.')
        ->and($cli->loginCalls)->toBe(['my-dev-profile']);
});

it('reports a failed one-off login without leaking an exception', function (): void {
    $cli = watchCli((new FakeAwsCli)->queueExpiredSession());
    $cli->loginFailure = AwsAuthenticationFailed::loginFailed(
        'my-dev-profile',
        'The config profile (my-dev-profile) could not be found',
    );

    $this->artisan('aws-sso:watch', ['--once' => true])
        ->expectsOutputToContain('could not be authenticated')
        ->assertFailed();
});

it('does nothing when continuous monitoring is disabled', function (): void {
    config(['aws-sso.monitor' => false]);
    $cli = watchCli(new FakeAwsCli);

    $this->artisan('aws-sso:watch', ['--once' => true])->assertSuccessful();

    expect($cli->identityCalls)->toBe([])
        ->and($cli->loginCalls)->toBe([]);
});

it('does nothing outside the configured environments', function (): void {
    $this->app['env'] = 'production';
    $cli = watchCli(new FakeAwsCli);

    $this->artisan('aws-sso:watch', ['--once' => true])->assertSuccessful();

    expect($cli->identityCalls)->toBe([])
        ->and($cli->loginCalls)->toBe([]);
});

it('does not repeat a tolerated static credential warning on every check', function (): void {
    $this->setEnvironmentVariable(StaticCredentials::ACCESS_KEY_ID, 'AKIAEXAMPLE');
    $this->setEnvironmentVariable(StaticCredentials::SECRET_ACCESS_KEY, 'secret');
    config(['aws-sso.fail_on_static_credentials' => false]);
    watchCli(FakeAwsCli::authenticated());

    $this->artisan('aws-sso:watch', ['--once' => true])
        ->doesntExpectOutputToContain('Static AWS credentials')
        ->assertSuccessful();
});
