<?php

declare(strict_types=1);

namespace LaravelAwsSso;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\ServiceProvider;
use LaravelAwsSso\Auth\AwsSsoAuthenticator;
use LaravelAwsSso\Aws\AwsCli;
use LaravelAwsSso\Aws\ProcessAwsCli;
use LaravelAwsSso\Console\LoginCommand;
use LaravelAwsSso\Console\StatusCommand;
use LaravelAwsSso\Console\WatchCommand;
use LaravelAwsSso\Listeners\EnsureAwsSsoAuthentication;
use LaravelAwsSso\Support\AutomaticAuthentication;
use LaravelAwsSso\Support\StaticCredentials;

final class LaravelAwsSsoServiceProvider extends ServiceProvider
{
    private const string CONFIG = __DIR__.'/../config/aws-sso.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG, 'aws-sso');

        $this->app->singleton(StaticCredentials::class);

        $this->app->singleton(AwsCli::class, ProcessAwsCli::class);

        $this->app->singleton(AwsSsoAuthenticator::class);
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([self::CONFIG => $this->app->configPath('aws-sso.php')], 'aws-sso-config');

        $this->commands([LoginCommand::class, StatusCommand::class, WatchCommand::class]);

        if ($this->app->make(AutomaticAuthentication::class)->monitorsDevSession()) {
            DevCommands::artisan('aws-sso:watch', 'aws-sso')->blue();
        }

        $this->app->make(Dispatcher::class)->listen(
            CommandStarting::class,
            EnsureAwsSsoAuthentication::class,
        );
    }
}
