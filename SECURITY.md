# Security Policy

## Report a vulnerability

Do **not** open a public issue. Use [GitHub's private vulnerability reporting](https://github.com/cwilsn/laravel-aws-sso/security/advisories/new), or email the maintainer listed in `composer.json`.

Include the affected version, a clear description, and steps to reproduce. You should receive an acknowledgement within a few business days.

## Security boundary

Laravel AWS SSO is a local-development CLI helper. It verifies a named AWS profile with `aws sts get-caller-identity`, starts `aws sso login` when an interactive session needs renewal, optionally enforces an expected account and role, and detects environment credentials that would override the profile.

It does not authenticate HTTP requests, manage IAM permissions, or replace the AWS CLI or SDK credential chain.

## Security guarantees

- The PHP package never directly reads or writes `~/.aws`, including AWS configuration, credentials, and SSO cache files. The AWS CLI it invokes remains responsible for those files.
- It checks whether `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, and `AWS_SESSION_TOKEN` are present, but never retains or prints their values.
- AWS commands are passed through Laravel's Process API as argument arrays without a shell. The profile remains one argument, and architecture tests forbid direct shell-execution functions.
- Account matching is exact. Role matching is exact and case-sensitive against the permission-set name or generated role name; a non-assumed-role identity cannot satisfy it.
- Configured guardrails fail closed when static environment credentials shadow the profile or when a value cannot be compared safely. `null`, `false`, and blank strings disable a guardrail.
- Automatic checks run only for configured console commands and environments. With the defaults, that means `php artisan dev` locally. Periodic background checks never open a browser.

If static credentials are explicitly allowed and no guardrail is configured, the package warns instead of claiming the application is using the verified profile.

## Known limitations and safe use

- The package invokes `aws` by name and trusts the operating system's executable resolution. On Windows, an `aws.exe` in the project directory may be selected before the installed CLI. This is an accepted risk because installing and booting an untrusted Laravel project already permits arbitrary code execution.
- The package verifies its configured CLI profile at check time. An application can still use explicit credentials, another profile, or a custom provider, and the AWS SDK resolves credentials independently between checks.
- SSO provides short-lived credentials; it does not make broad permissions safe. Use a dedicated, least-privileged permission set and consider configuring both account and role guardrails.
- The package is intended for local development. Enabling it in other environments is an explicit configuration choice.
