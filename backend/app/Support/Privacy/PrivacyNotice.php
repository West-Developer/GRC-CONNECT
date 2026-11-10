<?php

namespace App\Support\Privacy;

use App\Models\PrivacyNoticeAcceptance;
use App\Models\User;
use Illuminate\Validation\Rule;

/**
 * The Data Privacy Notice as the server sees it (ADR 0055): which version is current, who is asked to agree to
 * it, and whether a given person already has. The wording is in the frontend; only the version and the contact
 * are decided here (`config/privacy.php`).
 */
final class PrivacyNotice
{
    public function version(): string
    {
        return (string) config('privacy.notice_version');
    }

    public function effectiveDate(): string
    {
        return (string) config('privacy.effective_date');
    }

    public function controller(): string
    {
        return (string) config('privacy.controller');
    }

    /**
     * @return array{name: ?string, email: string, phone: ?string}
     */
    public function contact(): array
    {
        $name = config('privacy.contact.name');
        $phone = config('privacy.contact.phone');

        return [
            'name' => is_string($name) && trim($name) !== '' ? trim($name) : null,
            'email' => (string) config('privacy.contact.email'),
            'phone' => is_string($phone) && trim($phone) !== '' ? trim($phone) : null,
        ];
    }

    /**
     * The two request fields that carry an agreement: that it was given, and to which version. The version is
     * named so the record states the wording the person actually saw, and so a notice that changed while a page
     * was open is read again instead of agreed to unseen. `$required` is for a request that cannot proceed
     * without it (Admission saving an applicant); optional ones (account setup) record it only when it is sent.
     *
     * @return array<string, list<mixed>>
     */
    public function agreementRules(bool $required): array
    {
        return [
            'privacy_notice_acknowledged' => [$required ? 'required' : 'sometimes', 'accepted'],
            'privacy_notice_version' => [
                $required ? 'required' : 'required_with:privacy_notice_acknowledged',
                'string',
                Rule::in([$this->version()]),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function agreementMessages(string $who): array
    {
        return [
            'privacy_notice_acknowledged.required' => "Confirm that {$who} read the Data Privacy Notice and agreed to it.",
            'privacy_notice_acknowledged.accepted' => "Confirm that {$who} read the Data Privacy Notice and agreed to it.",
            'privacy_notice_version.in' => 'The Data Privacy Notice was updated. Reload the page and read the current version.',
        ];
    }

    /** Every person is asked; the queue kiosk is a shared device, not a person. */
    public function appliesTo(User $user): bool
    {
        return ! $user->role->isDevice();
    }

    public function requiresAcceptance(User $user): bool
    {
        if (! $this->appliesTo($user)) {
            return false;
        }

        return ! PrivacyNoticeAcceptance::query()
            ->where('user_id', $user->id)
            ->where('method', PrivacyNoticeAcceptance::METHOD_SELF)
            ->where('notice_version', $this->version())
            ->exists();
    }

    /** The newest version the person agreed to themself (not one Admission recorded for them). */
    public function latestAcceptance(User $user): ?PrivacyNoticeAcceptance
    {
        return PrivacyNoticeAcceptance::query()
            ->where('user_id', $user->id)
            ->where('method', PrivacyNoticeAcceptance::METHOD_SELF)
            ->orderByDesc('accepted_at')
            ->orderByDesc('id')
            ->first();
    }

    public function acceptedVersion(User $user): ?string
    {
        return $this->latestAcceptance($user)?->notice_version;
    }
}
