<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$lines = file(__DIR__ . '/../../Subject And Prerequisuite/Professor_Department_List.md');

$deptMap = [
    // Coaches
    'Coach Jude Salonga' => 'CCS',
    'Coach Juvelyn Ticag' => 'CCS',
    'Coach Mariel Prado' => 'CCS',
    'Coach Rodolfo P. Loreto' => 'COA',
    'Diana Duyan' => 'COE',
    'Jonas Santos' => 'COE',
    'Rommel R. Bravo' => 'COE',
    'Ryan Romnick B. Sanchez' => 'COE',
    'Rafael Abubo' => 'COE',
    'Felix Bejosa' => 'COE',
    // Unidentified
    'Aaron U. Alonzo' => 'COA',
    'Aielene Cindel V. Gallen' => 'COA',
    'Victor Cabral' => 'CBAE',
    'Bianca Caceres' => 'CBAE',
    'Christian Gretchel A. Alariao' => 'CCS',
    'Cristy M. Tolentino' => 'CBAE',
    'Cristy Mirasol' => 'CBAE',
    'Daisy R. Cuadra' => 'COE',
    'April Olet' => 'COE',
    'Uma Danuco' => 'CBAE',
    'Lorenzo Tulod' => 'COA',
    'Emmanuel Delacruz' => 'CBAE',
    'Francheska Leila N. De Asis' => 'CCS',
    'Gabriel A. Palada' => 'CBAE',
    'Gezzle Marter' => 'COA',
    'Isabel M. Garchitorena' => 'CBAE',
    'Jan Henry N. Corrales' => 'COA',
    'Jay B. Evangelista' => 'CCS',
    'Mara Grace Salazar' => 'CBAE',
    'Martin Jabonita' => 'CBAE',
    'Marvie S. Parto' => 'COE',
    'Mary Grace E. Mendiola' => 'CBAE',
    'Mary Shane Carpila' => 'COA',
    'Yvonne Alonzo' => 'CBAE',
    'Yvonne Delgado' => 'COE',
    'Tomas Geronimo' => 'COE',
    'Jonas Salazar' => 'CCS',
    'Michael John E. Silverio' => 'COE',
    'Diana Elena Asis' => 'CCS',
    'Adrian Delarmente' => 'COE',
    'Yvonne Fernandez' => 'CCS',
    'Kara Olvis' => 'CCS',
    'Carlos Payusan' => 'COE',
    'Rafael Portiles' => 'COE',
    'Xavier Salazar' => 'CCS',
    'Rommel B. Antonio' => 'CBAE',
    'Ronald Travilla' => 'CBAE',
    'Samuel Cubacub' => 'CCS',
    'Vevencio M. Gabuya' => 'CBAE',
    'Vivian C. Acosta' => 'COA',
];

$nameFixes = [
    'Delos Santos' => 'Maria Delos Santos',
    'Coach Jude Salonga' => 'Jude Salonga',
    'Coach Juvelyn Ticag' => 'Juvelyn Ticag',
    'Coach Mariel Prado' => 'Mariel Prado',
    'Coach Rodolfo P. Loreto' => 'Rodolfo P. Loreto',
    'Adrian Mara Bautista' => 'Adrian M. Bautista',
    'Jonas Jonas Dela Cruz' => 'Jonas Dela Cruz',
];

function splitFacultyName(string $fullName): array {
    $tokens = preg_split('/\s+/', $fullName, -1, PREG_SPLIT_NO_EMPTY);
    $suffix = null;
    if (count($tokens) > 1 && preg_match('/^(Jr\.?|Sr\.?|II|III|IV|V)$/i', end($tokens))) {
        $suffix = array_pop($tokens);
    }
    if (count($tokens) === 1) {
        return ['first_name' => $tokens[0], 'middle_initial' => null, 'last_name' => $tokens[0], 'suffix' => $suffix];
    }
    $first = array_shift($tokens);
    $last = array_pop($tokens);
    $middle = $tokens !== [] ? mb_strtoupper(mb_substr($tokens[0], 0, 1)) : null;
    return ['first_name' => $first, 'middle_initial' => $middle, 'last_name' => $last, 'suffix' => $suffix];
}

