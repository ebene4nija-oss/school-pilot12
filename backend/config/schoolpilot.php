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

];
