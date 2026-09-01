<?php

declare(strict_types=1);

namespace LaravelAwsSso\Console;

use Illuminate\Console\Command;
use LaravelAwsSso\Auth\AwsSsoAuthenticator;
use LaravelAwsSso\Aws\AwsCli;
use LaravelAwsSso\Exceptions\LaravelAwsSsoException;
use LaravelAwsSso\Support\StaticCredentials;

/**
 * Reports on the current AWS setup without ever starting a browser login.
 */
final class StatusCommand extends Command
{
    protected $signature = 'aws-sso:status
                            {--profile= : The AWS profile to inspect instead of the configured one}';

    protected $description = 'Show the current AWS IAM Identity Center authentication status';

    public function handle(
        AwsCli $cli,
        AwsSsoAuthenticator $authenticator,
        StaticCredentials $staticCredentials,
    ): int {
        $profileOption = $this->option('profile');
        $profile = $authenticator->profile(is_string($profileOption) ? $profileOption : null);

        $installed = $cli->isInstalled();
        $names = $staticCredentials->names();

        $this->newLine();
        $this->components->twoColumnDetail('AWS CLI', $installed ? '<fg=green>available</>' : '<fg=red>not found</>');
        $this->components->twoColumnDetail('Profile', $profile);
        $this->components->twoColumnDetail(
            'Environment credentials',
            $names === [] ? '<fg=gray>none</>' : '<fg=yellow>'.implode(', ', $names).'</>',
        );

        if (! $installed) {
            $this->components->twoColumnDetail('Authenticated', '<fg=red>no</>');
            $this->newLine();
            $this->components->error('AWS CLI v2 was not found. Install it and run `aws configure sso`.');

            return self::FAILURE;
        }

        try {
            $identity = $cli->identity($profile);
            $authenticator->verify($identity, $profile);
        } catch (LaravelAwsSsoException $e) {
            $this->components->twoColumnDetail('Authenticated', '<fg=red>no</>');
            $this->newLine();
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Authenticated', '<fg=green>yes</>');
        $this->components->twoColumnDetail('Account', $identity->account);
        $this->newLine();

        // Written as a plain line so the ARN stays copy-pasteable at any width.
        $this->line("Identity: {$identity->arn}");

        if ($staticCredentials->detected()) {
            $this->newLine();
            $this->components->warn($staticCredentials->shadowWarning($profile));
        }

        return self::SUCCESS;
    }
}
