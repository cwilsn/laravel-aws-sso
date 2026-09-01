<?php

declare(strict_types=1);

use LaravelAwsSso\Aws\AwsCli;
use LaravelAwsSso\Exceptions\AwsAuthenticationFailed;
use LaravelAwsSso\Support\StaticCredentials;
use LaravelAwsSso\Tests\Fixtures\FakeAwsCli;

function loginCli(FakeAwsCli $cli): FakeAwsCli
{
    test()->swap(AwsCli::class, $cli);

    return $cli;
}

beforeEach(function (): void {
    config(['aws-sso.profile' => 'my-dev-profile']);
});

it('reports the identity when the session is already valid', function (): void {
    $cli = loginCli(FakeAwsCli::authenticated());

    $this->artisan('aws-sso:login')
        ->expectsOutputToContain('AWS authenticated with [my-dev-profile]: arn:aws:sts::123456789012:assumed-role/')
        ->assertSuccessful();

    expect($cli->loginCalls)->toBe([]);
});

it('triggers an sso login when the session has expired', function (): void {
    $cli = loginCli((new FakeAwsCli)->queueExpiredSession()->queueIdentity(FakeAwsCli::sampleIdentity()));

    $this->artisan('aws-sso:login')
        ->expectsOutputToContain('AWS SSO session for [my-dev-profile] has expired.')
        ->assertSuccessful();

    expect($cli->loginCalls)->toBe(['my-dev-profile']);
});

it('honours a profile override', function (): void {
    $cli = loginCli(FakeAwsCli::authenticated());

    $this->artisan('aws-sso:login', ['--profile' => 'another-profile'])
        ->expectsOutputToContain('AWS authenticated with [another-profile]')
        ->assertSuccessful();

    expect($cli->identityCalls)->toBe(['another-profile']);
});

it('runs regardless of the configured environments and commands', function (): void {
    $this->app['env'] = 'production';
    config(['aws-sso.commands' => [], 'aws-sso.environments' => []]);
    $cli = loginCli(FakeAwsCli::authenticated());

    $this->artisan('aws-sso:login')->assertSuccessful();

    expect($cli->identityCalls)->toBe(['my-dev-profile']);
});

it('fails with a readable error rather than an exception', function (): void {
    loginCli((new FakeAwsCli)->queueExpiredSession()->queueExpiredSession());

    $this->artisan('aws-sso:login')
        ->expectsOutputToContain('Unable to authenticate AWS profile [my-dev-profile].')
        ->assertFailed();
});

it('fails when the aws cli is missing', function (): void {
    $cli = loginCli(FakeAwsCli::authenticated());
    $cli->installed = false;

    $this->artisan('aws-sso:login')
        ->expectsOutputToContain('AWS CLI v2 was not found.')
        ->assertFailed();
});

it('fails when static credentials would shadow the profile', function (): void {
    $this->setEnvironmentVariable(StaticCredentials::ACCESS_KEY_ID, 'AKIAEXAMPLE');
    $this->setEnvironmentVariable(StaticCredentials::SECRET_ACCESS_KEY, 'super-secret-value');
    loginCli(FakeAwsCli::authenticated());

    $this->artisan('aws-sso:login')
        ->doesntExpectOutputToContain('super-secret-value')
        ->expectsOutputToContain('Static AWS credentials are configured in the environment.')
        ->assertFailed();
});

it('fails with a readable error when the identity breaks an account guardrail', function (): void {
    loginCli(FakeAwsCli::authenticated());
    config(['aws-sso.expected_account_id' => '999999999999']);

    $this->artisan('aws-sso:login')
        ->expectsOutputToContain('expected [999999999999]')
        ->assertFailed();
});

it('fails with a readable error when the identity breaks a role guardrail', function (): void {
    loginCli(FakeAwsCli::authenticated());
    config(['aws-sso.expected_role' => 'AdministratorAccess']);

    $this->artisan('aws-sso:login')
        ->expectsOutputToContain('Expected role to contain: AdministratorAccess')
        ->assertFailed();
});

it('fails when the sso login itself cannot complete', function (): void {
    $cli = loginCli((new FakeAwsCli)->queueExpiredSession());
    $cli->loginFailure = AwsAuthenticationFailed::loginFailed(
        'my-dev-profile',
        'The config profile (my-dev-profile) could not be found',
    );

    $this->artisan('aws-sso:login')
        ->expectsOutputToContain('could not be authenticated')
        ->assertFailed();

    expect($cli->loginCalls)->toBe(['my-dev-profile']);
});

it('never prints credential values when static credentials are detected', function (): void {
    loginCli(FakeAwsCli::authenticated());
    $this->setEnvironmentVariable(StaticCredentials::ACCESS_KEY_ID, 'AKIASUPERSECRETVALUE');
    $this->setEnvironmentVariable(StaticCredentials::SECRET_ACCESS_KEY, 'thisisthesecret');

    $this->artisan('aws-sso:login')
        ->doesntExpectOutputToContain('AKIASUPERSECRETVALUE')
        ->doesntExpectOutputToContain('thisisthesecret')
        ->assertFailed();
});
