<?php

namespace App\Http\Requests\Api\V1\AcademicTerm;

use App\Domain\Enrollment\EnrollmentAudience;
use App\Domain\Enrollment\EnrollmentPlatform;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateEnrollmentScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enrollment_opens_at' => ['required', 'date'],
            'enrollment_closes_at' => ['required', 'date', 'after:enrollment_opens_at'],
            'add_drop_opens_at' => ['nullable', 'date'],
            'add_drop_closes_at' => ['nullable', 'date'],
            // One platform for everyone enrolling this term (printed on the COR).
            'enrollment_platform' => ['sometimes', 'nullable', Rule::enum(EnrollmentPlatform::class)],
            'windows' => ['required', 'array', 'size:'.count(EnrollmentAudience::cases())],
            'windows.*.audience' => ['required', 'string', Rule::enum(EnrollmentAudience::class), 'distinct'],
            'windows.*.opens_at' => ['nullable', 'date'],
            'windows.*.closes_at' => ['nullable', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $termOpens = $this->date('enrollment_opens_at');
            $termCloses = $this->date('enrollment_closes_at');
            $addDropOpens = $this->date('add_drop_opens_at');
            $addDropCloses = $this->date('add_drop_closes_at');
            $windows = $this->input('windows');

            if ($addDropOpens !== null && $addDropCloses !== null && $addDropCloses->lt($addDropOpens)) {
                $validator->errors()->add(
                    'add_drop_closes_at',
                    'The add/drop close date must be after its open date.',
                );
            }

            if ($termOpens === null || $termCloses === null || ! is_array($windows)) {
                return;
            }

            foreach ($windows as $index => $window) {
                if (! is_array($window)) {
                    continue;
                }

                $opens = $this->date("windows.{$index}.opens_at");
                $closes = $this->date("windows.{$index}.closes_at");

                if ($opens !== null && $closes !== null && $closes->lt($opens)) {
                    $validator->errors()->add(
                        "windows.{$index}.closes_at",
                        'The close date must be after its open date.',
                    );
                }

                if ($opens !== null && $opens->lt($termOpens)) {
                    $validator->errors()->add(
                        "windows.{$index}.opens_at",
                        'The open date cannot be before the term-wide enrollment opening.',
                    );
                }

                if ($closes !== null && $closes->gt($termCloses)) {
                    $validator->errors()->add(
                        "windows.{$index}.closes_at",
                        'The close date cannot be after the term-wide enrollment closing.',
                    );
                }
            }
        });
    }

    /**
     * @return list<array{audience: string, opens_at: ?string, closes_at: ?string}>
     */
    public function windows(): array
    {
        /** @var list<array<string, mixed>> $windows */
        $windows = $this->validated('windows', []);

        return array_map(static function (array $window): array {
            $opensAt = $window['opens_at'] ?? null;
            $closesAt = $window['closes_at'] ?? null;

            return [
                'audience' => (string) $window['audience'],
                'opens_at' => is_string($opensAt) ? $opensAt : null,
                'closes_at' => is_string($closesAt) ? $closesAt : null,
            ];
        }, $windows);
    }
}
