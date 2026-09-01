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

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw AwsAuthenticationFailed::malformedIdentity();
        }

        return new self(
            self::string($decoded, 'UserId'),
            self::string($decoded, 'Account'),
            self::string($decoded, 'Arn'),
        );
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
