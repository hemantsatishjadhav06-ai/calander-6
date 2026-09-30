<?php

declare(strict_types=1);

namespace App\Services\Airtable;

use App\Dto\Post\DraftData;
use App\Enums\PostStatus;
use App\Models\AirtableIntegration;
use App\Models\AirtablePostLink;
use App\Models\Post;
use App\Models\User;
use App\Services\Posts\DraftService;
use App\Services\Posts\PostReviewService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class AirtableSyncService
{
    public function __construct(
        private readonly AirtableClient $client,
        private readonly DraftService $drafts,
        private readonly PostReviewService $reviews,
    ) {}

    public function sync(AirtableIntegration $integration): void
    {
        $lease = (string) Str::uuid();
        $claimed = AirtableIntegration::query()->whereKey($integration->id)->where('enabled', true)
            ->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<', now()))
            ->where(fn ($q) => $q->whereNull('cooldown_until')->orWhere('cooldown_until', '<=', now()))
            ->update(['lease_token' => $lease, 'lease_until' => now()->addMinutes(15)]);
        if ($claimed !== 1) {
            return;
        }
        $integration->refresh();
        $this->client->resetBudget();
        try {
            $actor = User::query()->find($integration->configured_by_id);
            if (! $actor || ! $actor->hasAllPermissions(['workspace.settings.manage'], $integration->workspace_id)) {
                throw new AirtableSyncException('The integration owner no longer has workspace admin access. An admin must save the configuration again.');
            }
            $records = $this->client->records($integration);
            $this->assertUniqueAppIds($records);
            $present = [];
            $remoteFields = [];
            foreach ($records as $record) {
                $present[$record['id']] = true;
                $remoteFields[$record['id']] = $record['fields'];
                $this->receive($integration, $actor, $record);
            }

            Post::withoutGlobalScopes()->where('workspace_id', $integration->workspace_id)
                ->where('status', '!=', PostStatus::Deleted->value)->orderBy('id')
                ->each(function (Post $post) use ($integration): void {
                    AirtablePostLink::query()->firstOrCreate(
                        ['integration_id' => $integration->id, 'app_post_id' => $post->id],
                        ['post_id' => $post->id],
                    );
                });

            $remainingRecords = max(0, (int) config('airtable.max_records', 1000) - count($records));
            AirtablePostLink::query()->where('integration_id', $integration->id)->orderBy('id')
                ->each(function (AirtablePostLink $link) use ($integration, $present, $remoteFields, &$remainingRecords): void {
                    if ($link->record_id && ! isset($present[$link->record_id])) {
                        $link->forceFill(['sync_error' => 'This row was deleted in Airtable. The dashboard post was preserved.'])->save();

                        return;
                    }
                    $post = $link->post_id ? Post::withoutGlobalScopes()->where('workspace_id', $integration->workspace_id)->find($link->post_id) : null;
                    $fields = $post ? $this->fields($post, $link) : [
                        'App Post ID' => $link->app_post_id,
                        'Publishing status' => 'deleted',
                        'Sync status' => 'Deleted in dashboard. This Airtable record is retained for history.',
                    ];
                    $hash = hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR));
                    if ($link->export_hash === $hash && $this->matchesCanonicalFields($fields, $remoteFields[$link->record_id ?? ''] ?? [])) {
                        return;
                    }
                    if ($link->record_id) {
                        $this->client->update($integration, $link->record_id, $fields);
                    } else {
                        if ($remainingRecords === 0) {
                            throw new AirtableSyncException('The Airtable record safety limit is reached. Remaining dashboard posts were preserved without creating more rows.');
                        }
                        $newFields = $fields;
                        if ($integration->post_name_field && ! array_key_exists($integration->post_name_field, $fields)) {
                            $newFields[$integration->post_name_field] = $post?->excerpt(100) ?? 'Archived post';
                        }
                        $link->record_id = $this->client->upsert($integration, $newFields);
                        $remainingRecords--;
                    }
                    $link->forceFill(['export_hash' => $hash])->save();
                });
            $integration->forceFill([
                'last_synced_at' => now(),
                'last_error' => null,
                'next_sync_at' => $integration->sync_interval_minutes ? now()->addMinutes($integration->sync_interval_minutes) : null,
                'cooldown_until' => now()->addMinute(),
            ])->save();
        } catch (AirtableSyncException $error) {
            $integration->forceFill(['last_error' => $error->getMessage(), 'next_sync_at' => now()->addHour()])->save();
        } catch (Throwable $error) {
            // Do not serialize HTTP request objects or raw provider bodies (tokens/content).
            $integration->forceFill(['last_error' => 'Sync stopped unexpectedly. Saved progress is safe; check the application health before retrying.', 'next_sync_at' => now()->addHour()])->save();
            throw $error;
        } finally {
            AirtableIntegration::query()->whereKey($integration->id)->where('lease_token', $lease)
                ->update(['lease_token' => null, 'lease_until' => null]);
        }
    }

    /** @param list<array{id: string, fields: array<string, mixed>}> $records */
    private function assertUniqueAppIds(array $records): void
    {
        $seen = [];
        foreach ($records as $record) {
            $id = $record['fields']['App Post ID'] ?? '';
            if (! is_string($id) || $id === '') {
                continue;
            }
            if (isset($seen[$id])) {
                throw new AirtableSyncException('Duplicate App Post IDs found in Airtable. Remove the duplicate ID before syncing.');
            }
            $seen[$id] = true;
        }
    }

    /** @param array{id: string, fields: array<string, mixed>} $record */
    private function receive(AirtableIntegration $integration, User $actor, array $record): void
    {
        $fields = $record['fields'];
        $link = AirtablePostLink::query()->where('integration_id', $integration->id)->where('record_id', $record['id'])->first();
        if (! $link && is_string($fields['App Post ID'] ?? null) && $fields['App Post ID'] !== '') {
            // Recover only a pre-existing outbound mapping after an ambiguous upsert.
            // Never accept a supplied foreign post/workspace ID as authorization.
            $link = AirtablePostLink::query()->where('integration_id', $integration->id)
                ->where('app_post_id', $fields['App Post ID'])->whereNull('record_id')->first();
            if (! $link) {
                return;
            }
            $link->forceFill(['record_id' => $record['id']])->save();
        }
        $text = $fields['Draft text'] ?? null;
        if (! is_string($text) || trim($text) === '') {
            return;
        }
        if (mb_strlen($text) > 63000) {
            if ($link) {
                $link->forceFill(['sync_error' => 'Draft text exceeds the 63,000 character import limit.'])->save();
            }

            return;
        }
        $revision = is_string($fields['Draft revision'] ?? null) ? $fields['Draft revision'] : '';
        $hash = hash('sha256', json_encode([$text, $revision], JSON_THROW_ON_ERROR));
        if (! $link) {
            DB::transaction(function () use ($integration, $actor, $record, $text, $hash): void {
                $post = $this->drafts->createDraft($integration->workspace_id, $actor, ['kind' => 'none'], [$text]);
                $post->forceFill(['review_required' => true, 'review_status' => 'pending', 'review_revision' => $this->reviews->revision($post)])->save();
                AirtablePostLink::create([
                    'integration_id' => $integration->id, 'post_id' => $post->id,
                    'app_post_id' => $post->id, 'record_id' => $record['id'], 'proposal_hash' => $hash,
                ]);
                $this->reviews->record($post, 'draft_imported', 'airtable');
            });

            return;
        }
        if ($link->proposal_hash === $hash || ! $link->post_id) {
            return;
        }
        DB::transaction(function () use ($integration, $link, $text, $revision, $hash): void {
            $post = Post::withoutGlobalScopes()->where('workspace_id', $integration->workspace_id)->lockForUpdate()->find($link->post_id);
            if (! $post) {
                return;
            }
            if ($post->status !== PostStatus::Draft || count($post->segments) > 1 || ! hash_equals($this->reviews->revision($post), $revision)) {
                $link->forceFill([
                    'conflict' => ['text' => $text, 'revision' => $revision, 'hash' => $hash],
                    'sync_error' => 'Proposal needs review: revision changed, post is not a draft, or it contains a thread. Nothing was overwritten.',
                ])->save();

                return;
            }
            $this->applyText($post, $text);
            $link->forceFill(['proposal_hash' => $hash, 'conflict' => null, 'sync_error' => null])->save();
        });
    }

    public function applyText(Post $post, string $text, ?string $actorId = null): void
    {
        $ids = $post->targets()->pluck('connected_account_id')->all();
        $accountSetId = $post->account_set_id;
        $post = $this->drafts->updateDraft($post, DraftData::fromArray([
            'segments' => [$text],
            'mentions' => $post->mentions ?? [],
            'destination' => ['kind' => 'accounts', 'ids' => $ids],
        ]), source: null, preserveDestination: true);
        $post->forceFill(['account_set_id' => $accountSetId, 'review_required' => true, 'review_status' => 'pending', 'review_revision' => $this->reviews->revision($post), 'review_note' => null])->save();
        $this->reviews->record($post, 'draft_updated', 'airtable', $actorId);
    }

    /**
     * Airtable omits empty cells and normalizes timestamps. Compare only the
     * integration-owned fields, so a hand-edited status is corrected on sync.
     *
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     */
    private function matchesCanonicalFields(array $expected, array $actual): bool
    {
        foreach ($expected as $name => $value) {
            $remote = $actual[$name] ?? null;
            if (in_array($name, ['Scheduled at', 'Published at'], true) && is_string($value) && is_string($remote)) {
                if (strtotime($value) !== strtotime($remote)) {
                    return false;
                }

                continue;
            }
            if (($value === '' ? null : $value) !== ($remote === '' ? null : $remote)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function fields(Post $post, AirtablePostLink $link): array
    {
        return [
            'App Post ID' => $post->id,
            'Text' => $post->base_text,
            'Current revision' => $this->reviews->revision($post),
            'Review status' => $this->reviews->status($post),
            'Review note' => $post->getAttribute('review_note') ?? '',
            'Publishing status' => $post->status->value,
            'Scheduled at' => $post->scheduled_at?->toIso8601String(),
            'Published at' => $post->published_at?->toIso8601String(),
            'Targets' => $post->targets->map(fn ($target): string => $target->platform->value.': '.$target->status->value)->implode("\n"),
            'Dashboard URL' => route('posts.show', $post),
            'Review URL' => route('airtable.index', ['post' => $post->id, 'source' => 'airtable']),
            'Sync status' => $link->sync_error ?? 'Synced. Edit Draft text and copy Current revision into Draft revision; then run sync. Approval uses Review URL.',
        ];
    }
}
