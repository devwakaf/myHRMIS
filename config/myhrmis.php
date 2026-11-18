<?php

return [

    /*
    | Supported UI locales (A3). BM is the default.
    */
    'locales' => ['ms', 'en'],

    /*
    | MyKad place-of-birth codes accepted without a warning (B3.1).
    | UNVERIFIED-STAT (spec-00 open Q3): confirm against an official JPN list.
    | Unknown codes only raise a warning, never an error.
    */
    'ic_place_codes' => array_merge(
        range(1, 16),
        range(21, 59),
        range(60, 68),
        [71, 72],
        range(74, 79),
        range(82, 93),
        [98, 99],
    ),

    /*
    | Payroll (spec-04 §6): day of the month (1-28) on which RemindPayrollNotStarted warns when no
    | Proses Gaji exists yet for the current month; it also warns on the last day of the month.
    */
    'payroll' => [
        'not_started_day' => (int) env('PAYROLL_NOT_STARTED_DAY', 25),
    ],

    /*
    | Employee documents (B1.10).
    */
    'documents' => [
        'disk' => 'local',
        'max_kb' => 10240,
        'mimes' => ['pdf', 'jpg', 'jpeg', 'png', 'docx'],
    ],

];
