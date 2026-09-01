<?php

declare(strict_types=1);

namespace LaravelAwsSso\Support;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;

/**
 * Defines where automatic authentication is allowed to run.
 */
final readonly class AutomaticAuthentication
{
    public function __construct(
        private Config $config,
        private Application $app,
    ) {}

    public function enabledFor(string $command): bool
    {
        return $this->app->runningInConsole()
            && $this->config->get('aws-sso.enabled', true)
            && in_array($command, $this->list('aws-sso.commands'), true)
            && in_array($this->app->environment(), $this->list('aws-sso.environments'), true);
    }

    public function monitorsDevSession(): bool
    {
        return $this->config->get('aws-sso.monitor', true)
            && $this->enabledFor('dev');
    }

    /**
     * Read a scope list from configuration.
     *
     * A bare string is accepted as a single-entry list. Reading `'dev'` as an
     * empty list would silently skip the check everywhere, and a scope that
     * quietly covers nothing is the one failure mode this package must not have.
     *
     * @return list<string>
     */
    private function list(string $key): array
    {
        $values = $this->config->get($key, []);

        if (is_string($values)) {
            return $values === '' ? [] : [$values];
        }

        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter($values, is_string(...)));
    }
}
