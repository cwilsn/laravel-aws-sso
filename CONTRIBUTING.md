# Contributing

Thanks for your interest in improving Laravel AWS SSO.

## Scope

This package is deliberately small. It is a developer-experience bridge between `php artisan dev` and `aws sso login` — nothing more. It is not an AWS abstraction layer, an IAM management tool, or a credential manager.

Before proposing a feature, please open an issue. Changes that expand the scope, add configuration that does not solve a concrete problem, or introduce abstractions not justified by behaviour are unlikely to be merged.

## Getting started

```bash
git clone https://github.com/cwilsn/laravel-aws-sso.git
cd laravel-aws-sso
composer install
composer test
```

## Before opening a pull request

```bash
composer validate --strict
composer lint       # vendor/bin/pint --test
composer test       # vendor/bin/pest
```

CI additionally runs the suite on PHP 8.3, 8.4, and 8.5 against both `--prefer-lowest` and `--prefer-stable` dependencies.

## Testing rules

These are non-negotiable:

- **No test may contact AWS.** `tests/TestCase.php` starts process recording with no handlers and enables `Process::preventStrayProcesses()`, so any unfaked process fails the test.
- **No test may read the developer's `~/.aws` directory.**
- Use `tests/Fixtures/FakeAwsCli.php` for behaviour that sits above the CLI boundary, and Laravel's `Process::fake()` for the CLI boundary itself.
- Environment variables must be set through `TestCase::setEnvironmentVariable()` so they are cleaned up between tests.

## Code style

- `declare(strict_types=1)` everywhere, enforced by an architecture test.
- Return types and typed properties throughout.
- `final` classes unless extension is deliberately supported.
- Constructor injection over facades and container lookups inside domain classes.
- Laravel Pint (`composer format`) for formatting.

## Security-sensitive rules

Pull requests that violate any of these will be rejected:

- Never build an AWS command as a string. Argument arrays only — a profile name comes from user-controlled configuration and must never reach a shell. `exec`, `shell_exec`, `system`, `passthru`, backticks, and `proc_open` are blocked by an architecture test.
- Never print, log, or store credential values. Variable *names* are fine; values are not.
- Never read or write `~/.aws/credentials`, `~/.aws/config`, or `~/.aws/sso/cache`.
- Never persist tokens or credentials anywhere.

## Releasing

1. Ensure CI is green on every supported PHP version.
2. Move the `Unreleased` entries in `CHANGELOG.md` under the new version heading.
3. Tag the release (`git tag v1.0.0 && git push --tags`).
4. Packagist picks it up through the GitHub webhook.

This project follows [semantic versioning](https://semver.org/spec/v2.0.0.html).

## Supported versions

`composer.json` allows Laravel `^13.16`, the release that introduced `php artisan dev`. Orchestra Testbench 11 requires Laravel `^13.23`, so CI's `--prefer-lowest` job exercises 13.23 rather than 13.16. If you have a way to test the 13.16–13.22 range, contributions are welcome.
