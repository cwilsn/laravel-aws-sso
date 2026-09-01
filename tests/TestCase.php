<?php

declare(strict_types=1);

namespace LaravelAwsSso\Tests;

use Illuminate\Support\Facades\Process;
use LaravelAwsSso\LaravelAwsSsoServiceProvider;
use LaravelAwsSso\Support\StaticCredentials;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** @var list<string> */
    private const array CREDENTIAL_VARIABLES = [
        StaticCredentials::ACCESS_KEY_ID,
        StaticCredentials::SECRET_ACCESS_KEY,
        StaticCredentials::SESSION_TOKEN,
    ];

    protected function setUp(): void
    {
        $this->forgetAwsCredentialVariables();

        parent::setUp();

        // No test may reach a real AWS CLI. Recording starts with no handlers,
        // so anything a test has not explicitly faked fails loudly.
        Process::fake([]);
        Process::preventStrayProcesses();
    }

    protected function tearDown(): void
    {
        $this->forgetAwsCredentialVariables();

        parent::tearDown();
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [LaravelAwsSsoServiceProvider::class];
    }

    /**
     * Write an environment variable everywhere Laravel's Env repository reads from.
     */
    protected function setEnvironmentVariable(string $name, string $value): void
    {
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    protected function forgetEnvironmentVariable(string $name): void
    {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }

    private function forgetAwsCredentialVariables(): void
    {
        foreach (self::CREDENTIAL_VARIABLES as $name) {
            $this->forgetEnvironmentVariable($name);
        }
    }
}
