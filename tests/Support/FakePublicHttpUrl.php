<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\PublicHttpUrl;
use Override;

/** Deterministic DNS for test files that explicitly fake their outbound HTTP. */
class FakePublicHttpUrl extends PublicHttpUrl
{
    /** @return list<string> */
    #[Override]
    protected function resolveAddresses(string $host): array
    {
        return ['93.184.216.34'];
    }
}
