<?php

namespace App\Support;

/**
 * Resolve HTTP fixture hosts without a live DNS dependency. The fetchers still
 * validate the returned addresses and pin curl, and Http::fake handles requests.
 * Unknown hosts retain the real resolver behavior for rejection tests.
 *
 * @return list<string>|false
 */
function gethostbynamel(string $hostname): array|false
{
    if (in_array($hostname, ['example.com', 'static.klipy.com', 'bsky.social', 'public.api.bsky.app', 'plc.directory', 'video.bsky.app'], true)) {
        return ['93.184.215.14'];
    }

    return \gethostbynamel($hostname);
}

/**
 * @return array<int, array<string, mixed>>|false
 */
function dns_get_record(string $hostname, int $type = DNS_ANY): array|false
{
    if (in_array($hostname, ['example.com', 'static.klipy.com', 'bsky.social', 'public.api.bsky.app', 'plc.directory', 'video.bsky.app'], true)) {
        return [];
    }

    return \dns_get_record($hostname, $type);
}
