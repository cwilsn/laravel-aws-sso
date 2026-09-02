# Changelog

Notable changes to `laravel-aws-sso`, following [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.1] - 2026-09-01

### Documentation

- Added a workflow-first guide and dev-console screenshot to the README.
- Simplified the security policy and clarified its trust boundaries. Runtime behavior is unchanged.

## [1.0.0] - 2026-09-01

### Documentation

- Streamlined the README for the stable release. Runtime behavior is unchanged.

## [0.1.0-alpha.2] - 2026-09-01

### Added

- Added an `aws-sso` tab to `php artisan dev` that monitors the session every 60 seconds and supports signing in with `r` without restarting other processes.
- Added `monitor` and `monitor_interval` configuration options.

### Changed

- Raised the minimum Laravel version from 13.16 to 13.18.

### Documentation

- Documented the Windows `aws.exe` resolution trust assumption. Runtime behavior is unchanged.

## [0.1.0-alpha.1] - 2026-09-01

First alpha. The API may change before 1.0.

### Added

- Automatic Identity Center authentication before `php artisan dev` starts.
- Static environment credential detection that fails closed by default.
- Optional, exact account and role guardrails that fail closed when they cannot be enforced.
- `aws-sso:login` and `aws-sso:status` commands.
- Publishable configuration with working defaults.

[unreleased]: https://github.com/cwilsn/laravel-aws-sso/compare/v1.0.1...HEAD
[1.0.1]: https://github.com/cwilsn/laravel-aws-sso/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/cwilsn/laravel-aws-sso/compare/v0.1.0-alpha.2...v1.0.0
[0.1.0-alpha.2]: https://github.com/cwilsn/laravel-aws-sso/compare/v0.1.0-alpha.1...v0.1.0-alpha.2
[0.1.0-alpha.1]: https://github.com/cwilsn/laravel-aws-sso/releases/tag/v0.1.0-alpha.1
