<?php

declare(strict_types=1);

namespace App\Services\Airtable;

use App\Models\AirtableIntegration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

class AirtableClient
{
    private int $calls = 0;

    public function resetBudget(): void
    {
        $this->calls = 0;
    }

    public function configured(AirtableIntegration $integration): bool
    {
        return is_string(config('airtable.tokens.'.$integration->workspace_id))
            && config('airtable.tokens.'.$integration->workspace_id) !== '';
    }

    /** @return list<array{id: string, fields: array<string, mixed>}> */
    public function records(AirtableIntegration $integration): array
    {
        $records = [];
        $offset = null;
        do {
            $page = $this->request($integration, 'GET', array_filter(['pageSize' => 100, 'offset' => $offset]));
            if (! isset($page['records']) || ! is_array($page['records'])) {
                throw new AirtableSyncException('Airtable returned an invalid records response.');
            }
            foreach ($page['records'] as $record) {
                if (! is_array($record) || ! is_string($record['id'] ?? null) || ! is_array($record['fields'] ?? null)) {
                    throw new AirtableSyncException('Airtable returned an invalid record.');
                }
                $records[] = ['id' => $record['id'], 'fields' => $record['fields']];
            }
            $offset = $page['offset'] ?? null;
            if (count($records) > (int) config('airtable.max_records', 1000) || ($offset !== null && count($records) >= (int) config('airtable.max_records', 1000))) {
                throw new AirtableSyncException('This integration is limited to 1,000 Airtable records. No partial pull was applied.');
            }
        } while (is_string($offset) && $offset !== '');

        return $records;
    }

    /**
     * Upsert using a persisted app UUID so an ambiguous network result can be retried.
     * Draft text/revision are deliberately NEVER included in outbound fields.
     *
     * @param  array<string, mixed>  $fields
     * @return string Airtable record ID
     */
    public function upsert(AirtableIntegration $integration, array $fields): string
    {
        unset($fields['Draft text'], $fields['Draft revision']);
        $result = $this->request($integration, 'PATCH', [
            'performUpsert' => ['fieldsToMergeOn' => ['App Post ID']],
            'records' => [['fields' => $fields]],
        ]);
        $id = $result['records'][0]['id'] ?? null;
        if (! is_string($id)) {
            throw new AirtableSyncException('Airtable did not confirm the saved record. Retry sync to reconcile it.');
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function update(AirtableIntegration $integration, string $recordId, array $fields): void
    {
        unset($fields['Draft text'], $fields['Draft revision']);
        $this->request($integration, 'PATCH', ['records' => [['id' => $recordId, 'fields' => $fields]]]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function request(AirtableIntegration $integration, string $method, array $payload): array
    {
        if (! $this->configured($integration)) {
            throw new AirtableSyncException('A server-side Airtable token has not been configured for this workspace.');
        }
        if (! preg_match('/^app[a-zA-Z0-9]+$/', $integration->base_id) || ! preg_match('/^tbl[a-zA-Z0-9]+$/', $integration->table_id)) {
            throw new AirtableSyncException('Configure a valid Airtable base ID and table ID.');
        }
        if (++$this->calls > (int) config('airtable.max_calls_per_sync', 50)) {
            throw new AirtableSyncException('Per-sync API budget reached. Progress is saved; sync again later.');
        }
        DB::transaction(function () use ($integration): void {
            $row = AirtableIntegration::query()->lockForUpdate()->findOrFail($integration->id);
            if ($row->quota_month !== now()->utc()->format('Y-m')) {
                $row->forceFill(['quota_month' => now()->utc()->format('Y-m'), 'api_calls' => 0]);
            }
            if ($row->api_calls >= (int) config('airtable.monthly_call_budget', 800)) {
                throw new AirtableSyncException('This integration’s monthly API safety budget is exhausted. Check Airtable usage before raising it.');
            }
            $row->forceFill(['api_calls' => $row->api_calls + 1])->save();
        });

        // Four calls/second for this serial worker. Airtable can still rate-limit
        // other users/apps on the same base; a 429 always stops and backs off.
        Sleep::usleep(260000);
        try {
            $response = Http::withToken((string) config('airtable.tokens.'.$integration->workspace_id))
                ->acceptJson()->timeout(10)->connectTimeout(3)
                ->withOptions(['allow_redirects' => false])
                ->send($method, 'https://api.airtable.com/v0/'.$integration->base_id.'/'.$integration->table_id,
                    [$method === 'GET' ? 'query' : 'json' => $payload]);
        } catch (ConnectionException) {
            throw new AirtableSyncException('Airtable could not be reached. Saved progress will be reconciled on the next sync.');
        }
        if ($response->status() === 429) {
            $integration->forceFill(['cooldown_until' => now()->addHour()])->save();
            throw new AirtableSyncException('Airtable rate or plan limit reached. Sync is paused for one hour.');
        }
        if (! $response->successful()) {
            throw new AirtableSyncException(match ($response->status()) {
                401, 403 => 'Airtable access was denied. Check the server token, base access and record read/write scopes.',
                404 => 'Airtable base or table was not found. Check the saved IDs and token access.',
                422 => 'Airtable rejected the schema. Check exact field names/types and duplicate App Post IDs.',
                default => 'Airtable sync failed (HTTP '.$response->status().'). Retry later.',
            });
        }
        $data = $response->json();
        if (! is_array($data)) {
            throw new AirtableSyncException('Airtable returned an invalid response.');
        }

        return $data;
    }
}
