# Changelog

All notable changes to `laravel-aws-sso` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Added an `aws-sso` companion process to Laravel's native `dev` process list. It checks the configured profile every 60 seconds by default. When a session expires, the tab waits without opening a browser; select it and press `r` to sign in when you return, without restarting `php artisan dev`.
- Added `monitor` and `monitor_interval` configuration options for disabling the companion process or changing its check interval.
- Raised the minimum Laravel version from 13.16 to 13.18, which introduced priority-based vendor registration for development processes.

### Documentation

- Corrected the binary-resolution trust assumption in `SECURITY.md`. It described the `aws` executable as resolved through `PATH`, which understates the behaviour on Windows: Symfony's Process component bypasses the shell, so `CreateProcess` resolves the name and searches the current directory — the Laravel project root — ahead of `PATH`. No code change; the behaviour is unchanged and documented as a known, unmitigated trust assumption.

## [0.1.0-alpha.1] - 2026-09-01

First alpha. The API may change before 1.0.

### Added

- `CommandStarting` listener that verifies an AWS IAM Identity Center session before `php artisan dev` starts, and runs `aws sso login` when the session can no longer be refreshed.
- Detection of static `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` credentials that would shadow the SSO profile, failing closed by default.
- Optional `expected_account_id` and `expected_role` guardrails, verified against `aws sts get-caller-identity`.
- `aws-sso:login` and `aws-sso:status` Artisan commands.
- Publishable configuration under the `aws-sso-config` tag; the package works without publishing it.

### Guardrail semantics

This is the first release, so these are stated as behaviour rather than as fixes. They are called out because each one is a place a guardrail could otherwise appear to pass while describing an identity the application would not use.

- `expected_role` is compared against the role component of the assumed-role ARN, exactly and case-sensitively, matching either the Identity Center permission set name or the generated role name in full. It is not a substring test over the ARN, which would accept any broader permission set whose name extends the expected one and would also be satisfied by a match in the session name.
- An identity that is not an assumed role never satisfies a role guardrail, which also rejects a profile backed by long-lived keys in `~/.aws/credentials`.
- Static environment credentials are a hard failure whenever a guardrail is configured; `fail_on_static_credentials => false` does not downgrade that case, because environment credentials take precedence over the profile in the AWS SDK chain.
- A guardrail value that cannot be compared to an identity throws `InvalidGuardrailConfiguration` rather than silently disabling the check. `null` and `false` mean "off".
- `aws-sso:login` and the `CommandStarting` listener never report the profile's identity as the one the application authenticated with while static credentials shadow the profile.

[unreleased]: https://github.com/cwilsn/laravel-aws-sso/compare/v0.1.0-alpha.1...HEAD
[0.1.0-alpha.1]: https://github.com/cwilsn/laravel-aws-sso/releases/tag/v0.1.0-alpha.1
