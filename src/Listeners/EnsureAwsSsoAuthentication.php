<?php

declare(strict_types=1);

namespace LaravelAwsSso\Listeners;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use LaravelAwsSso\Auth\AuthenticationResult;
use LaravelAwsSso\Auth\AwsSsoAuthenticator;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Verifies AWS authentication immediately before an opted-in Artisan command runs.
 *
 * Laravel 13 does not let vendor packages register processes with
 * `Illuminate\Foundation\DevCommands`, so this listener hooks the Artisan
 * lifecycle instead and leaves the native `dev` command untouched. Any
 * exception thrown here propagates and stops the command from starting.
 */
final readonly class EnsureAwsSsoAuthentication
{
    public function __construct(
        private AwsSsoAuthenticator $authenticator,
        private Config $config,
        private Application $app,
    ) {}

    public function handle(CommandStarting $event): void
    {
        if (! $this->shouldRun($event)) {
            return;
        }

        $result = $this->authenticator->ensureAuthenticated(
            output: $event->output,
            interactive: $this->isInteractive($event->input),
        );

        // Stay silent when the session was already valid. The shadowed case has
        // already warned through the same output during the check.
        if ($result->reauthenticated) {
            $event->output->writeln($this->successMessage($result));
        }
    }

    private function shouldRun(CommandStarting $event): bool
    {
        return $this->app->runningInConsole()
            && $this->config->get('aws-sso.enabled', true)
            && in_array($event->command, $this->list('aws-sso.commands'), true)
            && in_array($this->app->environment(), $this->list('aws-sso.environments'), true);
    }

    private function successMessage(AuthenticationResult $result): string
    {
        // When static credentials shadow the profile the identity belongs to the
        // profile, not to the application, so it is never announced as the one
        // the application authenticated with.
        $message = $result->shadowedByStaticCredentials
            ? "AWS profile [{$result->profile}] signed in, but your application will use the static credentials in your environment"
            : "AWS authenticated with [{$result->profile}]";

        $style = $result->shadowedByStaticCredentials ? 'comment' : 'info';

        if (! $this->config->get('aws-sso.show_identity_after_login', true)) {
            return "<{$style}>{$message}.</{$style}>";
        }

        return "<{$style}>{$message}:</{$style}> ".$result->identity->arn;
    }

    /**
     * Artisan reports interactivity late, so the raw flags are inspected too.
     */
    private function isInteractive(InputInterface $input): bool
    {
        if ($input->hasParameterOption(['--no-interaction', '-n'], true)) {
            return false;
        }

        return $input->isInteractive();
    }

    /**
     * Read a scope list from configuration.
     *
     * A bare string is accepted as a single-entry list. Reading `'dev'` as an
     * empty list would silently skip the check everywhere, and a scope that
     * quietly covers nothing is the one failure mode this listener must not
     * have.
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
