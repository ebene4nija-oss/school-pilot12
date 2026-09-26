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

];
