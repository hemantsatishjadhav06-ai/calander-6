<?php

declare(strict_types=1);

namespace App\Enums;

enum EngagementStatus: string
{
    case Ok = 'ok';
    case Unsupported = 'unsupported';
    case RateLimited = 'rate_limited';

    /**
     * The platform accepted the credentials but the account has no API quota
     * left (X answers 402 "credits depleted"). Unlike a rate limit this does not
     * clear on its own — it needs a plan change — so callers park for far longer
     * rather than retrying on the normal poll interval.
     */
    case QuotaExhausted = 'quota_exhausted';
    case AuthExpired = 'auth_expired';
    case Failed = 'failed';

    public function isOk(): bool
    {
        return $this === self::Ok;
    }

    /**
     * The HTTP status a connector outcome is reported as by the engagement
     * action endpoints.
     *
     * 422 is deliberately absent and must never be used here. Inertia's
     * `useHttp` special-cases status 422 into its validation-errors path
     * (@inertiajs/react/dist/index.js:1845), parsing the body as `data.errors`
     * and firing `onError`. A connector failure returned as 422 would therefore
     * fire `onError({})` — an empty bag — silently swallowing the message and
     * skipping `onHttpException`, which is where the client rolls the optimistic
     * update back and toasts the reason. That is precisely the silent-lie bug
     * this map exists to fix, so 422 stays reserved for real validation errors.
     */
    public function httpStatus(): int
    {
        return match ($this) {
            self::Ok => 200,
            self::AuthExpired => 403,
            self::Unsupported => 409,
            self::RateLimited => 429,
            self::QuotaExhausted => 402,
            self::Failed => 502,
        };
    }
}
