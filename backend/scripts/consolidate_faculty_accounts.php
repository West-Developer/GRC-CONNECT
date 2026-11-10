<?php
require 'backend/vendor/autoload.php';
$app = require_once 'backend/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Section;
use App\Models\AcademicGrade;
use Illuminate\Support\Facades\DB;

// Load all standardized faculty accounts (@grc.com)
$standardFaculty = User::where('role', 'faculty')
    ->where('email', 'like', '%@grc.com')
    ->get();

echo "Standardized faculty count: " . $standardFaculty->count() . "\n";

// Helper to normalize names for comparison
function normName(string $name): string {
    // Remove titles like Coach, Dr, etc.
    $n = preg_replace('/^(coach|prof|dr|mr|ms|mrs)\.?\s+/i', '', trim($name));
    // Remove middle initials like 'N.', 'M.', 'A.', etc.
    $n = preg_replace('/\s+[a-z]\.?\s+/i', ' ', $n);
    // Remove extra spaces
    $n = preg_replace('/\s+/', ' ', $n);
    // Lowercase
    return strtolower(trim($n));
}

// Build lookup maps for standardized faculty
// 1. Exact normalized full name -> user
$nameMap = [];
// 2. First + Last name -> user
$firstLastMap = [];
// 3. User ID -> user
foreach ($standardFaculty as $sf) {
    $n = normName($sf->name);
    $nameMap[$n] = $sf;
    
    $fl = strtolower(trim($sf->first_name . ' ' . $sf->last_name));
    $firstLastMap[$fl] = $sf;
}

// Old faculty accounts (@grc.test)
$oldFaculty = User::where('role', 'faculty')
    ->where('email', 'like', '%@grc.test')
    ->get();

echo "Old faculty count: " . $oldFaculty->count() . "\n";

$mappedCount = 0;
$unmappedCount = 0;
$sectionsUpdated = 0;
$gradesUpdated = 0;

DB::beginTransaction();

try {
    foreach ($oldFaculty as $old) {
        $target = null;
        $oldNorm = normName($old->name);
        $oldFl = strtolower(trim($old->first_name . ' ' . $old->last_name));
        
        if (isset($nameMap[$oldNorm])) {
            $target = $nameMap[$oldNorm];
        } elseif (isset($firstLastMap[$oldFl])) {
            $target = $firstLastMap[$oldFl];
        } else {
            // Check if standard faculty has name containing old first and last
            foreach ($standardFaculty as $sf) {
                if ($old->last_name && strcasecmp($sf->last_name, $old->last_name) === 0) {
                    if ($old->first_name && stripos($sf->first_name, $old->first_name) !== false) {
                        $target = $sf;
                        break;
                    }
                }
            }
        }
        
        if ($target) {
            $mappedCount++;
            
            // Remap sections
            $sCount = Section::where('professor_id', $old->id)->update(['professor_id' => $target->id]);
            $sectionsUpdated += $sCount;
            
            // Remap academic grades
            $gCount = AcademicGrade::where('encoded_by', $old->id)->update(['encoded_by' => $target->id]);
            $gradesUpdated += $gCount;
            
            // Also copy password from standard user or ensure unified password
            // echo "Remapped Old ID {$old->id} ({$old->name}) -> Standard ID {$target->id} ({$target->name}) [Sections: {$sCount}, Grades: {$gCount}]\n";
        } else {
            $unmappedCount++;
            $sCount = Section::where('professor_id', $old->id)->count();
            if ($sCount > 0) {
                echo "UNMAPPED with sections: ID {$old->id} ({$old->name} - {$old->email}) has {$sCount} sections\n";
            }
        }
    }
    
    DB::commit();
    echo "\nSUMMARY:\n";
    echo "Mapped old faculty: {$mappedCount}\n";
    echo "Unmapped old faculty: {$unmappedCount}\n";
    echo "Sections updated to standardized faculty: {$sectionsUpdated}\n";
    echo "Grades updated to standardized faculty: {$gradesUpdated}\n";
} catch (\Exception $e) {
    DB::rollBack();
    echo "Error: " . $e->getMessage() . "\n";
}
