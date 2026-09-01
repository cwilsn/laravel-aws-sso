<?php

declare(strict_types=1);

namespace LaravelAwsSso\Listeners;

use Illuminate\Console\Events\CommandStarting;
use LaravelAwsSso\Auth\AwsSsoAuthenticator;
use LaravelAwsSso\Support\AuthenticationMessage;
use LaravelAwsSso\Support\AutomaticAuthentication;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Verifies AWS authentication immediately before an opted-in Artisan command runs.
 *
 * Any exception thrown here propagates and stops the command from starting.
 * A separate DevCommands companion process keeps checking after startup.
 */
final readonly class EnsureAwsSsoAuthentication
{
    public function __construct(
        private AwsSsoAuthenticator $authenticator,
        private AutomaticAuthentication $automaticAuthentication,
        private AuthenticationMessage $message,
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
            $event->output->writeln($this->message->success($result));
        }
    }

    private function shouldRun(CommandStarting $event): bool
    {
        return $this->automaticAuthentication->enabledFor($event->command);
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
}
