<?php

/*
 * The Data Privacy Notice (RA 10173, the Data Privacy Act of 2012) — ADR 0055.
 *
 * The notice text itself lives in the frontend (`features/lib/privacy`); this file holds the two things the
 * server has to be the authority for: WHICH version of the notice is current (a person who agreed to an older
 * version is asked again), and who a person writes to about their data.
 *
 * Change `notice_version` (and `effective_date`) whenever the wording or the purposes change in a way that
 * people should read again. Nothing else decides who is asked.
 */
return [
    'notice_version' => env('PRIVACY_NOTICE_VERSION', '2026-10-18'),

    'effective_date' => env('PRIVACY_NOTICE_EFFECTIVE_DATE', '2026-10-18'),

    'controller' => env('PRIVACY_CONTROLLER_NAME', 'Global Reciprocal Colleges'),

    /*
     * Who to write to about personal data. No Data Protection Officer is named here because none is published
     * (grc.edu.ph/privacy-policy lists only info@grc.edu.ph): set PRIVACY_DPO_NAME / PRIVACY_DPO_EMAIL /
     * PRIVACY_DPO_PHONE on the server once the school names one. Until then the page shows the school's published
     * contact address and no officer's name.
     */
    'contact' => [
        'name' => env('PRIVACY_DPO_NAME'),
        'email' => env('PRIVACY_DPO_EMAIL', 'info@grc.edu.ph'),
        'phone' => env('PRIVACY_DPO_PHONE'),
    ],
];
