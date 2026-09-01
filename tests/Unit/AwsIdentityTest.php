<?php

declare(strict_types=1);

use LaravelAwsSso\Aws\AwsIdentity;
use LaravelAwsSso\Exceptions\AwsAuthenticationFailed;

it('parses an sts caller identity document', function (): void {
    $identity = AwsIdentity::fromJson(<<<'JSON'
    {
        "UserId": "AROAEXAMPLEID:developer",
        "Account": "123456789012",
        "Arn": "arn:aws:sts::123456789012:assumed-role/AWSReservedSSO_LaravelDeveloper_0a1b/developer"
    }
    JSON);

    expect($identity->userId)->toBe('AROAEXAMPLEID:developer')
        ->and($identity->account)->toBe('123456789012')
        ->and($identity->arn)->toBe('arn:aws:sts::123456789012:assumed-role/AWSReservedSSO_LaravelDeveloper_0a1b/developer');
});

it('rejects malformed sts payloads', function (string $payload): void {
    expect(fn () => AwsIdentity::fromJson($payload))
        ->toThrow(AwsAuthenticationFailed::class);
})->with([
    'empty string' => [''],
    'invalid json' => ['{'],
    'json null' => ['null'],
    'json list' => ['["123456789012"]'],
    'scalar' => ['"123456789012"'],
    'missing arn' => ['{"UserId":"a","Account":"123456789012"}'],
    'missing account' => ['{"UserId":"a","Arn":"arn:aws:sts::1:assumed-role/x/y"}'],
    'missing user id' => ['{"Account":"123456789012","Arn":"arn:aws:sts::1:assumed-role/x/y"}'],
    'blank value' => ['{"UserId":"a","Account":"   ","Arn":"arn"}'],
    'non-string value' => ['{"UserId":"a","Account":123456789012,"Arn":"arn"}'],
]);

it('does not leak the raw payload in the malformed identity message', function (): void {
    $payload = '{"UserId":"super-secret-value"}';

    expect(fn () => AwsIdentity::fromJson($payload))
        ->toThrow(function (AwsAuthenticationFailed $e): void {
            expect($e->getMessage())->not->toContain('super-secret-value');
        });
});
