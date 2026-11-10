<?php

namespace Database\Seeders;

use App\Models\FeeSchedule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class FeeScheduleSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            if (FeeSchedule::count() > 0) {
                return;
            }

            // 1. Tuition per unit
            FeeSchedule::create([
                'category' => 'tuition',
                'label' => 'Tuition Rate Per Unit',
                'amount' => '200.00',
                'program_codes' => null,
                'is_active' => true,
                'sort_order' => 1,
            ]);

            // 2. Miscellaneous / Other Fees
            $miscellaneousFees = [
                ['label' => 'Registration', 'amount' => '200.00', 'program_codes' => null],
                ['label' => 'Guidance and Counseling and Student Affair', 'amount' => '200.00', 'program_codes' => null],
                ['label' => 'Medical and Dental', 'amount' => '350.00', 'program_codes' => null],
                ['label' => 'Student Information System Fee', 'amount' => '200.00', 'program_codes' => null],
                ['label' => 'Energy/Water/Communication Fees', 'amount' => '1000.00', 'program_codes' => null],
                ['label' => 'Community Extension Fee', 'amount' => '200.00', 'program_codes' => null],
                ['label' => 'Research & Publication', 'amount' => '200.00', 'program_codes' => null],
                ['label' => 'Computer Lab Fee 1 (All Students)', 'amount' => '500.00', 'program_codes' => null],
                ['label' => 'Student I.D.', 'amount' => '100.00', 'program_codes' => null],
                ['label' => 'Development Fee', 'amount' => '400.00', 'program_codes' => null],
                ['label' => 'Postal', 'amount' => '150.00', 'program_codes' => null],
                ['label' => 'Computer Lab Fee 2 (BSIT)', 'amount' => '500.00', 'program_codes' => ['BSIT']],
                ['label' => 'Sports Development Fee', 'amount' => '0.00', 'program_codes' => null],
                ['label' => 'Hand Book', 'amount' => '0.00', 'program_codes' => null],
                ['label' => 'Library Fee', 'amount' => '0.00', 'program_codes' => null],
            ];

            $order = 2;
            foreach ($miscellaneousFees as $fee) {
                FeeSchedule::create([
                    'category' => 'miscellaneous',
                    'label' => $fee['label'],
                    'amount' => $fee['amount'],
                    'program_codes' => $fee['program_codes'],
                    'is_active' => true,
                    'sort_order' => $order++,
                ]);
            }
        });
    }
}
