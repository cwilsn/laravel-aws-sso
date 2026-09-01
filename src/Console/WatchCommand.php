<?php

declare(strict_types=1);

namespace LaravelAwsSso\Console;

use Illuminate\Console\Attributes\Hidden;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Sleep;
use LaravelAwsSso\Auth\AwsSsoAuthenticator;
use LaravelAwsSso\Exceptions\AwsAuthenticationFailed;
use LaravelAwsSso\Exceptions\LaravelAwsSsoException;
use LaravelAwsSso\Support\AuthenticationMessage;
use LaravelAwsSso\Support\AutomaticAuthentication;

/**
 * Watches the SSO session for as long as Laravel's dev command is running.
 *
 * This is an internal companion process registered with DevCommands. It is a
 * real Artisan command so Laravel's process supervisor owns its lifetime and
 * terminates it with the rest of the development processes.
 */
#[Hidden]
final class WatchCommand extends Command
{
    private const int DEFAULT_INTERVAL = 60;

    protected $signature = 'aws-sso:watch
                            {--once : Check once immediately instead of continuing to watch}';

    protected $description = 'Monitor the AWS IAM Identity Center session used by php artisan dev';

    public function handle(
        AwsSsoAuthenticator $authenticator,
        AutomaticAuthentication $automaticAuthentication,
        AuthenticationMessage $message,
        Config $config,
    ): int {
        if (! $automaticAuthentication->monitorsDevSession()) {
            return self::SUCCESS;
        }

        $once = (bool) $this->option('once');
        $profile = $authenticator->profile();
        $waiting = false;
        $result = null;

        try {
            // This check runs immediately whenever Multiplex starts or restarts
            // the tab. It is the only check allowed to open a browser.
            $result = $authenticator->ensureAuthenticated(
                output: $this->output,
                interactive: $this->input->isInteractive(),
                // The parent dev command has already emitted this persistent
                // configuration warning; do not repeat it in the companion tab.
                warnAboutStaticCredentials: false,
            );
        } catch (LaravelAwsSsoException $e) {
            $this->reportFailure($e);

            if ($once) {
                return self::FAILURE;
            }

            $this->reportRestartInstructions();
            $waiting = true;
        }

        if ($result?->reauthenticated === true) {
            $this->line($message->success($result));
        }

        if ($once) {
            return self::SUCCESS;
        }

        $seconds = $this->interval($config);
        $this->line("<info>Watching AWS SSO session [{$profile}] every {$seconds} seconds.</info>");

        for (; ;) {
            Sleep::sleep($seconds);

            try {
                // A periodic check must never open a browser. The process may
                // have been left running while the developer is away.
                $authenticator->ensureAuthenticated(
                    interactive: false,
                    warnAboutStaticCredentials: false,
                );
            } catch (AwsAuthenticationFailed) {
                if (! $waiting) {
                    $this->newLine();
                    $this->line("<comment>AWS SSO session [{$profile}] is no longer usable.</comment>");
                    $this->reportRestartInstructions();
                }

                $waiting = true;

                continue;
            } catch (LaravelAwsSsoException $e) {
                if (! $waiting) {
                    $this->reportFailure($e);
                    $this->reportRestartInstructions();
                }

                $waiting = true;

                continue;
            }

            if ($waiting) {
                $this->line("<info>AWS SSO session [{$profile}] is usable again.</info>");
                $waiting = false;
            }
        }
    }

    private function reportFailure(LaravelAwsSsoException $exception): void
    {
        $this->newLine();
        $this->components->error($exception->getMessage());
    }

    private function reportRestartInstructions(): void
    {
        $this->line('Select the <info>aws-sso</info> tab and press <info>r</info> to sign in.');
        $this->line('Without tab shortcuts, run <info>php artisan aws-sso:login</info> in another terminal.');
    }

    private function interval(Config $config): int
    {
        $value = $config->get('aws-sso.monitor_interval', self::DEFAULT_INTERVAL);

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return self::DEFAULT_INTERVAL;
    }
}
