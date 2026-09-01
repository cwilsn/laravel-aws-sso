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

it('trims surrounding whitespace from every identity field', function (): void {
    $identity = AwsIdentity::fromJson(<<<'JSON'
    {
        "UserId": "  AROAEXAMPLEID:developer  ",
        "Account": "\t123456789012\n",
        "Arn": " arn:aws:sts::123456789012:assumed-role/Role/developer "
    }
    JSON);

    expect($identity->userId)->toBe('AROAEXAMPLEID:developer')
        ->and($identity->account)->toBe('123456789012')
        ->and($identity->arn)->toBe('arn:aws:sts::123456789012:assumed-role/Role/developer');
});

it('ignores extra keys that sts may add', function (): void {
    $identity = AwsIdentity::fromJson(
        '{"UserId":"u","Account":"1","Arn":"a","ResponseMetadata":{"RequestId":"abc"}}'
    );

    expect($identity->account)->toBe('1');
});

describe('role parsing', function (): void {
    it('extracts the role name from an assumed-role arn', function (): void {
        $identity = new AwsIdentity('u', '123456789012', 'arn:aws:sts::123456789012:assumed-role/MyRole/session');

        expect($identity->roleName())->toBe('MyRole');
    });

    it('has no role name for an identity that is not an assumed role', function (string $arn): void {
        expect((new AwsIdentity('u', '123456789012', $arn))->roleName())->toBeNull();
    })->with([
        'iam user' => ['arn:aws:iam::123456789012:user/developer'],
        'account root' => ['arn:aws:iam::123456789012:root'],
        'federated user' => ['arn:aws:sts::123456789012:federated-user/developer'],
        'no session name' => ['arn:aws:sts::123456789012:assumed-role/MyRole'],
        'not an arn' => ['assumed-role/MyRole/session'],
    ]);

    it('does not let a session name be read as the role name', function (): void {
        $identity = new AwsIdentity('u', '1', 'arn:aws:sts::1:assumed-role/Admin/assumed-role/Developer/x');

        expect($identity->roleName())->toBe('Admin');
    });

    it('recovers the permission set name from an identity center role', function (string $role, string $expected): void {
        $identity = new AwsIdentity('u', '123456789012', "arn:aws:sts::123456789012:assumed-role/{$role}/developer");

        expect($identity->permissionSetName())->toBe($expected);
    })->with([
        'simple' => ['AWSReservedSSO_LaravelDeveloper_0a1b2c3d4e5f', 'LaravelDeveloper'],
        'short suffix' => ['AWSReservedSSO_LaravelDeveloper_9f8e7d', 'LaravelDeveloper'],
        'underscored name' => ['AWSReservedSSO_Laravel_Developer_v2_0a1b2c3d', 'Laravel_Developer_v2'],
        'hex-looking name' => ['AWSReservedSSO_abcdef_0a1b2c3d', 'abcdef'],
    ]);

    it('has no permission set name for a role identity center did not create', function (string $role): void {
        $identity = new AwsIdentity('u', '123456789012', "arn:aws:sts::123456789012:assumed-role/{$role}/developer");

        expect($identity->permissionSetName())->toBeNull();
    })->with([
        'plain iam role' => ['MyAppRole'],
        'no suffix' => ['AWSReservedSSO_LaravelDeveloper'],
        'non-hex suffix' => ['AWSReservedSSO_LaravelDeveloper_notahexvalue'],
    ]);
});
