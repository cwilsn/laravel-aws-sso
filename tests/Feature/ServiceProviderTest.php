<?php

declare(strict_types=1);

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use LaravelAwsSso\Auth\AwsSsoAuthenticator;
use LaravelAwsSso\Aws\AwsCli;
use LaravelAwsSso\Aws\ProcessAwsCli;
use LaravelAwsSso\LaravelAwsSsoServiceProvider;
use LaravelAwsSso\Listeners\EnsureAwsSsoAuthentication;

it('merges the package configuration without publishing', function (): void {
    expect(config('aws-sso.commands'))->toBe(['dev'])
        ->and(config('aws-sso.environments'))->toBe(['local'])
        ->and(config('aws-sso.enabled'))->toBeTrue()
        ->and(config('aws-sso.fail_on_static_credentials'))->toBeTrue()
        ->and(config('aws-sso.show_identity_after_login'))->toBeTrue()
        ->and(config('aws-sso.expected_account_id'))->toBeNull()
        ->and(config('aws-sso.expected_role'))->toBeNull();
});

it('binds the aws cli contract to the process implementation as a singleton', function (): void {
    expect(app(AwsCli::class))->toBeInstanceOf(ProcessAwsCli::class)
        ->and(app(AwsCli::class))->toBe(app(AwsCli::class));
});

it('resolves the authenticator as a singleton', function (): void {
    expect(app(AwsSsoAuthenticator::class))->toBe(app(AwsSsoAuthenticator::class));
});

it('registers the command starting listener', function (): void {
    expect(app(Dispatcher::class)->getListeners(CommandStarting::class))->not->toBeEmpty();

    // The listener resolves out of the container with its dependencies.
    expect(app(EnsureAwsSsoAuthentication::class))->toBeInstanceOf(EnsureAwsSsoAuthentication::class);
});

it('registers the artisan commands', function (): void {
    $commands = array_keys(app(Kernel::class)->all());

    expect($commands)->toContain('aws-sso:login')
        ->and($commands)->toContain('aws-sso:status');
});

it('publishes the configuration under a stable tag', function (): void {
    $paths = ServiceProvider::pathsToPublish(
        LaravelAwsSsoServiceProvider::class,
        'aws-sso-config',
    );

    expect($paths)->toHaveCount(1)
        ->and(array_key_first($paths))->toEndWith('config/aws-sso.php')
        ->and(reset($paths))->toEndWith('aws-sso.php');
});
