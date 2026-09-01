<?php

declare(strict_types=1);

use Illuminate\Console\Application as Artisan;
use Illuminate\Contracts\Console\Kernel;
use LaravelAwsSso\Aws\AwsCli;
use LaravelAwsSso\Exceptions\AwsAuthenticationFailed;
use LaravelAwsSso\Tests\Fixtures\FakeAwsCli;

/**
 * Exercises the real Artisan dispatch path rather than a hand-fired event.
 *
 * Laravel skips the Symfony -> Laravel console event bridge while unit testing,
 * so it is wired up explicitly here. A throwaway command stands in for `dev`
 * because the real one starts long-running development processes.
 */
function guardedCommand(): void
{
    app(Kernel::class)->rerouteSymfonyCommandEvents();
    app(Kernel::class)->command('sso-test:target', fn () => 0)->describe('Guarded test target');
    app(Kernel::class)->command('sso-test:other', fn () => 0)->describe('Unguarded test target');

    config(['aws-sso.commands' => ['sso-test:target'], 'aws-sso.profile' => 'my-dev-profile']);
}

beforeEach(function (): void {
    $this->app['env'] = 'local';
});

afterEach(function (): void {
    Artisan::forgetBootstrappers();
});

it('checks authentication before a guarded command runs', function (): void {
    guardedCommand();
    $cli = FakeAwsCli::authenticated();
    $this->app->instance(AwsCli::class, $cli);

    $this->artisan('sso-test:target')->assertSuccessful();

    expect($cli->identityCalls)->toBe(['my-dev-profile']);
});

it('stops a guarded command when authentication fails', function (): void {
    guardedCommand();
    $cli = (new FakeAwsCli)->queueExpiredSession()->queueExpiredSession();
    $this->app->instance(AwsCli::class, $cli);

    expect(fn () => $this->artisan('sso-test:target')->run())
        ->toThrow(AwsAuthenticationFailed::class);

    expect($cli->loginCalls)->toBe(['my-dev-profile']);
});

it('leaves unguarded commands alone', function (): void {
    guardedCommand();
    $cli = new FakeAwsCli;
    $this->app->instance(AwsCli::class, $cli);

    $this->artisan('sso-test:other')->assertSuccessful();

    expect($cli->identityCalls)->toBe([])
        ->and($cli->loginCalls)->toBe([]);
});
