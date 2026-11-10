<?php

$sourceCsv = __DIR__ . '/curriculum-2024-2029-placements.csv';
$csv2018 = __DIR__ . '/curriculum-2018-2023-placements.csv';
$csv2012 = __DIR__ . '/curriculum-2012-2017-placements.csv';

$lines = file($sourceCsv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$header = str_getcsv(array_shift($lines));

$rows2018 = [];
$rows2012 = [];

// Replacement mappings for 2018-2023
$map2018 = [
    'PATHFIT 1' => 'PE 1',
    'PATHFIT 2' => 'PE 2',
    'PATHFIT 3' => 'PE 3',
    'PATHFIT 4' => 'PE 4',
    'PATHFIT1'  => 'PE 1',
    'PATHFIT2'  => 'PE 2',
    'PATHFIT3'  => 'PE 3',
    'PATHFIT4'  => 'PE 4',
    'IAS2'      => 'IT-ELEC1',
    'IAS2L'     => 'IT-ELEC2',
    'AVE'       => 'IT-ELEC3',
    'AVEL'      => 'WEBDES',
    'QM-TQM'    => 'BUS-ORG',
];

// Replacement mappings for 2012-2017 (Pre-K12)
$map2012 = [
    'PURPCOMM'  => 'ENG 1',
    'KOMFIL'    => 'FIL 1',
    'FILDIS'    => 'FIL 2',
    'MATHWRLD'  => 'MATH 1',
    'MATHINV'   => 'MATH 2',
    'UNSELF'    => 'PSYCH 1',
    'ETHICS'    => 'POLSCI 1',
    'CONWRLD'   => 'SOCIO 1',
    'ARTAPP'    => 'HUM 1',
    'STS'       => 'NATSCI 2',
    'READPHIL'  => 'HIST 1',
    'PHILHIS'   => 'HIST 1',
    'PATHFIT 1' => 'PE 1',
    'PATHFIT 2' => 'PE 2',
    'PATHFIT 3' => 'PE 3',
    'PATHFIT 4' => 'PE 4',
    'PATHFIT1'  => 'PE 1',
    'PATHFIT2'  => 'PE 2',
    'PATHFIT3'  => 'PE 3',
    'PATHFIT4'  => 'PE 4',
    // CCS Pre-K12 Majors
    'IAS1'      => 'NET 1',
    'IAS1L'     => 'NET 2',
    'IAS2'      => 'SOFENG',
    'IAS2L'     => 'SYSANA',
    'CC-PROG1'  => 'PROG 1',
    'CC-PROG1L' => 'PROG 1',
    'CC-PROG2'  => 'PROG 2',
    'CC-PROG2L' => 'PROG 2',
    'CC-DATASTRUCT' => 'DATASTRUCT',
    'CC-DATASTRUCTL' => 'DATASTRUCT',
    'CC-IM'     => 'DATABASE 1',
    'CC-IML'    => 'DATABASE 1',
    'CC-APPDET' => 'DATABASE 2',
    'CC-APPDETL'=> 'DATABASE 2',
    'CAPSTONE1' => 'CAPSTONE 1',
    'CAPSTONE2' => 'CAPSTONE 2',
    'PRACTICUM' => 'PRACTICUM',
    'AVE'       => 'IT-ELEC1',
    'AVEL'      => 'IT-ELEC2',
    // CBAE Pre-K12 Majors
    'FUNDACC'   => 'BASIC ACCTG',
    'MANACC'    => 'BASIC ACCTG',
    'BUSLAW'    => 'BUS-ORG',
    'FINMAN'    => 'FIN 1',
    'MKTG'      => 'MKTG 1',
    'GGSR'      => 'BUS-ETH',
    'BP-PRE'    => 'FEASIB',
    // COA Pre-K12 Majors
    'FAR1'      => 'ACTG 1',
    'FAR2'      => 'ACTG 2',
    'FAR3'      => 'ACTG 3',
    'CA'        => 'COST-ACC',
    'AT'        => 'AUD-THEO',
    'IA1'       => 'ACTG 1',
    'IA2'       => 'ACTG 2',
    'IA3'       => 'ACTG 3',
];

foreach ($lines as $line) {
    $row = array_combine($header, str_getcsv($line));
    
    // 2018-2023 version
    $code2018 = $map2018[$row['subject_code']] ?? $row['subject_code'];
    $rows2018[] = [
        $row['college'],
        $row['program_code'],
        $row['year_level'],
        $row['semester'],
        $code2018,
    ];

    // 2012-2017 version
    $code2012 = $map2012[$row['subject_code']] ?? $row['subject_code'];
    $rows2012[] = [
        $row['college'],
        $row['program_code'],
        $row['year_level'],
        $row['semester'],
        $code2012,
    ];
}

// Write 2018-2023 CSV
$f2018 = fopen($csv2018, 'w');
fputcsv($f2018, $header);
foreach ($rows2018 as $r) {
    fputcsv($f2018, $r);
}
fclose($f2018);

// Write 2012-2017 CSV
$f2012 = fopen($csv2012, 'w');
fputcsv($f2012, $header);
foreach ($rows2012 as $r) {
    fputcsv($f2012, $r);
}
fclose($f2012);

echo "Successfully generated 2018-2023 (" . count($rows2018) . " rows) and 2012-2017 (" . count($rows2012) . " rows) placement CSVs.\n";

