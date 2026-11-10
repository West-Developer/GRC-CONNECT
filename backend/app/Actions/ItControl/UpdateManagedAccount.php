<?php

namespace App\Actions\ItControl;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\PersonName;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

/**
 * IT Control edits a Student or Faculty account's name parts and email (ADR 0054). The composed `name` is
 * rewritten from the parts with the middle initial the account already has; the middle name is never read or
 * written here. The audit row names the fields that changed, never their values.
 */
final class UpdateManagedAccount
{
    private const FIELDS = ['first_name', 'last_name', 'suffix', 'email'];

    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    /** @param array<string, mixed> $data */
    public function execute(User $target, array $data, User $actor, AuditRequestContext $context): User
    {
        return DB::transaction(function () use ($target, $data, $actor, $context): User {
            $locked = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

            $attributes = [];
            foreach (['first_name', 'last_name'] as $part) {
                if (array_key_exists($part, $data)) {
                    $attributes[$part] = PersonName::normalizeNamePart((string) $data[$part]);
                }
            }
            if (array_key_exists('suffix', $data)) {
                $attributes['suffix'] = PersonName::normalizeSuffix($data['suffix'] === null ? null : (string) $data['suffix']);
            }
            if (array_key_exists('email', $data)) {
                $attributes['email'] = trim((string) $data['email']);
            }

            if (array_intersect(array_keys($attributes), ['first_name', 'last_name', 'suffix']) !== []) {
                $attributes['name'] = PersonName::compose(
                    (string) ($attributes['first_name'] ?? $locked->first_name ?? ''),
                    $locked->middle_initial,
                    (string) ($attributes['last_name'] ?? $locked->last_name ?? ''),
                    array_key_exists('suffix', $attributes) ? $attributes['suffix'] : $locked->suffix,
                );
            }

            $changed = array_values(array_intersect(array_keys($data), self::FIELDS));
            $locked->fill($attributes)->save();

            $this->auditRecorder->record(
                $actor,
                AuditAction::USER_ACCOUNT_UPDATED,
                AuditableType::USER_ACCOUNT,
                $locked->id,
                ['field_count' => count($changed)],
                ['changed_fields' => $changed],
                null,
                $context,
            );

            return $locked->refresh();
        });
    }
}
