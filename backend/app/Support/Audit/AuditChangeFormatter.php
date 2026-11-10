<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Turns an audit row's raw before/after snapshots into a list of readable
 * field changes for the Registrar Head's audit screen (stakeholder Doc 14).
 * Only ever reads values the recorder already accepted, and keeps the
 * screen's earlier promise not to show personal identifiers: any field whose
 * key looks like a name, email, password, token, or secret is hidden.
 */
final class AuditChangeFormatter
{
    /** @var array<string, string> */
    private const FIELD_LABELS = [
        'academic_term_id' => 'Academic term',
        'subject_id' => 'Subject',
        'section_code' => 'Section',
        'professor_id' => 'Professor',
        'schedule_days' => 'Days',
        'starts_at_time' => 'Start time',
        'ends_at_time' => 'End time',
        'room' => 'Room',
        'modality' => 'Modality',
        'capacity' => 'Seats',
        'capacity_source' => 'Seat source',
        'viability_threshold' => 'Viability threshold',
        'enrolled_count' => 'Enrolled students',
        'status' => 'Status',
        'reason' => 'Reason',
        'max_units' => 'Maximum units',
        'requirement' => 'Requirement',
        'is_submitted' => 'Submitted',
        'category' => 'Category',
    ];

    /** Keys whose numeric value is a user id worth showing as a name. */
    private const USER_ID_FIELDS = ['professor_id', 'decided_by', 'granted_by', 'requested_by'];

    private const HIDDEN = 'Hidden';

    /**
     * The user ids named by the given rows, so the caller can look their names
     * up once for the whole page.
     *
     * @param  iterable<AuditLog>  $logs
     * @return list<int>
     */
    public static function userIdsIn(iterable $logs): array
    {
        $ids = [];
        foreach ($logs as $log) {
            foreach ([$log->before_values, $log->after_values] as $snapshot) {
                foreach (self::USER_ID_FIELDS as $field) {
                    $value = $snapshot[$field] ?? null;
                    if (is_int($value)) {
                        $ids[$value] = true;
                    }
                }
            }
        }

        return array_keys($ids);
    }

    /**
     * Names for the user ids named by these rows, in one query.
     *
     * @param  iterable<AuditLog>  $logs
     * @return array<int, string>
     */
    public static function userNamesFor(iterable $logs): array
    {
        $ids = self::userIdsIn($logs);

        if ($ids === []) {
            return [];
        }

        /** @var array<int, string> */
        return User::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * One entry per field in either snapshot; `changed` is false for a field
     * that kept its value, so the screen can show it muted.
     *
     * @param  ?array<string, mixed>  $before
     * @param  ?array<string, mixed>  $after
     * @param  array<int, string>  $userNames
     * @return list<array{field: string, label: string, old: string|int|float|bool|null, new: string|int|float|bool|null, changed: bool}>
     */
    public static function changes(?array $before, ?array $after, array $userNames = []): array
    {
        $before ??= [];
        $after ??= [];
        $fields = array_values(array_unique([...array_keys($before), ...array_keys($after)]));

        $entries = [];
        foreach ($fields as $field) {
            $field = (string) $field;
            $old = self::present($field, $before[$field] ?? null, $userNames);
            $new = self::present($field, $after[$field] ?? null, $userNames);

            $entries[] = [
                'field' => $field,
                'label' => self::FIELD_LABELS[$field] ?? Str::headline($field),
                'old' => $old,
                'new' => $new,
                'changed' => $old !== $new,
            ];
        }

        return $entries;
    }

    /**
     * @param  array<int, string>  $userNames
     */
    private static function present(string $field, mixed $value, array $userNames): string|int|float|bool|null
    {
        if (preg_match('/email|name|password|token|secret/iu', $field) === 1) {
            return $value === null ? null : self::HIDDEN;
        }

        if ($value === null || is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            if (is_int($value) && in_array($field, self::USER_ID_FIELDS, true)) {
                return $userNames[$value] ?? "User #{$value}";
            }

            return $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? null : $encoded;
    }
}
