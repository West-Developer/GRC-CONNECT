<?php

namespace App\Domain\Scheduling;

use App\Domain\Identity\UserRole;
use App\Models\Section;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * A published section is final for the Program Head (stakeholder Doc 14,
 * ADR 0032). The only thing a Program Head may still change on it directly is
 * the professor — that needs no approval, but the Registrar Head is told
 * (see `UpdateSection`). Every other field goes through a change request.
 *
 * Enforced on the API, not only in the schedule screen.
 */
final class PublishedSectionLock
{
    /**
     * Fields a change request may carry.
     *
     * @var list<string>
     */
    public const CHANGEABLE_FIELDS = [
        'schedule_days',
        'starts_at_time',
        'ends_at_time',
        'room',
        'modality',
        'capacity',
        'viability_threshold',
    ];

    /** @var list<string> Fields a Program Head may still edit on a published section. */
    private const FREE_FIELDS = ['professor_id', 'override_reason'];

    public function __construct(private readonly CanonicalScheduleDays $canonicalScheduleDays) {}

    public function locks(User $actor, Section $section): bool
    {
        return $actor->role === UserRole::ProgramChair && $section->status === SectionStatus::Published;
    }

    /**
     * @param  array<string, mixed>  $proposed  the validated section update payload
     *
     * @throws AuthorizationException when the payload changes anything but the professor
     */
    public function assertAllowed(User $actor, Section $section, array $proposed): void
    {
        if (! $this->locks($actor, $section)) {
            return;
        }

        foreach ($proposed as $field => $value) {
            if (in_array($field, self::FREE_FIELDS, true)) {
                continue;
            }

            if ($this->normalized($field, $value) !== $this->normalized($field, $this->current($section, $field))) {
                throw new AuthorizationException(
                    'This section is published, so its schedule can only be changed through a change request that the Registrar Head approves. Only the professor can still be reassigned directly.',
                );
            }
        }
    }

    /** The section's stored value for a request field, in request shape. */
    public function current(Section $section, string $field): mixed
    {
        return match ($field) {
            'modality' => $section->modality?->value,
            'status' => $section->status->value,
            default => $section->{$field},
        };
    }

    public function normalized(string $field, mixed $value): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        if ($field === 'schedule_days') {
            return $this->canonicalScheduleDays->normalize((string) $value);
        }

        return trim((string) $value);
    }
}
