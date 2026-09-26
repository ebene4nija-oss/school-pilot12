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

];
