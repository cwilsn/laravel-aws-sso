# Laravel AWS SSO

**Seamless AWS IAM Identity Center authentication for local Laravel development.**

> **Alpha.** This is `v0.1.0-alpha.1`. It is being used and tested in anger, but the API may change before 1.0 and the configuration keys are not yet frozen. Feedback and bug reports are welcome.

Stop storing long-lived AWS access keys in your Laravel `.env`. Laravel AWS SSO ensures your IAM Identity Center session is valid when `php artisan dev` starts, and only asks you to sign in when necessary.

```bash
# First run of the day
$ php artisan dev
AWS SSO session for [my-dev-profile] has expired. Signing in...
Attempting to automatically open the SSO authorization page in your default browser...
Successfully logged into Start URL: https://example.awsapps.com/start
AWS authenticated with [my-dev-profile]: arn:aws:sts::123456789012:assumed-role/AWSReservedSSO_LaravelDeveloper_0a1b/you

# Every run after that
$ php artisan dev
# Laravel's dev UI starts immediately. No AWS noise.
```

---

## Contents

- [Why this package exists](#why-this-package-exists)
- [Requirements](#requirements)
- [Installation](#installation)
- [How it works](#how-it-works)
- [Configuration](#configuration)
- [Identity guardrails](#identity-guardrails)
  - [Guardrails and static credentials](#guardrails-and-static-credentials)
- [Commands](#commands)
- [What this package never does](#what-this-package-never-does)
- [Troubleshooting](#troubleshooting)
- [Testing, changelog, contributing, security](#testing)

---

## Why this package exists

Long-lived IAM user access keys in a local `.env` are a persistent liability:

- They leak into shell history, backups, support bundles, screenshots, and accidental commits.
- They stay valid for months or years unless somebody manually rotates them.
- They are routinely granted far broader permissions than the app actually needs.
- Revoking one means finding every developer who has a copy.

AWS IAM Identity Center replaces them with a browser sign-in that issues **short-lived role credentials**. The AWS CLI and the AWS SDK for PHP both understand Identity Center profiles natively — the only friction left is remembering to run `aws sso login` before you start work. This package removes that last step.

### ⚠️ This package does not make your AWS permissions safe

> **If you authenticate an `AdministratorAccess` profile, your local application effectively has administrator access for the entire session.** A stray `Storage::delete()`, a bad migration against the wrong account, or a compromised dependency inherits everything that permission set can do.
>
> Create a dedicated, least-privileged permission set for local application use and point `AWS_PROFILE` at that.

A sensible split looks like this:

```text
Your Identity Center user
  |
  +-- AdministratorAccess     permission set   (human admin work, console only)
  |
  +-- LaravelDeveloper        permission set   (what the local app runs as)
```

`LaravelDeveloper` should grant only what the application actually touches — the development S3 bucket, the development SQS queues, the SES sandbox, and nothing else. Use [`expected_role`](#identity-guardrails) to make it a hard error if the wrong permission set is ever active.

---

## Requirements

| | |
|---|---|
| PHP | `^8.3` |
| Laravel | `^13.16` (the release that introduced `php artisan dev`) |
| AWS CLI | v2, with IAM Identity Center support |

This package does **not** require `aws/aws-sdk-php`. It talks to the AWS CLI only; your application keeps whatever AWS SDK dependencies it already has.

---

## Installation

### Step 1 — Configure an IAM Identity Center profile

This assumes your organization has IAM Identity Center enabled and you have been assigned a permission set. On your machine:

```bash
aws configure sso --profile my-dev-profile
```

Follow the prompts, choosing the **least-privileged** permission set for local development. See the [AWS CLI Identity Center guide](https://docs.aws.amazon.com/cli/latest/userguide/cli-configure-sso.html) for the full walkthrough, and [`aws configure sso-session`](https://docs.aws.amazon.com/cli/latest/userguide/sso-configure-profile-token.html) if you want one session shared across profiles.

Confirm it works before installing anything:

```bash
aws sts get-caller-identity --profile my-dev-profile
```

### Step 2 — Install the package

```bash
composer require cwilsn/laravel-aws-sso:^0.1.0-alpha --dev
```

The stability flag is required while this is an alpha — Composer will not resolve a pre-release version under the default `minimum-stability: stable`.

Install it as a **dev dependency**. Its only job is improving local developer authentication; it has no place in a deployed application.

The service provider is auto-discovered. There is nothing to register and nothing to publish.

### Step 3 — Update your `.env`

Remove any static credentials:

```diff
- AWS_ACCESS_KEY_ID=AKIA...
- AWS_SECRET_ACCESS_KEY=...
```

Add your profile:

```dotenv
AWS_PROFILE=my-dev-profile
```

Keep the rest of your normal AWS settings:

```dotenv
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=my-development-bucket
```

`AWS_PROFILE` is the standard variable understood by the AWS CLI, the AWS SDK for PHP, and this package. One value drives all three.

> Laravel enables Dotenv's `PutenvAdapter` by default, so a value set in `.env` is visible to libraries that resolve it through `getenv()` — which is how the AWS SDK finds it.

### Step 4 — Run Laravel

```bash
php artisan dev
```

That's the whole setup. Your existing AWS code keeps working unchanged, now backed by short-lived credentials:

```php
Storage::disk('s3')->put('example.txt', 'Hello');
```

---

## How it works

Laravel 13 deliberately prevents vendor packages from registering processes with `Illuminate\Foundation\DevCommands`, so this package leaves the native `dev` command completely alone. It listens for `Illuminate\Console\Events\CommandStarting` instead, which Laravel dispatches immediately before an Artisan command executes.

```text
php artisan dev
      │
      ▼
Laravel boots normally
      │
      ▼
CommandStarting('dev')
      │
      ├─▶ is the package enabled, in a guarded environment, for a guarded command?
      ├─▶ verify no conflicting static AWS credentials
      ├─▶ verify the AWS CLI is installed
      ├─▶ aws sts get-caller-identity --profile <profile>
      │     └─ fails? ─▶ aws sso login --profile <profile> ─▶ verify again
      ├─▶ check the optional account / role guardrails
      │
      ▼
Laravel's native dev command starts
```

Authentication state is determined by making a **real API call** (`aws sts get-caller-identity`), which validates the whole chain at once: the CLI is present, the profile exists, its SSO configuration is valid, the cached Identity Center session is usable or refreshable, and role credentials can actually be obtained.

The package never reads `~/.aws/sso/cache`, never computes expiry timestamps itself, and never touches your AWS configuration files. The AWS CLI already refreshes role credentials automatically while the underlying Identity Center session is alive; a browser login is only started when it genuinely cannot be refreshed.

If authentication fails, the exception propagates and `php artisan dev` does not start. You never end up with a running app that quietly has no AWS access.

---

## Configuration

Installation works without publishing anything. To customise:

```bash
php artisan vendor:publish --tag=aws-sso-config
```

```php
return [
    // Master switch for the automatic check. The manual commands stay available.
    'enabled' => env('AWS_SSO_ENABLED', true),

    // The same profile the AWS CLI and AWS SDK use.
    'profile' => env('AWS_PROFILE', 'default'),

    // Artisan commands that require a valid session before they start.
    'commands' => ['dev'],

    // Application environments in which the check runs.
    'environments' => ['local'],

    // Fail closed when AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY are set.
    'fail_on_static_credentials' => true,

    // Print the assumed-role ARN after an interactive login.
    'show_identity_after_login' => true,

    // Optional guardrails. Off unless configured.
    'expected_account_id' => env('AWS_SSO_EXPECTED_ACCOUNT_ID'),
    'expected_role' => env('AWS_SSO_EXPECTED_ROLE'),
];
```

### Profile resolution

1. `--profile` on `aws-sso:login` / `aws-sso:status`
2. `config('aws-sso.profile')`
3. `AWS_PROFILE`
4. `default`

### Scope

The check runs only when **all** of the following hold: the application is running in the console, the package is enabled, the current environment is listed in `environments`, and the starting command is listed in `commands`.

That means `php artisan migrate`, `route:list`, `test`, and `queue:work` are unaffected, no browser ever opens during an HTTP request, and nothing happens in production unless you explicitly change the configuration.

Add commands you want guarded:

```php
'commands' => ['dev', 'queue:work'],
```

### Non-interactive Artisan

If a login would be required but Artisan was invoked with `--no-interaction`, the package aborts rather than opening a browser nobody is watching:

```text
AWS SSO authentication is required, but Artisan is running non-interactively.
Run `aws sso login --profile my-dev-profile` first.
```

### Static credential detection

The AWS SDK for PHP checks environment credentials **before** SSO profiles in its default provider chain. That means you can sign in to SSO successfully while your application quietly keeps using stale access keys. The package detects this and fails closed:

```text
Static AWS credentials are configured in the environment.
AWS SDKs prefer AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY over the SSO profile [my-dev-profile].
Remove those variables from your .env so the SDK can use the profile.
```

Set `fail_on_static_credentials` to `false` to downgrade this to a warning. Credential *values* are never printed or logged — only variable names.

When it is downgraded, the package still refuses to claim your application authenticated as the profile, because it will not:

```text
AWS profile [my-dev-profile] resolves to: arn:aws:sts::123456789012:assumed-role/AWSReservedSSO_LaravelDeveloper_0a1b/you

WARN  That is the identity of the profile, not the one your application will use. The AWS SDK
      resolves the static credentials in your environment first.
```

`fail_on_static_credentials` cannot be used to downgrade the [guardrail case](#guardrails-and-static-credentials).

`AWS_SESSION_TOKEN` on its own is reported by `aws-sso:status` for diagnostics but is not treated as an unsafe long-lived credential.

---

## Identity guardrails

Both guardrails are optional and inactive unless configured.

```dotenv
AWS_SSO_EXPECTED_ACCOUNT_ID=123456789012
AWS_SSO_EXPECTED_ROLE=LaravelDeveloper
```

**Account.** If STS reports a different account, authentication fails:

```text
AWS profile [my-dev-profile] authenticated to account [999999999999]; expected [123456789012].
```

**Role.** Identity Center generates role names such as `AWSReservedSSO_LaravelDeveloper_0a1b2c3d4e5f`, so `expected_role` is matched against the **permission set name**, recovered from the role component of the assumed-role ARN. The comparison is exact and case-sensitive:

```text
AWS profile [my-dev-profile] authenticated as an unexpected role.
Expected permission set or role: LaravelDeveloper
Actual permission set or role: AdministratorAccess
Actual identity: arn:aws:sts::123456789012:assumed-role/AWSReservedSSO_AdministratorAccess_9f8e7d/you
```

Either spelling of the same role is accepted — the permission set name (`LaravelDeveloper`) or the generated role name in full (`AWSReservedSSO_LaravelDeveloper_0a1b2c3d4e5f`). A plain IAM role is matched by its own name.

The match is deliberately **not** a substring test over the ARN. A substring test would accept any broader permission set whose name merely extends the expected one — `LaravelDeveloperAdmin` contains `LaravelDeveloper` — and would also be satisfied by a match anywhere else in the ARN, including the session name. Both cases pass a guardrail while the application runs as something other than the role you pinned.

An identity that is not an assumed role at all — an IAM user, the account root — can never satisfy a role guardrail. That also catches a profile backed by long-lived keys in `~/.aws/credentials` rather than an Identity Center session.

This is the cheapest way to stop yourself from running the local app as an administrator by accident. It is a guardrail, not a security boundary — the real control is the permission set itself.

### Guardrails and static credentials

A guardrail describes the identity your application must run as. Environment credentials come ahead of the SSO profile in the AWS SDK's chain, so while `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` are set, checking the profile would assert about an identity your application is not going to use.

When a guardrail is configured and static credentials are present, the package therefore **fails closed regardless of `fail_on_static_credentials`**:

```text
Static AWS credentials are configured in the environment, so the account and role guardrails cannot be enforced.
```

A guardrail that quietly does not apply is worse than no guardrail, because it reads as a passing check. Remove the variables, or unset the guardrails.

### Malformed guardrail values

A guardrail set to something that cannot be compared to an identity — an array, an object, `true` — is a configuration error rather than a silent no-op, so a typo cannot disable the check:

```text
The [aws-sso.expected_account_id] guardrail is configured with a bool, which cannot be compared to an AWS identity.
Set it to a string, or to null to turn the guardrail off.
```

`null` and `false` both mean "off". Quote account IDs — an unquoted `012345678901` is an octal literal in PHP, not an account number.

---

## Commands

### `php artisan aws-sso:login`

Authenticate on demand — useful for troubleshooting, or before running a command that isn't in the guarded list. Does nothing if the session is already valid.

```bash
php artisan aws-sso:login
php artisan aws-sso:login --profile=another-profile
```

### `php artisan aws-sso:status`

Report the current state without ever starting a browser login. Exits non-zero when authentication is not valid, so it works in a script.

```text
AWS CLI:       available
Profile:       my-dev-profile
Env credentials: none
Authenticated: yes
Account:       123456789012
Identity:      arn:aws:sts::123456789012:assumed-role/AWSReservedSSO_LaravelDeveloper_0a1b/you
```

---

## What this package never does

- Create IAM users, access keys, or permission sets.
- Write credentials to `.env`, `~/.aws/credentials`, or anywhere else.
- Read, copy, or parse SSO cache tokens under `~/.aws/sso/cache`.
- Modify `~/.aws/config`, `config/filesystems.php`, queue config, mail config, or any AWS client configuration.
- Install the AWS CLI or run `aws configure sso` for you.
- Register a vendor `DevCommands` process, or wrap, replace, or reimplement Laravel's `dev` command.
- Build shell command strings from configuration. Every AWS invocation is an argument array passed straight to `proc_open`, so a profile name can never be interpreted as shell syntax.
- Run during HTTP requests, or in production unless you explicitly configure it to.

---

## Troubleshooting

### `aws` not found

```text
AWS CLI v2 was not found.
```

Install [AWS CLI v2](https://docs.aws.amazon.com/cli/latest/userguide/getting-started-install.html), then run `aws configure sso`. The package will never install it for you.

### Profile not found

```bash
aws configure sso --profile my-dev-profile
```

`aws configure sso` is a one-time setup that requires choices the package should not make on your behalf, so it is never run automatically.

### Session expired

No action needed — `php artisan dev` starts the login for you. Manual fallback:

```bash
aws sso login --profile my-dev-profile
```

### Laravel still uses old credentials

Check for `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` in your `.env`, your shell profile (`~/.zshrc`, `~/.bashrc`), and your editor's environment. Remove them.

Also check for application code that passes an explicit `credentials` array to an AWS SDK client — that bypasses the provider chain entirely. Let the SDK resolve credentials itself.

Run `php artisan aws-sso:status` to see exactly which AWS variables are set.

### Wrong account or role

```bash
aws sts get-caller-identity --profile my-dev-profile
```

Then fix the profile in `~/.aws/config` or your Identity Center permission set assignment. Consider setting `AWS_SSO_EXPECTED_ACCOUNT_ID` and `AWS_SSO_EXPECTED_ROLE` so this fails fast next time.

### Signing out

The AWS CLI already handles this:

```bash
aws sso logout
```

---

## Testing

```bash
composer test
```

The suite never touches AWS. Every process invocation is faked, stray processes are blocked, and no test reads your `~/.aws` directory.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## Security

See [SECURITY.md](SECURITY.md) for the security model and how to report a vulnerability.

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
