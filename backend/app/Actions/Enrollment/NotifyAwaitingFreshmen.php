<?php

namespace App\Actions\Enrollment;

use App\Domain\Enrollment\EnrollmentCategory;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Identity\StudentType;
use App\Domain\Notifications\NotificationType;
use App\Domain\Scheduling\SectionStatus;
use App\Mail\FreshmanSectionAvailableMail;
use App\Models\Notification;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Support\Http\FrontendBaseUrl;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Tells the freshmen who paid while every section was full that one has opened
 * (ADR 0043). Called when sections are published and when a published section gains
 * seats. A freshman is told when a block of their own program and year (the section
 * plan's curriculum and year level, the same key the block pool uses) is published
 * and has an open seat in every subject, and only if they have paid and have no
 * enrollment in progress this term. Once per freshman per call, however many
 * sections opened together.
 *
 * The in-app notification is the record; the email is a courtesy that never fails
 * the section change that triggered it (a delivery error is reported and swallowed).
 */
final class NotifyAwaitingFreshmen
{
    /**
     * @param  list<int>  $sectionIds
     * @return int how many freshmen were told
     */
    public function forSections(array $sectionIds): int
    {
        try {
            return $this->notifyFor($sectionIds);
        } catch (Throwable $exception) {
            // A notice is a courtesy to people waiting for a section. Whatever goes wrong while finding
            // or telling them must never stop the section change, or the schedule publication, that
            // triggered it — so it is reported (it reaches the log) and the change goes on.
            report($exception);

            return 0;
        }
    }

    /**
     * @param  list<int>  $sectionIds
     */
    private function notifyFor(array $sectionIds): int
    {
        if ($sectionIds === []) {
            return 0;
        }

        $sections = Section::query()
            ->whereIn('id', $sectionIds)
            ->where('status', SectionStatus::Published)
            ->where('is_block_exclusive', true)
            ->whereNotNull('section_plan_id')
            ->with('sectionPlan')
            ->get();

        $told = [];

        foreach ($sections->groupBy('academic_term_id') as $termId => $termSections) {
            foreach ($termSections->groupBy(fn (Section $section): string => $section->sectionPlan?->curriculum_id.'|'.$section->sectionPlan?->year_level) as $group) {
                $plan = $group->first()?->sectionPlan;

                if ($plan === null) {
                    continue;
                }

                $codes = $this->openBlockCodes((int) $termId, $plan->curriculum_id, $plan->year_level, array_values($group->map(fn (Section $section): string => $section->section_code)->unique()->all()));

                if ($codes === []) {
                    continue;
                }

                foreach ($this->awaitingFreshmen((int) $termId, $plan->curriculum_id, $plan->year_level) as $freshman) {
                    if (isset($told[$freshman->id])) {
                        continue;
                    }

                    $told[$freshman->id] = true;
                    $this->tell($freshman, $codes);
                }
            }
        }

        return count($told);
    }

    /**
     * The given block codes that can take a student right now: every subject of the block
     * has a seat (the same "block minimum" rule as `BuildEnrollmentBlockPool`).
     *
     * @param  list<string>  $blockCodes
     * @return list<string>
     */
    private function openBlockCodes(int $termId, int $curriculumId, int $yearLevel, array $blockCodes): array
    {
        $open = [];

        foreach ($blockCodes as $code) {
            $sections = Section::query()
                ->where('academic_term_id', $termId)
                ->where('section_code', $code)
                ->where('status', SectionStatus::Published)
                ->where('is_block_exclusive', true)
                ->whereHas('sectionPlan', fn ($plan) => $plan->where('curriculum_id', $curriculumId)->where('year_level', $yearLevel))
                ->get();

            if ($sections->isNotEmpty() && $sections->every(fn (Section $section): bool => $section->remainingSeats() > 0)) {
                $open[] = $code;
            }
        }

        sort($open, SORT_NATURAL);

        return $open;
    }

    /**
     * @return iterable<StudentProfile>
     */
    private function awaitingFreshmen(int $termId, int $curriculumId, int $yearLevel): iterable
    {
        return StudentProfile::query()
            ->where('student_type', StudentType::Freshman->value)
            ->whereNotNull('freshman_payment_confirmed_at')
            // An irregular freshman chooses subjects one by one, so a new block is not what they wait for.
            ->where(fn ($query) => $query
                ->whereNull('enrollment_category')
                ->orWhere('enrollment_category', '!=', EnrollmentCategory::Irregular->value))
            ->where('curriculum_id', $curriculumId)
            ->where('year_level', $yearLevel)
            ->whereDoesntHave('enrollments', fn ($enrollments) => $enrollments
                ->where('academic_term_id', $termId)
                ->whereNotIn('status', [EnrollmentStatus::Rejected->value, EnrollmentStatus::Cancelled->value, EnrollmentStatus::Withdrawn->value]))
            ->with('user')
            ->get();
    }

    /**
     * @param  list<string>  $blockCodes
     */
    private function tell(StudentProfile $freshman, array $blockCodes): void
    {
        $label = implode(', ', $blockCodes);

        Notification::create([
            'user_id' => $freshman->user_id,
            'type' => NotificationType::FreshmanSectionAvailable,
            'message' => "A section is now open for your program and year ({$label}). Open Enrollment to choose it and submit; your earlier payment is applied once Registrar Staff approve.",
        ]);

        try {
            Mail::to($freshman->user->email)->send(new FreshmanSectionAvailableMail(
                $freshman->user->name,
                $label,
                FrontendBaseUrl::resolve().'/portal/enrollment',
            ));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
