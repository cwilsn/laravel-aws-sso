# Laravel AWS SSO

**AWS IAM Identity Center authentication for local Laravel development.**

Laravel AWS SSO checks that your configured AWS profile can authenticate before `php artisan dev` starts. If the profile needs a fresh Identity Center session, it runs `aws sso login` and opens the browser. While Laravel is running, a companion process continues checking the session and lets you sign in again without restarting the rest of your development processes.

> Use a dedicated, least-privileged permission set for local development. Your application receives every permission granted to the selected profile.

## Requirements

| Dependency | Version |
|---|---|
| PHP | `^8.3` |
| Laravel | `^13.18` |
| AWS CLI | v2 with IAM Identity Center support |

The package does not require `aws/aws-sdk-php`. Your application can continue using its existing AWS SDK dependencies.

## Installation

### 1. Configure an Identity Center profile

```bash
aws configure sso --profile my-dev-profile
aws sts get-caller-identity --profile my-dev-profile
```

Choose the least-privileged permission set your local application needs. See the [AWS CLI IAM Identity Center guide](https://docs.aws.amazon.com/cli/latest/userguide/cli-configure-sso.html) for profile setup details.

### 2. Install the package

```bash
composer require cwilsn/laravel-aws-sso:^1.0 --dev
```

The service provider is auto-discovered. Install this package as a dev dependency; it is not intended for deployed applications.

### 3. Configure your environment

Remove static credentials from `.env`:

```diff
- AWS_ACCESS_KEY_ID=AKIA...
- AWS_SECRET_ACCESS_KEY=...
```

Set the profile you configured:

```dotenv
AWS_PROFILE=my-dev-profile
AWS_DEFAULT_REGION=us-east-1
```

Then start Laravel normally:

```bash
php artisan dev
```

The first run may open the Identity Center login page. Later runs start immediately while the session remains usable.

## Configuration

The defaults work without publishing anything. To customise them:

```bash
php artisan vendor:publish --tag=aws-sso-config
```

| Option | Default | Purpose |
|---|---|---|
| `enabled` | `true` | Enable automatic authentication checks. |
| `profile` | `AWS_PROFILE` or `default` | AWS CLI profile to verify. |
| `commands` | `['dev']` | Artisan commands checked before startup. |
| `environments` | `['local']` | Laravel environments where automatic checks run. |
| `monitor` | `true` | Add the `aws-sso` companion process to `php artisan dev`. |
| `monitor_interval` | `60` | Seconds between background checks. |
| `fail_on_static_credentials` | `true` | Fail when environment access keys would override the profile. |
| `show_identity_after_login` | `true` | Print the profile ARN after automatic login. |
| `expected_account_id` | `null` | Require an exact AWS account ID. |
| `expected_role` | `null` | Require an exact permission-set or role name. |

Automatic checks run only for configured commands and environments. The manual commands remain available wherever the package is installed.

Laravel configuration caching freezes the selected profile. After changing `AWS_PROFILE`, run:

```bash
php artisan config:clear
```

## Identity guardrails

Pin the account and permission set your application is allowed to use:

```dotenv
AWS_SSO_EXPECTED_ACCOUNT_ID=123456789012
AWS_SSO_EXPECTED_ROLE=LaravelDeveloper
```

- Account IDs are compared exactly.
- Role matching is exact and case-sensitive. For Identity Center roles, use the permission-set name, such as `LaravelDeveloper`, or the complete generated role name.
- IAM users and the account root cannot satisfy a role guardrail.
- If `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` are present, configured guardrails fail closed even when `fail_on_static_credentials` is `false`.
- `null`, `false`, and blank strings disable a guardrail. Ensure templated environment values are non-empty when enforcement is expected.

Guardrails reduce wrong-account and wrong-role mistakes, but they do not replace least-privileged IAM permissions.

## Commands

### Login

Authenticate on demand. This does nothing when the profile is already usable.

```bash
php artisan aws-sso:login
php artisan aws-sso:login --profile=another-profile
```

### Status

Inspect the CLI, profile, environment credentials, and current identity without opening a browser. The command exits non-zero when authentication is invalid.

```bash
php artisan aws-sso:status
php artisan aws-sso:status --profile=another-profile
```

## Session monitoring

The `aws-sso` process checks the profile every 60 seconds by default. Background checks never open a browser.

If the session expires, select the `aws-sso` tab in Laravel's development UI and press `r`. If your terminal does not provide tab controls, sign in from another terminal:

```bash
php artisan aws-sso:login
```

The watcher resumes once the profile becomes usable. It does not stop Laravel's other development processes.

## Security notes

- The package never writes credentials or modifies `~/.aws/config`, `~/.aws/credentials`, or the SSO cache.
- Static credential values are never printed; only the variable names are reported.
- `aws sts get-caller-identity` verifies the resulting principal, not the source of its credentials. Configure the profile with `aws configure sso`; other valid credential sources can also satisfy STS.
- The package verifies its configured CLI profile. AWS clients using explicit credentials, another profile, or a custom provider are outside that check and must be kept aligned separately.
- AWS commands are passed to Symfony Process as argument arrays. Configuration is not concatenated into shell command strings.

See [SECURITY.md](SECURITY.md) for the full security model and vulnerability reporting instructions.

## Troubleshooting

### AWS CLI not found

Install [AWS CLI v2](https://docs.aws.amazon.com/cli/latest/userguide/getting-started-install.html), then configure your profile with `aws configure sso`.

### Profile not found

```bash
aws configure sso --profile my-dev-profile
```

### Laravel uses old credentials or the wrong profile

Remove `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` from `.env`, your shell profile, and your editor environment. Check for AWS clients constructed with explicit credentials, then clear cached Laravel configuration:

```bash
php artisan config:clear
php artisan aws-sso:status
```

### Wrong account or role

Inspect the profile directly, correct its permission-set assignment, and consider enabling both identity guardrails:

```bash
aws sts get-caller-identity --profile my-dev-profile
```

### Sign out

```bash
aws sso logout
```

## Development

```bash
composer check
```

The test suite never contacts AWS or reads your `~/.aws` directory.

See [CHANGELOG.md](CHANGELOG.md), [CONTRIBUTING.md](CONTRIBUTING.md), and [LICENSE.md](LICENSE.md).
