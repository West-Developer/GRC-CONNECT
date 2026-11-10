<?php

/*
|--------------------------------------------------------------------------
| CHED / NSTP report header values (ADR 0049)
|--------------------------------------------------------------------------
|
| The institution details printed on the CHED Enrollment List and the NSTP forms. They are the
| values on the template CHED NCR sent (template_2024.xlsx), kept here so a change of address or
| name is a configuration change, not a code change.
*/

return [
    'school_name' => env('CHED_SCHOOL_NAME', 'Global Reciprocal Colleges'),
    'school_address' => env('CHED_SCHOOL_ADDRESS', '454 GRC Building, 1400 Rizal Ave Ext, East Grace Park, Caloocan, Metro Manila'),
    // Form 2-A's "Private/Public" column.
    'classification' => env('CHED_CLASSIFICATION', 'Private'),
];
