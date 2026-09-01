# Security Policy

## Reporting a vulnerability

Please do **not** open a public issue for a security vulnerability.

Report it privately through [GitHub's private vulnerability reporting](https://github.com/cwilsn/laravel-aws-sso/security/advisories/new), or by email to the maintainer listed in `composer.json`. You should receive an acknowledgement within a few business days.

Please include a description of the issue, the affected version, and steps to reproduce.

## Supported versions

| Version | Supported |
|---------|-----------|
| 1.x     | ✅        |

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

### This package does not make your AWS permissions safe

Authenticating an `AdministratorAccess` profile gives your local application administrator access for the duration of the session. IAM Identity Center replaces long-lived keys with short-lived ones; it does not reduce what those credentials can do.

Create a least-privileged permission set for local application use, point `AWS_PROFILE` at it, and consider setting `AWS_SSO_EXPECTED_ROLE` so an unexpected permission set becomes a hard error.
