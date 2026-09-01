<?php

declare(strict_types=1);

namespace LaravelAwsSso\Aws;

use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use LaravelAwsSso\Exceptions\AwsAuthenticationFailed;
use Symfony\Component\Process\Process as SymfonyProcess;
use Throwable;

/**
 * Runs the AWS CLI through Laravel's Process API.
 *
 * Every command is built as an argument array, so the profile name is passed
 * to `proc_open` as a single argv entry and is never parsed by a shell.
 *
 * Pending processes are created explicitly rather than through the factory's
 * `__call()` forwarding, which is untyped and would erase every result type.
 */
final readonly class ProcessAwsCli implements AwsCli
{
    private const int VERSION_TIMEOUT = 10;

    private const int IDENTITY_TIMEOUT = 15;

    public function __construct(private ProcessFactory $process) {}

    public function isInstalled(): bool
    {
        try {
            return $this->pending()
                ->timeout(self::VERSION_TIMEOUT)
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
        $result = $this->pending()
            ->timeout(self::IDENTITY_TIMEOUT)
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

        $result = $this->pending()
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

    private function pending(): PendingProcess
    {
        return $this->process->newPendingProcess();
    }
}
