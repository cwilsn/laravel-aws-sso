<?php

declare(strict_types=1);

namespace LaravelAwsSso\Exceptions;

use RuntimeException;

/**
 * A guardrail was configured with a value that cannot be compared.
 *
 * Guardrails are a security control, so an unusable value is an error rather
 * than a silent no-op. Failing closed here means a typo in `config/aws-sso.php`
 * cannot quietly disable the account or role check.
 */
final class InvalidGuardrailConfiguration extends RuntimeException implements LaravelAwsSsoException
{
    public static function make(string $key, string $type): self
    {
        return new self(implode(PHP_EOL, [
            'The ['.$key.'] guardrail is configured with a '.$type.', which cannot be compared to an AWS identity.',
            'Set it to a string, or to null to turn the guardrail off.',
        ]));
    }
}
