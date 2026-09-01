<?php

declare(strict_types=1);

use LaravelAwsSso\Exceptions\AwsAuthenticationFailed;

it('reports the exit code when the login fails', function (): void {
    $exception = AwsAuthenticationFailed::loginFailed('my-dev-profile', 'Session token not found', 253);

    expect($exception->getMessage())
        ->toContain('could not be authenticated (exit code 253)')
        ->toContain('Session token not found');
});

it('points at the terminal when a tty login captured no output', function (): void {
    // With a TTY the AWS CLI is handed /dev/tty, so neither stream reaches PHP.
    $exception = AwsAuthenticationFailed::loginFailed('my-dev-profile', '', 253, attachedToTty: true);

    expect($exception->getMessage())
        ->toContain('(exit code 253)')
        ->toContain('wrote its output straight to this terminal')
        ->toContain('aws configure sso --profile my-dev-profile');
});

it('does not blame the terminal when the login was not attached to one', function (): void {
    $exception = AwsAuthenticationFailed::loginFailed('my-dev-profile', '', 253);

    expect($exception->getMessage())
        ->not->toContain('this terminal')
        ->and($exception->getMessage())->toContain('could not be authenticated (exit code 253)');
});

it('prefers real captured output over the terminal hint', function (): void {
    // A TTY that somehow captured output should show it rather than the hint.
    $exception = AwsAuthenticationFailed::loginFailed('my-dev-profile', 'The config profile could not be found', 253, attachedToTty: true);

    expect($exception->getMessage())
        ->toContain('The config profile could not be found')
        ->not->toContain('this terminal');
});

it('omits the exit code when the process never reported one', function (): void {
    $exception = AwsAuthenticationFailed::loginFailed('my-dev-profile', 'boom');

    expect($exception->getMessage())
        ->toContain('could not be authenticated.')
        ->not->toContain('exit code');
});

it('keeps the raw cli output available for reuse', function (): void {
    $exception = AwsAuthenticationFailed::loginFailed('my-dev-profile', 'Session token not found', 253);

    expect($exception->cliOutput())->toBe('Session token not found');
});
