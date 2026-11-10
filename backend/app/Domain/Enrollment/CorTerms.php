<?php

namespace App\Domain\Enrollment;

final class CorTerms
{
    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            'I, the undersigned student, of legal age, hereby acknowledge, consent, and agree to be bound by the following terms and conditions governing the withdrawal of enrollment at Global Reciprocal Colleges.',
            '1. Period of Withdrawal. Withdrawal of enrollment may be validly effected only until the week immediately following the Midterm Examination.',
            '2. Unofficial Withdrawal. Any withdrawal not duly processed shall be deemed unofficial and shall not exempt the student from financial and academic obligations.',
            '3. Administrative Charges. Administrative charges shall apply irrespective of the date of enrollment, with Day 1 reckoned from the official opening of classes.',
            '4. Withdrawal within Two (2) Weeks. An administrative charge equivalent to twenty percent (20%) of the total tuition and miscellaneous fees shall be imposed.',
            '5. Withdrawal within Third (3rd) to Fourth (4th) Week. An administrative charge equivalent to fifty percent (50%) of the total tuition and miscellaneous fees shall be imposed.',
            '6. Withdrawal on Fifth (5th) Week or Later. Any withdrawal duly processed on the fifth (5th) week or thereafter shall render the student liable for the full tuition and miscellaneous fees. A grade remark of W (Withdrawn) shall be entered.',
            '7. Scholars. A scholar who withdraws enrollment may be liable for tuition and miscellaneous fees under the applicable scholarship provisions.',
            '8. Return of Admission Requirements. Admission requirements shall be returned when official withdrawal is duly accomplished within thirty (30) days, subject to institutional records requirements.',
        ];
    }
}
