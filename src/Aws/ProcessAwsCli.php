<?php

declare(strict_types=1);

namespace LaravelAwsSso\Aws;

use Illuminate\Process\Factory as ProcessFactory;
use LaravelAwsSso\Exceptions\AwsAuthenticationFailed;
use Symfony\Component\Process\Process as SymfonyProcess;
use Throwable;

/**
 * Runs the AWS CLI through Laravel's Process API.
 *
 * Every command is built as an argument array, so the profile name is passed
 * to `proc_open` as a single argv entry and is never parsed by a shell.
 */
final class ProcessAwsCli implements AwsCli
{
    public function __construct(
        private readonly ProcessFactory $process,
        private readonly int $versionTimeout = 10,
        private readonly int $identityTimeout = 15,
    ) {}

    public function isInstalled(): bool
    {
        try {
            return $this->process
                ->timeout($this->versionTimeout)
                ->run(['aws', '--version'])
                ->successful();
        } catch (Throwable) {
            // A missing executable surfaces differently across platforms; either
            // way the CLI is unusable, which is all the caller needs to know.
            return false;
        }
    }

    public function identity(string $profile): AwsIdentity
    {
        $result = $this->process
            ->timeout($this->identityTimeout)
            ->run([
                'aws',
                'sts',
                'get-caller-identity',
                '--profile',
                $profile,
                '--output',
                'json',
                '--no-cli-pager',
            ]);

        if (! $result->successful()) {
            throw AwsAuthenticationFailed::identityUnavailable(
                $profile,
                $result->errorOutput() ?: $result->output(),
            );
        }

        return AwsIdentity::fromJson($result->output());
    }

    public function login(string $profile, ?callable $onOutput = null): void
    {
        $tty = SymfonyProcess::isTtySupported();

        $result = $this->process
            // The developer has to complete a browser flow; do not cut them off.
            ->forever()
            ->tty($tty)
            ->run(
                ['aws', 'sso', 'login', '--profile', $profile, '--no-cli-pager'],
                $tty ? null : $onOutput,
            );

        if (! $result->successful()) {
            throw AwsAuthenticationFailed::loginFailed(
                $profile,
                $result->errorOutput() ?: $result->output(),
            );
        }
    }
}
