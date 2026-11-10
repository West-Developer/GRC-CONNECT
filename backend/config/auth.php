<?php

use App\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" and password
    | reset "broker" for your application. You may change these values
    | as required, but they're a perfect start for most applications.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | which utilizes session storage plus the Eloquent user provider.
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | Supported: "session"
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | If you have multiple user tables or models you may configure multiple
    | providers to represent the model / table. These providers may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],

        // 'users' => [
        //     'driver' => 'database',
        //     'table' => 'users',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | These configuration options specify the behavior of Laravel's password
    | reset functionality, including the table utilized for token storage
    | and the user provider that is invoked to actually retrieve users.
    |
    | The expiry time is the number of minutes that each reset token will be
    | considered valid. This security feature keeps tokens short-lived so
    | they have less time to be guessed. You may change this as needed.
    |
    | The throttle setting is the number of seconds a user must wait before
    | generating more password reset tokens. This prevents the user from
    | quickly generating a very large amount of password reset tokens.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => env('AUTH_PASSWORD_RESET_EXPIRE', 1440),
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | One-time Account Setup Codes
    |--------------------------------------------------------------------------
    |
    | Invited Students, Faculty and staff finish their account with a six-digit
    | code (see App\Support\Auth\AccountSetupCodes). A six-digit code is only
    | safe with a guess limit: after this many wrong attempts the code stops
    | working and a new invitation must be sent. Its expiry follows
    | `passwords.users.expire`.
    |
    */

    'setup_codes' => [
        'max_attempts' => (int) env('AUTH_SETUP_CODE_MAX_ATTEMPTS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | One-time Password Reset Codes
    |--------------------------------------------------------------------------
    |
    | An already-active account's self-service password reset (see
    | App\Support\Auth\PasswordResetCodes), the same six-digit-code-plus-
    | guess-limit shape as `setup_codes` above but deliberately its own
    | config key — a change to one must never silently retune the other.
    |
    */

    'password_reset' => [
        'expire' => (int) env('AUTH_PASSWORD_RESET_CODE_EXPIRE', 60),
        'max_attempts' => (int) env('AUTH_PASSWORD_RESET_MAX_ATTEMPTS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Login Email OTP (Second Factor)
    |--------------------------------------------------------------------------
    |
    | A six-digit email code required at login (see App\Support\Auth\
    | LoginOtpChallenges / LoginOtpPolicy), unless the account verified one
    | within the last `grace_minutes` — so it is not required on every single
    | login. Per-account, not per-device, by design (auth-hardening batch,
    | 2026-09-29, owner-delegated trade-off). `queue_kiosk` accounts never
    | require it.
    |
    | `enforced_after`: owner-confirmed transitional gap — the same rollout
    | allowance already applied to the new password-complexity rule extends
    | to login OTP too. Any account created *before* this moment is
    | permanently exempt from OTP; only accounts created from this point
    | forward are subject to it. This is a TEMPORARY allowance for a smooth
    | rollout, not a permanent decision — revisit and remove before real
    | production go-live (see PROGRESS.md's "Known transitional gap" note).
    | The default below is the exact moment this exemption was introduced.
    |
    */

    'login_otp' => [
        'grace_minutes' => (int) env('LOGIN_OTP_GRACE_MINUTES', 30),
        'max_attempts' => (int) env('LOGIN_OTP_MAX_ATTEMPTS', 5),
        'challenge_ttl_minutes' => (int) env('LOGIN_OTP_CHALLENGE_TTL_MINUTES', 10),
        'enforced_after' => env('LOGIN_OTP_ENFORCED_AFTER', '2026-09-29 11:47:46'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the amount of seconds before a password confirmation
    | window expires and users are asked to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
