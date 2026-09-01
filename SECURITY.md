# Security Policy

## Reporting a vulnerability

Please do **not** open a public issue for a security vulnerability.

Report it privately through [GitHub's private vulnerability reporting](https://github.com/cwilsn/laravel-aws-sso/security/advisories/new), or by email to the maintainer listed in `composer.json`. You should receive an acknowledgement within a few business days.

Please include a description of the issue, the affected version, and steps to reproduce.

## Supported versions

| Version | Supported |
|---------|-----------|
| 0.1.x   | ✅        |

This package is in alpha. Until 1.0 the API may change between minor versions, and only the latest 0.1.x release receives fixes.

## Security model

Understanding what this package does and does not protect is the point of this section.

### What the package handles

- It verifies that a named AWS profile has a usable IAM Identity Center session by calling `aws sts get-caller-identity`.
- It starts `aws sso login` when that session can no longer be refreshed.
- It optionally asserts that the resulting identity belongs to an expected AWS account and permission set.
- It detects static environment credentials that would silently take precedence over the SSO profile.

### What the package deliberately does not do

- **It never stores credentials.** No access keys, secret keys, session tokens, or SSO tokens are read, written, cached, or logged by this package. The AWS CLI and the AWS SDK credential chain remain solely responsible for credentials.
- **It never reads or writes `~/.aws`.** Not `credentials`, not `config`, not the SSO token cache under `~/.aws/sso/cache`. Those are AWS implementation details and are treated as off-limits.
- **It never prints credential values.** Error messages name environment variables; they never contain their contents.
- **It never runs during HTTP requests**, and never in production unless the configuration is explicitly changed.

### Command injection

The AWS profile name originates in application configuration, which is user-controlled input. Every AWS CLI invocation is built as an argument array and handed to `proc_open` without a shell, so a value such as `foo; rm -rf /` is passed through as a single, inert argv entry.

`exec`, `shell_exec`, `system`, `passthru`, backticks, and direct `proc_open` calls are forbidden in this package and enforced by an architecture test. A regression test asserts that shell metacharacters in a profile name remain a single process argument.

### Guardrails fail closed

The optional `expected_account_id` and `expected_role` guardrails are a security control, so every path that makes one unenforceable is an error rather than a silent pass:

- **The role guardrail compares the role component of the ARN, never a substring of the whole ARN.** A substring test accepts any broader permission set whose name extends the expected one (`LaravelDeveloperAdmin` contains `LaravelDeveloper`) and is also satisfied by a match in the session name. The comparison is exact and case-sensitive against the Identity Center permission set name or the generated role name.
- **An identity that is not an assumed role can never satisfy a role guardrail.** This also rejects a profile backed by long-lived keys in `~/.aws/credentials` rather than an Identity Center session.
- **Static environment credentials with a guardrail configured is a hard failure**, and `fail_on_static_credentials => false` does not downgrade it. The AWS SDK resolves environment credentials before the profile, so verifying the profile would assert about an identity the application will not use — a check that reads as passing while describing the wrong thing.
- **A guardrail value that cannot be compared to an identity is a configuration error.** Only `null` and `false` mean "off"; an array, an object, or `true` throws rather than silently disabling the check.

When static credentials are tolerated and no guardrail is configured, the package still declines to report the profile's identity as the one the application authenticated with, because it is not.

### Trust assumptions

- **The `aws` executable is invoked by bare name, never an absolute path**, with the environment and working directory the Artisan process already has. The package trusts the developer's machine to the same degree the developer already does; it does not sandbox or pin the binary.

  How that name resolves is platform-dependent, and on Windows it is not simply `PATH`. Symfony's Process component runs with `bypass_shell` enabled, so the name is resolved by `CreateProcess`, whose search order places **the current directory ahead of `PATH`**. Artisan's current directory is the Laravel project root, so an `aws.exe` sitting in a project root would be preferred over the installed AWS CLI. (Only `.exe` — bypassing the shell means `aws.bat` and `aws.cmd` are not candidates.) On Linux and macOS the current directory is not searched unless `PATH` itself contains `.`.

  This is known and not currently mitigated. In practice it is bounded by the fact that reaching it means running `composer install` and booting the project's service providers from an untrusted repository, both of which already execute arbitrary code well before the AWS CLI is invoked. A future version may give the subprocess a neutral working directory, which costs nothing — the AWS CLI reads its configuration from `~/.aws` and does not depend on where it runs.
- Guardrails constrain what this package will let start. They do not constrain what the application does afterwards — the AWS SDK resolves credentials independently on every call.

### This package does not make your AWS permissions safe

Authenticating an `AdministratorAccess` profile gives your local application administrator access for the duration of the session. IAM Identity Center replaces long-lived keys with short-lived ones; it does not reduce what those credentials can do.

Create a least-privileged permission set for local application use, point `AWS_PROFILE` at it, and consider setting `AWS_SSO_EXPECTED_ROLE` so an unexpected permission set becomes a hard error.
