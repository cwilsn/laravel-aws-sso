<?php

declare(strict_types=1);

namespace LaravelAwsSso\Console;

use Illuminate\Console\Command;
use LaravelAwsSso\Auth\AwsSsoAuthenticator;
use LaravelAwsSso\Exceptions\LaravelAwsSsoException;

final class LoginCommand extends Command
{
    protected $signature = 'aws-sso:login
                            {--profile= : The AWS profile to authenticate instead of the configured one}';

    protected $description = 'Ensure the AWS IAM Identity Center session for the configured profile is valid';

    public function handle(AwsSsoAuthenticator $authenticator): int
    {
        $profile = $this->option('profile');

        try {
            $result = $authenticator->ensureAuthenticated(
                profile: is_string($profile) ? $profile : null,
                output: $this->output,
                interactive: $this->input->isInteractive(),
            );
        } catch (LaravelAwsSsoException $e) {
            $this->newLine();
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        // Written as plain lines so long assumed-role ARNs are not truncated.
        if ($result->shadowedByStaticCredentials) {
            $this->line("<comment>AWS profile [{$result->profile}] resolves to:</comment> {$result->identity->arn}");
            $this->newLine();
            $this->components->warn(
                'That is the identity of the profile, not the one your application will use. '
                .'The AWS SDK resolves the static credentials in your environment first.'
            );

            return self::SUCCESS;
        }

        $this->line("<info>AWS authenticated with [{$result->profile}]:</info> {$result->identity->arn}");

        return self::SUCCESS;
    }
}
