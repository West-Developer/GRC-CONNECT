<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Models\User;
use App\Support\Privacy\PrivacyNotice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The person agrees to the version they were shown. Naming it (rather than "the current one") is what makes the
 * record true: if the notice changed while the page was open, the agreement is refused and they read it again.
 */
final class AcceptPrivacyNoticeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && app(PrivacyNotice::class)->appliesTo($user);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'notice_version' => ['required', 'string', Rule::in([app(PrivacyNotice::class)->version()])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'notice_version.in' => 'The Data Privacy Notice was updated. Reload the page and read the current version.',
        ];
    }
}
