<?php

namespace App\Models;

use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Throttles `last_used_at` writes to at most once every 5 minutes (Slice 4).
 *
 * Sanctum touches `last_used_at` on every authenticated request by default,
 * turning every GET poll (/notifications, /queue-status, /enrollments) into a
 * database write and causing query-count assertions to vary depending on
 * whether two requests straddle a clock second.
 *
 * Deliberately NOT `final`: `Sanctum::actingAs()` builds its token with
 * `Mockery::mock(Sanctum::personalAccessTokenModel())`, and Mockery cannot
 * mock a final class, so every `actingAs` test would fail with "marked final".
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    private const TOUCH_INTERVAL_SECONDS = 300;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'token',
        'abilities',
        'expires_at',
        'acting_role',
        'acting_college',
    ];

    /**
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        if ($this->exists && $this->isOnlyLastUsedAtDirty()) {
            $reference = $this->getOriginal('last_used_at') ?? $this->created_at;

            if ($reference !== null) {
                $previous = $reference instanceof Carbon ? $reference : Carbon::parse((string) $reference);

                if ($previous->diffInSeconds(now()) < self::TOUCH_INTERVAL_SECONDS) {
                    $this->syncChanges();

                    return true;
                }
            }
        }

        return parent::save($options);
    }

    private function isOnlyLastUsedAtDirty(): bool
    {
        $dirty = array_keys($this->getDirty());

        return $dirty === ['last_used_at']
            || $dirty === ['last_used_at', 'updated_at']
            || $dirty === ['updated_at', 'last_used_at'];
    }
}