$entries = [];
$usedEmails = [];

foreach ($lines as $line) {
    if (!preg_match('/^\|\s*(.*?)\s*\|\s*([^|\s]+@[^|\s]+)\s*\|\s*(.*?)\s*\|/u', $line, $matches)) {
        continue;
    }
    $origName = trim($matches[1]);
    $currentEmail = trim($matches[2]);
    $currentDept = trim($matches[3]);

    if ($origName === 'Professor Name') {
        continue;
    }

    $name = $nameFixes[$origName] ?? $origName;
    $dept = $deptMap[$origName] ?? $currentDept;

    $clean = trim((string) preg_replace('/^(?:Dr\.|Coach\s+|Atty\.)\s*/iu', '', $name));
    $cleanWithoutSuffix = trim((string) preg_replace('/\s+(?:Jr\.?|Sr\.?|II|III|IV|V)$/iu', '', $clean));
    $tokens = preg_split('/\s+/u', $cleanWithoutSuffix, -1, PREG_SPLIT_NO_EMPTY) ?: [$cleanWithoutSuffix];
    $first = strtolower((string) preg_replace('/[^a-z0-9]/iu', '', $tokens[0]));

    if (preg_match('/\b(dela\s+\w+|delos\s+\w+|de\s+\w+|san\s+\w+|[\w]+-[\w]+)$/iu', $cleanWithoutSuffix, $matchCompound)) {
        $last = strtolower((string) preg_replace('/[^a-z0-9]/iu', '', $matchCompound[0]));
    } else {
        $last = strtolower((string) preg_replace('/[^a-z0-9]/iu', '', (string) end($tokens)));
    }

    if ($origName === 'Henry Nieva Corrales' && $dept === 'COE') {
        $email = 'henry.corales.coe@grc.com';
    } else {
        $email = $first . '.' . $last . '.' . strtolower($dept) . '@grc.com';
    }

    if (isset($usedEmails[$email])) {
        $email = $first . '.' . $last . '2.' . strtolower($dept) . '@grc.com';
    }
    $usedEmails[$email] = true;

    $entries[] = [
        'orig_name' => $origName,
        'current_email' => $currentEmail,
        'name' => $name,
        'email' => $email,
        'dept' => $dept,
    ];
}

echo "Parsed " . count($entries) . " faculty entries.\n";

DB::beginTransaction();

$passwordHash = Hash::make('password');
$updatedCount = 0;

foreach ($entries as $index => $entry) {
    // Each entry corresponds to an ID in range 411 to 555
    $targetId = 411 + $index;
    $user = User::find($targetId);
    if (!$user) {
        $user = User::where('email', $entry['current_email'])->first();
    }
    if (!$user) {
        echo "Could not find user for index $index (target ID $targetId)\n";
        continue;
    }

    $nameParts = splitFacultyName($entry['name']);

    $user->update([
        'name' => $entry['name'],
        'first_name' => $nameParts['first_name'],
        'middle_initial' => $nameParts['middle_initial'],
        'last_name' => $nameParts['last_name'],
        'suffix' => $nameParts['suffix'],
        'email' => $entry['email'],
        'college' => strtolower($entry['dept']),
        'password' => $passwordHash,
        'status' => 'active',
        'role' => 'faculty',
    ]);
    $updatedCount++;
}

DB::commit();
echo "Successfully updated $updatedCount users in database.\n";

// Rewrite Professor_Department_List.md cleanly
$mdLines = [
    '# Professor and Department List',
    '',
    '| Professor Name | Email | Department |',
    '|---|---|---|',
];

foreach ($entries as $entry) {
    $mdLines[] = sprintf('| %s | %s | %s |', $entry['name'], $entry['email'], $entry['dept']);
}

$mdLines[] = '';
$mdLines[] = '**Total professors:** ' . count($entries);
$mdLines[] = '';

file_put_contents(__DIR__ . '/../../Subject And Prerequisuite/Professor_Department_List.md', implode(PHP_EOL, $mdLines));
echo "Successfully updated Subject And Prerequisuite/Professor_Department_List.md.\n";

