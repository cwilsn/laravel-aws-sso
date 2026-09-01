<?php

declare(strict_types=1);

namespace LaravelAwsSso\Support;

use Illuminate\Contracts\Config\Repository as Config;
use LaravelAwsSso\Auth\AuthenticationResult;

/**
 * Formats the shared success message for automatic authentication checks.
 */
final readonly class AuthenticationMessage
{
    public function __construct(private Config $config) {}

    public function success(AuthenticationResult $result): string
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
}
