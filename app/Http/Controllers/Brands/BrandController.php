<?php

namespace App\Http\Controllers\Brands;

use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Brands\UpdateBrandProfileRequest;
use App\Models\BrandProfile;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Brands\BrandSetupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class BrandController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $user->currentWorkspace;
        abort_if($workspace === null, 404);
        $profile = BrandProfile::query()->where('workspace_id', $workspace->id)->first();
        $accounts = ConnectedAccount::query()->where('workspace_id', $workspace->id)->get();
        $connections = [];

        foreach ([Platform::Instagram, Platform::Facebook, Platform::X] as $platform) {
            $expected = match ($platform) {
                Platform::Instagram => $profile?->instagram_username,
                Platform::Facebook => $profile?->facebook_page_id,
                Platform::X => $profile?->x_username,
            };
            $candidates = $accounts->where('platform', $platform);
            $account = $expected === null ? null : $candidates->first(fn (ConnectedAccount $account): bool => $platform === Platform::Facebook
                ? $account->remote_account_id === $expected
                : strtolower(ltrim($account->handle, '@')) === strtolower($expected));
            $status = match (true) {
                $expected === null => 'not_configured',
                $account === null && $candidates->isNotEmpty() => 'mismatch',
                $account === null => 'not_connected',
                $account->isDisabled(), $account->status !== ConnectedAccountStatus::Active,
                $account->token_expires_at?->isPast() === true => 'needs_attention',
                default => 'connected',
            };
            $connections[] = [
                'platform' => $platform->value,
                'expected' => $expected,
                'status' => $status,
                'handle' => $account?->handle,
                'remote_account_id' => $account?->remote_account_id,
                'metrics_captured_at' => $account?->metrics_captured_at?->toIso8601String(),
            ];
        }

        return Inertia::render('brands/index', [
            'profile' => $profile?->only(['website_url', 'instagram_username', 'facebook_page_id', 'facebook_page_url', 'x_username', 'netlify_site_id', 'repository_url']),
            'connections' => $connections,
            'canManage' => $user->isOwnerOfWorkspace($workspace->id),
            'approvalRequired' => (bool) $workspace->requires_post_approval,
            'draftPlan' => Post::query()->where('workspace_id', $workspace->id)->where('status', PostStatus::Draft)
                ->whereNotNull('planned_schedule_at')->orderBy('planned_schedule_at')->limit(14)->get()
                ->map(fn (Post $post): array => ['id' => $post->id, 'text' => $post->base_text, 'planned_at' => $post->planned_schedule_at?->toIso8601String()])->all(),
        ]);
    }

    public function bootstrap(Request $request, BrandSetupService $setup): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isOwnerOfWorkspace($user->current_workspace_id), 403);
        $setup->setup($user);

        return redirect()->route('brands.index')->with('success', 'Both brands are prepared with private starter drafts and a proposed seven-day calendar. Connect accounts before requesting social review.');
    }

    public function update(UpdateBrandProfileRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $user->currentWorkspace;
        abort_if($workspace === null, 404);
        DB::transaction(function () use ($workspace, $request, $user): void {
            $locked = Workspace::query()->lockForUpdate()->findOrFail($workspace->id);
            abort_unless($locked->owner_id === $user->id && $user->isOwnerOfWorkspace($locked->id), 403);
            BrandProfile::query()->updateOrCreate(['workspace_id' => $locked->id], $request->validated());
            $locked->forceFill(['requires_post_approval' => true])->save();
        });

        return back()->with('success', 'Brand details saved. Draft approval is required.');
    }
}
