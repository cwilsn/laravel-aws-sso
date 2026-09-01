<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use LaravelAwsSso\Aws\ProcessAwsCli;
use LaravelAwsSso\Exceptions\AwsAuthenticationFailed;
use Symfony\Component\Process\Process as SymfonyProcess;

// Laravel matches process fakes against Symfony's escaped command line, which
// is exactly where an argument array proves it never touches a shell.
const VERSION_COMMAND = "'aws' '--version'";
const IDENTITY_COMMAND = "'aws' 'sts' 'get-caller-identity'*";
const LOGIN_COMMAND = "'aws' 'sso' 'login'*";

const IDENTITY_JSON = '{"UserId":"AROAEXAMPLEID:developer","Account":"123456789012","Arn":"arn:aws:sts::123456789012:assumed-role/AWSReservedSSO_LaravelDeveloper_0a1b/developer"}';

function awsCli(): ProcessAwsCli
{
    return app(ProcessAwsCli::class);
}

it('reports the aws cli as installed when the version command succeeds', function (): void {
    Process::fake([VERSION_COMMAND => Process::result('aws-cli/2.33.16')]);

    expect(awsCli()->isInstalled())->toBeTrue();

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['aws', '--version']);
});

it('reports the aws cli as missing when the version command fails', function (): void {
    Process::fake([VERSION_COMMAND => Process::result(output: '', errorOutput: 'command not found', exitCode: 127)]);

    expect(awsCli()->isInstalled())->toBeFalse();
});

it('resolves the caller identity', function (): void {
    Process::fake([IDENTITY_COMMAND => Process::result(IDENTITY_JSON)]);

    $identity = awsCli()->identity('my-dev-profile');

    expect($identity->account)->toBe('123456789012');

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === [
        'aws', 'sts', 'get-caller-identity', '--profile', 'my-dev-profile', '--output', 'json', '--no-cli-pager',
    ]);
});

it('applies a bounded timeout to the identity check', function (): void {
    Process::fake([IDENTITY_COMMAND => Process::result(IDENTITY_JSON)]);

    awsCli()->identity('my-dev-profile');

    Process::assertRan(fn (PendingProcess $process): bool => $process->timeout === 15);
});

it('fails when the identity check exits non-zero', function (): void {
    Process::fake([
        IDENTITY_COMMAND => Process::result(
            output: '',
            errorOutput: 'Error loading SSO Token: Token for start URL has expired.',
            exitCode: 255,
        ),
    ]);

    expect(fn () => awsCli()->identity('my-dev-profile'))
        ->toThrow(AwsAuthenticationFailed::class, 'does not have a usable IAM Identity Center session');
});

it('includes an excerpt of the aws error when the identity check fails', function (): void {
    Process::fake([
        IDENTITY_COMMAND => Process::result(
            output: '',
            errorOutput: 'The config profile (my-dev-profile) could not be found',
            exitCode: 255,
        ),
    ]);

    expect(fn () => awsCli()->identity('my-dev-profile'))
        ->toThrow(AwsAuthenticationFailed::class, 'The config profile (my-dev-profile) could not be found');
});

it('runs an interactive sso login without a timeout', function (): void {
    Process::fake([LOGIN_COMMAND => Process::result('Successfully logged into Start URL: https://example.awsapps.com/start')]);

    awsCli()->login('my-dev-profile');

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === [
        'aws', 'sso', 'login', '--profile', 'my-dev-profile', '--no-cli-pager',
    ] && $process->timeout === null);
});

it('fails when the sso login exits non-zero', function (): void {
    Process::fake([
        LOGIN_COMMAND => Process::result(
            output: '',
            errorOutput: 'The config profile (my-dev-profile) could not be found',
            exitCode: 255,
        ),
    ]);

    expect(fn () => awsCli()->login('my-dev-profile'))
        ->toThrow(AwsAuthenticationFailed::class, 'aws configure sso --profile my-dev-profile');
});

