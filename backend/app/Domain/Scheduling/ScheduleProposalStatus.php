<?php

namespace App\Domain\Scheduling;

/**
 * The schedule lifecycle. PRD §4.1 originally specified:
 *
 *   draft → dean_approved → executive_approved → published → closed
 *
 * amended by the product owner to a direct Executive Director step:
 *
 *   draft → dean_approved → published → closed
 *
 * `ExecutiveApproved` stays defined so a pre-existing row already in that
 * state remains representable, but `ScheduleProposalTransitionRules` no
 * longer contains any transition that reaches or leaves it — it is dead for
 * every new proposal. A returned proposal moves back to `draft` with a
 * required reason.
 *
 * The section-viability threshold that gates publication is NOT encoded here:
 * PRD §17 lists it as an open decision, so `sections.viability_threshold`
 * stays nullable and unconstrained until GRC confirms the value.
 */
enum ScheduleProposalStatus: string
{
    case Draft = 'draft';
    case DeanApproved = 'dean_approved';
    case ExecutiveApproved = 'executive_approved';
    case Published = 'published';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::DeanApproved => 'Dean Approved',
            self::ExecutiveApproved => 'Executive Approved',
            self::Published => 'Published',
            self::Closed => 'Closed',
        };
    }

    /**
     * Publication exposes the schedule to students and professors
     * (PRD §5.1). Before that point a proposal is planning-role only.
     */
    public function isVisibleToLearners(): bool
    {
        return match ($this) {
            self::Published, self::Closed => true,
            self::Draft, self::DeanApproved, self::ExecutiveApproved => false,
        };
    }
}
