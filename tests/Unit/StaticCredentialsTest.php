<?php

declare(strict_types=1);

use LaravelAwsSso\Support\StaticCredentials;

it('detects nothing on a clean environment', function (): void {
    $credentials = new StaticCredentials;

    expect($credentials->detected())->toBeFalse()
        ->and($credentials->names())->toBe([])
        ->and($credentials->hasSessionToken())->toBeFalse();
});

it('only reports static credentials when both halves of the key pair are present', function (): void {
    $this->setEnvironmentVariable(StaticCredentials::ACCESS_KEY_ID, 'AKIAEXAMPLE');

    expect((new StaticCredentials)->detected())->toBeFalse();

    $this->setEnvironmentVariable(StaticCredentials::SECRET_ACCESS_KEY, 'secret');

    expect((new StaticCredentials)->detected())->toBeTrue();
});

it('treats blank values as absent', function (): void {
    $this->setEnvironmentVariable(StaticCredentials::ACCESS_KEY_ID, '   ');
    $this->setEnvironmentVariable(StaticCredentials::SECRET_ACCESS_KEY, '');

    expect((new StaticCredentials)->detected())->toBeFalse()
        ->and((new StaticCredentials)->names())->toBe([]);
});

it('does not treat a session token alone as static credentials', function (): void {
    $this->setEnvironmentVariable(StaticCredentials::SESSION_TOKEN, 'token');

    $credentials = new StaticCredentials;

    expect($credentials->detected())->toBeFalse()
        ->and($credentials->hasSessionToken())->toBeTrue()
        ->and($credentials->names())->toBe([StaticCredentials::SESSION_TOKEN]);
});

it('reports the credential variable names that are set', function (): void {
    $this->setEnvironmentVariable(StaticCredentials::ACCESS_KEY_ID, 'AKIAEXAMPLE');
    $this->setEnvironmentVariable(StaticCredentials::SECRET_ACCESS_KEY, 'secret');
    $this->setEnvironmentVariable(StaticCredentials::SESSION_TOKEN, 'token');

    expect((new StaticCredentials)->names())->toBe([
        StaticCredentials::ACCESS_KEY_ID,
        StaticCredentials::SECRET_ACCESS_KEY,
        StaticCredentials::SESSION_TOKEN,
    ]);
});
