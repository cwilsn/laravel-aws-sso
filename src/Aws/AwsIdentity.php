<?php

declare(strict_types=1);

namespace LaravelAwsSso\Aws;

use LaravelAwsSso\Exceptions\AwsAuthenticationFailed;

/**
 * The result of `aws sts get-caller-identity`.
 *
 * Holds identity metadata only. It never carries credentials, tokens, or
 * anything that would be unsafe to print.
 */
final readonly class AwsIdentity
{
    public function __construct(
        public string $userId,
        public string $account,
        public string $arn,
    ) {}

    /**
     * Parse an STS caller identity document.
     *
     * @throws AwsAuthenticationFailed when the payload is not a well-formed identity
     */
    public static function fromJson(string $json): self
    {
        $decoded = json_decode(trim($json), true);

        if (! is_array($decoded)) {
            throw AwsAuthenticationFailed::malformedIdentity();
        }

        return new self(
            self::string($decoded, 'UserId'),
            self::string($decoded, 'Account'),
            self::string($decoded, 'Arn'),
        );
    }

    /**
     * The role name from an assumed-role ARN, or null for any other identity.
     *
     * STS reports an assumed role as
     * `arn:aws:sts::123456789012:assumed-role/<role name>/<session name>`.
     * An IAM user, the account root, or a federated identity has no role name
     * and returns null, which callers must treat as "not the expected role"
     * rather than "no opinion".
     */
    public function roleName(): ?string
    {
        if (preg_match('#^arn:[^:]*:sts::[0-9]*:assumed-role/([^/]++)/#', $this->arn, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * The IAM Identity Center permission set behind an assumed-role ARN.
     *
     * Identity Center names the roles it creates
     * `AWSReservedSSO_<permission set>_<role suffix>`, where the suffix is a
     * hexadecimal id whose length AWS does not document. A permission set may
     * itself contain underscores, so the name is taken greedily and only the
     * final hexadecimal segment is stripped. Returns null for a role Identity
     * Center did not create, which leaves the caller comparing the role name
     * verbatim rather than guessing.
     */
    public function permissionSetName(): ?string
    {
        $role = $this->roleName();

        if ($role === null || preg_match('/^AWSReservedSSO_(.+)_[0-9a-f]+$/', $role, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * @param  array<array-key, mixed>  $payload
     *
     * @throws AwsAuthenticationFailed
     */
    private static function string(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw AwsAuthenticationFailed::malformedIdentity();
        }

        return trim($value);
    }
}
