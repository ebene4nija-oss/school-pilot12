<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Result-checker PINs
    |--------------------------------------------------------------------------
    |
    | How many times one PIN opens the result it is bound to. Five mirrors what
    | Nigerian guardians already expect from WAEC and NECO scratch cards, and
    | leaves room for checking on a phone, a laptop and at a cybercafé without
    | anyone feeling they paid twice for one result.
    |
    */

    'result_pin_max_views' => (int) env('RESULT_PIN_MAX_VIEWS', 5),

    /*
    | Ceiling on a single wholesale PIN order. Guards against a mistyped
    | quantity turning into a nine-figure charge on a school's card before
    | anyone notices.
    */

    'result_pin_max_batch_quantity' => (int) env('RESULT_PIN_MAX_BATCH_QUANTITY', 10000),

    /*
    |--------------------------------------------------------------------------
    | The platform's own subdomain
    |--------------------------------------------------------------------------
    |
    | Every login resolves a tenant from the host, the operator's included, so
    | SchoolPilot holds a row in `schools` purely to give itself a subdomain to
    | authenticate through. That row is flagged `is_platform` and is excluded
    | from the tenant directory, from the school counts, and from suspension —
    | suspending it would lock the operator out of the console.
    |
    | This is only used to find the row on first migration. After that the flag
    | on the row is what counts, so renaming the subdomain does not turn
    | platform infrastructure back into a customer.
    |
    */

    'platform_subdomain' => env('PLATFORM_SUBDOMAIN', 'admin'),

    /*
    |--------------------------------------------------------------------------
    | Emails a school may send per day
    |--------------------------------------------------------------------------
    |
    | The platform-wide default; the operator console can set a different
    | number for one school, or switch a school's email off. Password-reset and
    | account-setup emails are never held back by it.
    |
    | One sending domain carries every school's mail, so one runaway broadcast
    | can get it blocklisted for all of them. 0 or empty means no limit.
    |
    */

    'email_daily_limit' => env('EMAIL_DAILY_LIMIT', 1000),

    /*
    |--------------------------------------------------------------------------
    | Operator console
    |--------------------------------------------------------------------------
    |
    | How long an operator's access to one school lasts after they open it with
    | a reason. Short enough that a forgotten tab does not leave a school open
    | all day; long enough for a support call. Re-opening starts a new grant.
    |
    */

    'operator_access_minutes' => (int) env('OPERATOR_ACCESS_MINUTES', 120),

    /*
    |--------------------------------------------------------------------------
    | Backups (read-only status for the operator console)
    |--------------------------------------------------------------------------
    |
    | Where deploy/cpanel/backup-db.sh writes its encrypted dumps and its log.
    | The app only reads these — it lists files and scans the log for the last
    | success. It never writes, restores or decrypts anything.
    |
    */

    'backup_dir' => env('BACKUP_DIR', '/home/schoolpilotorg/backups'),
    'backup_log' => env('BACKUP_LOG', '/home/schoolpilotorg/logs/backup.log'),

];
