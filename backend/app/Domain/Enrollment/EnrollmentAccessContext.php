<?php

namespace App\Domain\Enrollment;

/**
 * Which enrollment windows are open right now, from one student's point of
 * view.
 *
 * Block-section access is a question about *time*, not just about the
 * viewer: a block seat is reserved while its year level is enrolling, and
 * opens to irregular students once the irregular window starts. Answering
 * that needs more than "is my own window open", so the whole picture is
 * resolved once (`BuildEnrollmentAccessContext`) and passed down as this
 * value object rather than re-queried per section.
 */
final readonly class EnrollmentAccessContext
{
    /**
     * @param  list<EnrollmentAudience>  $openAudiences  every audience whose window is currently open
     */
    public function __construct(
        public EnrollmentAudience $viewerAudience,
        public bool $viewerWindowIsOpen,
        public bool $irregularWindowIsOpen,
        public array $openAudiences,
    ) {}
}
