<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Master switch for the automatic authentication check. Turning this off
    | leaves the `aws-sso:login` and `aws-sso:status` commands available.
    |
    */

    'enabled' => env('AWS_SSO_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | AWS Profile
    |--------------------------------------------------------------------------
    |
    | The named AWS profile to authenticate. This intentionally reads the same
    | AWS_PROFILE variable the AWS CLI and the AWS SDK for PHP already use, so
    | one value drives every tool.
    |
    */

    'profile' => env('AWS_PROFILE', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Guarded Commands
    |--------------------------------------------------------------------------
    |
    | Artisan commands that require a valid AWS session before they start. Only
    | add commands you are happy to have open a browser login.
    |
    */

    'commands' => [
        'dev',
    ],

    /*
    |--------------------------------------------------------------------------
    | Guarded Environments
    |--------------------------------------------------------------------------
    |
    | Application environments in which the check runs. Keep this to local
    | development; a deployed application should never wait on a browser.
    |
    */

    'environments' => [
        'local',
    ],

    /*
    |--------------------------------------------------------------------------
    | Fail On Static Credentials
    |--------------------------------------------------------------------------
    |
    | The AWS SDK credential chain reads AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY
    | before it reads SSO profiles, so leftover keys silently beat a fresh SSO
    | session. Fail closed by default; set to false to warn and continue.
    |
    */

    'fail_on_static_credentials' => true,

    /*
    |--------------------------------------------------------------------------
    | Show Identity After Login
    |--------------------------------------------------------------------------
    |
    | Print the assumed-role ARN after an interactive login completes. Useful
    | for confirming which permission set the application is running under.
    |
    */

    'show_identity_after_login' => true,

    /*
    |--------------------------------------------------------------------------
    | Expected Account
    |--------------------------------------------------------------------------
    |
    | Optional guardrail. When set, authentication fails unless STS reports
    | this exact AWS account ID.
    |
    */

    'expected_account_id' => env('AWS_SSO_EXPECTED_ACCOUNT_ID'),

    /*
    |--------------------------------------------------------------------------
    | Expected Role
    |--------------------------------------------------------------------------
    |
    | Optional guardrail. When set, the assumed-role ARN reported by STS must
    | contain this string. Identity Center generates role names such as
    | `AWSReservedSSO_LaravelDeveloper_0a1b2c3d4e5f`, so use the permission set
    | name rather than a full ARN.
    |
    */

    'expected_role' => env('AWS_SSO_EXPECTED_ROLE'),

];