it('never lets a profile name reach a shell', function (string $malicious): void {
    Process::fake(['*' => Process::result(IDENTITY_JSON)]);

    awsCli()->identity($malicious);

    // The array form is handed straight to proc_open, so the whole value stays
    // a single argv entry rather than being parsed as shell syntax.
    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
        && count($process->command) === 8
        && ($process->command[4] ?? null) === $malicious);

    // Symfony escapes the argument array into a single quoted argv entry, so
    // even the shell-looking payload is inert.
    $commandLine = (new SymfonyProcess(['aws', 'sts', 'get-caller-identity', '--profile', $malicious]))->getCommandline();

    expect($commandLine)->toContain(escapeshellarg($malicious))
        ->and(file_exists('/tmp/laravel-aws-sso-pwned'))->toBeFalse();
})->with([
    'command separator' => ['foo; touch /tmp/laravel-aws-sso-pwned'],
    'subshell' => ['$(touch /tmp/laravel-aws-sso-pwned)'],
    'backticks' => ['`touch /tmp/laravel-aws-sso-pwned`'],
    'pipe' => ['foo | touch /tmp/laravel-aws-sso-pwned'],
    'ampersands' => ['foo && touch /tmp/laravel-aws-sso-pwned'],
    'newline' => ["foo\ntouch /tmp/laravel-aws-sso-pwned"],
]);

it('never lets a profile name reach a shell during login', function (): void {
    Process::fake(['*' => Process::result('ok')]);

    awsCli()->login('foo; touch /tmp/laravel-aws-sso-pwned');

    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
        && ($process->command[4] ?? null) === 'foo; touch /tmp/laravel-aws-sso-pwned');

    expect(file_exists('/tmp/laravel-aws-sso-pwned'))->toBeFalse();
});

it('reports the aws cli as missing when the process cannot be started at all', function (): void {
    // Laravel throws for an unfaked process rather than returning a failed
    // result, which is the same shape as a missing executable on some platforms.
    Process::fake([LOGIN_COMMAND => Process::result('unrelated')]);

    expect(awsCli()->isInstalled())->toBeFalse();
});

it('applies a bounded timeout to the version check', function (): void {
    Process::fake([VERSION_COMMAND => Process::result('aws-cli/2.33.16')]);

    awsCli()->isInstalled();

    Process::assertRan(fn (PendingProcess $process): bool => $process->timeout === 10);
});

it('matches the tty mode to what the current terminal supports', function (): void {
    Process::fake([LOGIN_COMMAND => Process::result('Attempting to open the SSO authorization page')]);

    awsCli()->login('my-dev-profile', function (string $type, string $chunk): void {});

    // With a TTY the AWS CLI writes straight to the terminal; without one the
    // package has to stream the output through the callback instead.
    Process::assertRan(fn (PendingProcess $process): bool => $process->tty === SymfonyProcess::isTtySupported());
});

it('asks for json without a pager on every identity check', function (): void {
    Process::fake([IDENTITY_COMMAND => Process::result(IDENTITY_JSON)]);

    awsCli()->identity('my-dev-profile');

    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
        && in_array('--no-cli-pager', $process->command, true)
        && in_array('json', $process->command, true));
});

it('always builds commands as an argument array, never a string', function (): void {
    Process::fake([
        VERSION_COMMAND => Process::result('aws-cli/2.33.16'),
        IDENTITY_COMMAND => Process::result(IDENTITY_JSON),
        LOGIN_COMMAND => Process::result('ok'),
    ]);

    $cli = awsCli();
    $cli->isInstalled();
    $cli->identity('my-dev-profile');
    $cli->login('my-dev-profile');

    // A string command would be handed to `/bin/sh -c`; an array goes straight
    // to proc_open/execvp, so no argument is ever parsed as shell syntax.
    Process::assertRanTimes(fn (PendingProcess $process): bool => is_array($process->command)
        && $process->command[0] === 'aws', 3);
});
