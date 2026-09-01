<?php

declare(strict_types=1);

use LaravelAwsSso\Aws\AwsCli;
use LaravelAwsSso\Support\StaticCredentials;
use LaravelAwsSso\Tests\Fixtures\FakeAwsCli;

function statusCli(FakeAwsCli $cli): FakeAwsCli
{
    test()->swap(AwsCli::class, $cli);

    return $cli;
}

beforeEach(function (): void {
    config(['aws-sso.profile' => 'my-dev-profile']);
});

it('succeeds and reports the identity when authenticated', function (): void {
    statusCli(FakeAwsCli::authenticated());

    $this->artisan('aws-sso:status')
        ->expectsOutputToContain('AWS CLI')
        ->expectsOutputToContain('my-dev-profile')
        ->expectsOutputToContain('none')
        ->expectsOutputToContain('Authenticated')
        // Substrings must not overlap; the first matching expectation wins.
        ->expectsOutputToContain('Account')
        ->expectsOutputToContain('Identity: arn:aws:sts::123456789012:assumed-role/')
        ->assertSuccessful();
});

it('fails when the session is not usable and never starts a login', function (): void {
    $cli = statusCli((new FakeAwsCli)->queueExpiredSession());

    $this->artisan('aws-sso:status')
        ->expectsOutputToContain('Authenticated')
        ->assertFailed();

    expect($cli->loginCalls)->toBe([]);
});

it('fails when the aws cli is missing without calling sts', function (): void {
    $cli = statusCli(new FakeAwsCli);
    $cli->installed = false;

    $this->artisan('aws-sso:status')
        ->expectsOutputToContain('not found')
        ->assertFailed();

    expect($cli->identityCalls)->toBe([]);
});

it('honours a profile override', function (): void {
    $cli = statusCli(FakeAwsCli::authenticated());

    $this->artisan('aws-sso:status', ['--profile' => 'another-profile'])
        ->expectsOutputToContain('another-profile')
        ->assertSuccessful();

    expect($cli->identityCalls)->toBe(['another-profile']);
});

it('falls back to the default profile', function (): void {
    config(['aws-sso.profile' => null]);
    $cli = statusCli(FakeAwsCli::authenticated());

    $this->artisan('aws-sso:status')
        ->expectsOutputToContain('default')
        ->assertSuccessful();

    expect($cli->identityCalls)->toBe(['default']);
});

it('names static credential variables without printing their values', function (): void {
    $this->setEnvironmentVariable(StaticCredentials::ACCESS_KEY_ID, 'AKIAEXAMPLE');
    $this->setEnvironmentVariable(StaticCredentials::SECRET_ACCESS_KEY, 'super-secret-value');
    statusCli(FakeAwsCli::authenticated());

    $this->artisan('aws-sso:status')
        ->expectsOutputToContain('AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY')
        ->doesntExpectOutputToContain('super-secret-value')
        ->doesntExpectOutputToContain('AKIAEXAMPLE')
        ->assertSuccessful();
});

it('reports a guardrail mismatch as a failure', function (): void {
    config(['aws-sso.expected_account_id' => '999999999999']);
    statusCli(FakeAwsCli::authenticated());

    $this->artisan('aws-sso:status')
        ->expectsOutputToContain('authenticated to account [123456789012]; expected [999999999999]')
        ->assertFailed();
});

it('lists a lone session token without treating it as shadowing credentials', function (): void {
    statusCli(FakeAwsCli::authenticated());
    $this->setEnvironmentVariable(StaticCredentials::SESSION_TOKEN, 'FwoGZXIvYXdzEXAMPLE');

    $this->artisan('aws-sso:status')
        ->expectsOutputToContain(StaticCredentials::SESSION_TOKEN)
        // A session token alone is temporary, so it is reported but not fatal.
        ->doesntExpectOutputToContain('Remove '.StaticCredentials::ACCESS_KEY_ID)
        ->doesntExpectOutputToContain('FwoGZXIvYXdzEXAMPLE')
        ->assertSuccessful();
});

it('still reports the identity when static credentials are shadowing the profile', function (): void {
    statusCli(FakeAwsCli::authenticated());
    $this->setEnvironmentVariable(StaticCredentials::ACCESS_KEY_ID, 'AKIAEXAMPLE');
    $this->setEnvironmentVariable(StaticCredentials::SECRET_ACCESS_KEY, 'secret');

    // Status is diagnostic: it warns, but it never fails purely on a warning.
    $this->artisan('aws-sso:status')
        ->expectsOutputToContain('Identity: arn:aws:sts::123456789012:assumed-role/')
        ->expectsOutputToContain('so the AWS SDK can use [my-dev-profile]')
        ->assertSuccessful();
});

it('reports a role guardrail mismatch as a failure', function (): void {
    statusCli(FakeAwsCli::authenticated());
    config(['aws-sso.expected_role' => 'AdministratorAccess']);

    $this->artisan('aws-sso:status')
        ->expectsOutputToContain('Expected permission set or role: AdministratorAccess')
        ->assertFailed();
});

it('fails when static credentials make a guardrail unenforceable', function (): void {
    statusCli(FakeAwsCli::authenticated());
    $this->setEnvironmentVariable(StaticCredentials::ACCESS_KEY_ID, 'AKIAEXAMPLE');
    $this->setEnvironmentVariable(StaticCredentials::SECRET_ACCESS_KEY, 'secret');
    config(['aws-sso.expected_account_id' => '123456789012']);

    // The account matches, but it is the profile's account rather than the one
    // the application will use, so a pass here would be reporting the wrong thing.
    $this->artisan('aws-sso:status')
        ->expectsOutputToContain('guardrails cannot be enforced')
        ->assertFailed();
});
