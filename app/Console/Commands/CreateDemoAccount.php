<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PostStatus;
use App\Enums\WorkspaceRole;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateDemoAccount extends Command
{
    use ConfirmableTrait;

    protected $signature = 'demo:create
        {--email=demo@example.com : Email address for the demo login}
        {--name=Demo User : Display name for the demo login}
        {--force : Run without confirmation outside local}';

    protected $description = 'Provision (or reset) a demo login in its own workspace, printing a freshly generated password once.';

    /**
     * Sample drafts and scheduled posts, so the demo workspace is not empty on
     * first login. Deliberately no connected accounts and no published posts:
     * a fake connection would look real and fail on first use.
     *
     * @var list<array{text: string, status: PostStatus, in_hours: int|null}>
     */
    private const SAMPLE_POSTS = [
        [
            'text' => "Welcome to SM Manager 👋\n\nThis is a draft. Open it in the composer, edit the text, and pick which accounts it goes to.",
            'status' => PostStatus::Draft,
            'in_hours' => null,
        ],
        [
            'text' => "Scheduling works the same way everywhere: write once, tailor per platform, pick a time.\n\nThis one is queued for tomorrow.",
            'status' => PostStatus::Scheduled,
            'in_hours' => 24,
        ],
        [
            'text' => "Drag a post around the calendar to reschedule it. Drop it on a past slot and it stays where it was.",
            'status' => PostStatus::Scheduled,
            'in_hours' => 72,
        ],
        [
            'text' => "Connect an account under Accounts to start publishing for real. Nothing here is connected yet, so nothing can go out by accident.",
            'status' => PostStatus::Draft,
            'in_hours' => null,
        ],
    ];

    public function handle(): int
    {
        if (! $this->confirmToProceed('This creates a demo login on a non-local instance')) {
            return self::FAILURE;
        }

        $email = (string) $this->option('email');
        $name = (string) $this->option('name');

        $existing = User::query()->where('email', $email)->first();

        // Never silently repurpose a real account — least of all the instance
        // owner, whose password this would rotate out from under them.
        if ($existing?->isInstanceOwner()) {
            $this->error("{$email} is the instance owner. Refusing to turn it into a demo account.");

            return self::FAILURE;
        }

        // Shown once and never stored in plaintext, so there is no known
        // credential sitting in the repo, the deploy config, or the shell
        // history of whoever ran this.
        $password = Str::password(20, symbols: false);

        /** @var array{0: User, 1: Workspace, 2: bool} $provisioned */
        $provisioned = DB::transaction(
            fn (): array => $this->provision($email, $name, $password)
        );

        [$user, $workspace, $seeded] = $provisioned;

        $this->newLine();
        $this->info('Demo account ready.');
        $this->newLine();

        $this->table(['Detail', 'Value'], [
            ['Email', $user->email],
            ['Password', $password],
            ['Workspace', $workspace->name],
            ['Instance owner', 'no'],
            ['Sample posts', $seeded ? count(self::SAMPLE_POSTS).' created' : 'already present, left alone'],
        ]);

        $this->newLine();
        $this->comment('The password is shown here once and is not recoverable. Re-run this command to issue a new one.');

        if ((bool) config('subscriptions.enabled') && ! $workspace->is_initial) {
            $this->newLine();
            $this->warn('Billing is enabled and this is not the instance\'s initial workspace, so the demo workspace will hit the subscription gate.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: User, 1: Workspace, 2: bool} the user, its workspace, and
     *                                               whether sample posts were created by this run
     */
    private function provision(string $email, string $name, string $password): array
    {
        $user = User::query()->firstOrNew(['email' => $email]);

        $user->fill([
            'name' => $user->exists ? $user->name : $name,
            'password' => $password,
        ]);

        // Pre-verified on purpose: a demo login is handed out to people who
        // cannot read that inbox, so an unverified one would dead-end at the
        // verification notice.
        $user->email_verified_at ??= now();
        $user->save();

        // Keyed on the owner rather than a fixed slug, so a second `--email`
        // gets a workspace of its own instead of quietly joining the first
        // demo user's.
        $workspace = Workspace::query()->where('owner_id', $user->id)->oldest()->first()
            ?? Workspace::query()->create([
                'name' => 'Demo Workspace',
                'slug' => $this->availableSlug('demo'),
                'owner_id' => $user->id,
                'timezone' => 'UTC',
            ]);

        WorkspaceMembership::query()->firstOrCreate(
            [
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
            ],
            ['role' => WorkspaceRole::Owner],
        );

        $user->forceFill(['current_workspace_id' => $workspace->id])->save();

        return [$user, $workspace, $this->seedSamplePosts($workspace, $user)];
    }

    /**
     * `demo`, or `demo-2`, `demo-3`, … when that is taken. The slug is unique
     * instance-wide, and a demo workspace must not collide with a real one.
     */
    private function availableSlug(string $base): string
    {
        $slug = $base;
        $suffix = 1;

        while (Workspace::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.++$suffix;
        }

        return $slug;
    }

    /**
     * Create the sample posts, unless the workspace already holds some — a
     * re-run rotates the password without piling up duplicate drafts or
     * discarding whatever the last person tried in the demo.
     */
    private function seedSamplePosts(Workspace $workspace, User $user): bool
    {
        $hasPosts = Post::query()->withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->exists();

        if ($hasPosts) {
            return false;
        }

        foreach (self::SAMPLE_POSTS as $sample) {
            Post::query()->create([
                'workspace_id' => $workspace->id,
                'author_id' => $user->id,
                'base_text' => $sample['text'],
                'segments' => [$sample['text']],
                'status' => $sample['status']->value,
                'scheduled_at' => $sample['in_hours'] === null
                    ? null
                    : now()->addHours($sample['in_hours'])->startOfHour(),
            ]);
        }

        return true;
    }
}
