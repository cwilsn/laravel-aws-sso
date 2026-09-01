# Changelog

All notable changes to `laravel-aws-sso` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `CommandStarting` listener that verifies an AWS IAM Identity Center session before `php artisan dev` starts, and runs `aws sso login` when the session can no longer be refreshed.
- Detection of static `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` credentials that would shadow the SSO profile, failing closed by default.
- Optional `expected_account_id` and `expected_role` guardrails, verified against `aws sts get-caller-identity`.
- `aws-sso:login` and `aws-sso:status` Artisan commands.
- Publishable configuration under the `aws-sso-config` tag; the package works without publishing it.
