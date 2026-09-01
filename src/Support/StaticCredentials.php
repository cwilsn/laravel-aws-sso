<?php

declare(strict_types=1);

namespace LaravelAwsSso\Support;

use Illuminate\Support\Env;

/**
 * Detects long-lived AWS credentials in the environment.
 *
 * The AWS SDK credential chain reads environment credentials before it reads
 * SSO profiles, so leftover keys silently win over a fresh SSO session.
 */
final class StaticCredentials
{
    public const ACCESS_KEY_ID = 'AWS_ACCESS_KEY_ID';

    public const SECRET_ACCESS_KEY = 'AWS_SECRET_ACCESS_KEY';

    public const SESSION_TOKEN = 'AWS_SESSION_TOKEN';

    /**
     * Both halves of a static key pair are present, so the SDK will use them.
     */
    public function detected(): bool
    {
        return $this->has(self::ACCESS_KEY_ID) && $this->has(self::SECRET_ACCESS_KEY);
    }

    /**
     * The AWS credential variables that currently hold a value.
     *
     * Returns names only. Values are never read out of this class.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_values(array_filter(
            [self::ACCESS_KEY_ID, self::SECRET_ACCESS_KEY, self::SESSION_TOKEN],
            fn (string $name): bool => $this->has($name),
        ));
    }

    /**
     * A session token on its own is temporary, so it is diagnostic rather than fatal.
     */
    public function hasSessionToken(): bool
    {
        return $this->has(self::SESSION_TOKEN);
    }

    private function has(string $name): bool
    {
        $value = Env::get($name);

        return is_string($value) && trim($value) !== '';
    }
}
