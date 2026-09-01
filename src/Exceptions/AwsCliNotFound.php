<?php

declare(strict_types=1);

namespace LaravelAwsSso\Exceptions;

use RuntimeException;

final class AwsCliNotFound extends RuntimeException implements LaravelAwsSsoException
{
    public static function make(): self
    {
        return new self(implode(PHP_EOL, [
            'AWS CLI v2 was not found.',
            'Laravel AWS SSO requires AWS CLI v2 with IAM Identity Center support.',
            'Install the AWS CLI, run `aws configure sso`, and try again.',
            'https://docs.aws.amazon.com/cli/latest/userguide/getting-started-install.html',
        ]));
    }
}
