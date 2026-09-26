<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Platform;
use App\Mcp\Servers\ShoutrrrServer;
use App\Support\InstanceSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Mcp\Server\Tool;
use ReflectionClass;

/**
 * The pages an instance serves to people who are not logged in: the marketing
 * home page, the product pages that explain what the app does and how, and the
 * three legal pages.
 *
 * The legal pages are not decoration. Google, Meta, X and LinkedIn all require
 * a reachable privacy policy (and Meta a data-deletion route) before they will
 * approve an OAuth app, so an instance with no public pages cannot offer social
 * sign-in at all.
 */
class PublicPageController extends Controller
{
    public function __construct(private readonly InstanceSettings $settings) {}

    public function home(Request $request): Response|RedirectResponse
    {
        // Signed-in visitors have no use for the pitch; send them to the app,
        // which is what `/` did before this page existed.
        if ($request->user()) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('public/home', [
            ...$this->siteProps(),
            'platforms' => $this->platformOptions(),
            'mcpToolCount' => count($this->mcpToolClasses()),
        ]);
    }

    public function features(): Response
    {
        return Inertia::render('public/features', [
            ...$this->siteProps(),
            'platforms' => $this->platformOptions(),
        ]);
    }

    public function howItWorks(): Response
    {
        return Inertia::render('public/how-it-works', [
            ...$this->siteProps(),
            'platforms' => $this->platformOptions(),
            'cadence' => [
                'replies' => config('engagement.reply_refresh'),
                'repliesSteadyMinutes' => (int) config('engagement.steady_interval_minutes'),
                'repliesMaxBackoff' => (int) config('engagement.max_empty_backoff'),
                'metrics' => config('metrics.post_refresh'),
                'metricsMaxBackoff' => (int) config('metrics.max_unchanged_backoff'),
            ],
            'repost' => [
                'minDelayHours' => (int) config('repost.defaults.min_delay_hours'),
                'maxDelayHours' => (int) config('repost.defaults.max_delay_hours'),
                'plateauStreak' => (int) config('repost.defaults.plateau_streak'),
                'minPercentile' => (float) config('repost.defaults.min_percentile'),
                'baselineDays' => (int) config('repost.baseline.window_days'),
            ],
        ]);
    }

    /**
     * The per-platform numbers come straight from the Platform enum — the same
     * limits the composer enforces and the publish precheck blocks on — so the
     * page cannot promise a limit the app does not apply.
     */
    public function platforms(): Response
    {
        return Inertia::render('public/platforms', [
            ...$this->siteProps(),
            'platforms' => array_map(
                fn (Platform $platform): array => [
                    'value' => $platform->value,
                    'label' => $platform->label(),
                    'connection' => match (true) {
                        $platform->supportsWebhook() => 'webhook',
                        $platform->supportsAppPassword() => 'app-password',
                        default => 'oauth',
                    },
                    'maxLength' => $platform->maxLength(),
                    'lengthUnit' => match ($platform) {
                        Platform::X => 'utf16',
                        Platform::Bluesky => 'graphemes',
                        default => 'characters',
                    },
                    'maxMedia' => $platform->maxMedia(),
                    'maxVideoSeconds' => $platform->maxVideoDurationSeconds(),
                    'threads' => $platform->threadMax() === null,
                    'requiresMedia' => $platform->requiresMedia(),
                    'mixesVideoAndImages' => $platform->combinesVideoAndImages(),
                    'replies' => $platform->supportsEngagement(),
                    'replyLikes' => $platform->supportsEngagement() && $platform->supportsReplyLikes(),
                    'replyMedia' => $platform->supportsReplyMedia(),
                    'directMessages' => $platform->supportsDirectMessages(),
                    'directMessageMedia' => $platform->supportsDirectMessageMedia(),
                    'postMetrics' => $platform->supportsPostMetrics(),
                    'accountMetrics' => $platform->supportsAccountMetrics(),
                    'autoRepost' => $platform->supportsRepost(),
                ],
                Platform::cases(),
            ),
        ]);
    }

    /**
     * The MCP tool list is read from the server definition rather than written
     * out by hand, so a tool added to or removed from the server shows up here
     * without anyone remembering to edit a marketing page.
     */
    public function developers(): Response
    {
        return Inertia::render('public/developers', [
            ...$this->siteProps(),
            'apiBaseUrl' => url('api/v1'),
            'mcpUrl' => url('mcp'),
            'mcpTools' => array_map(
                function (string $class): array {
                    $tool = app($class);

                    return [
                        'name' => $tool->name(),
                        'description' => $tool->description(),
                    ];
                },
                $this->mcpToolClasses(),
            ),
        ]);
    }

    public function security(): Response
    {
        return Inertia::render('public/security', $this->siteProps());
    }

    public function privacy(): Response
    {
        return Inertia::render('public/privacy', $this->siteProps());
    }

    public function terms(): Response
    {
        return Inertia::render('public/terms', $this->siteProps());
    }

    public function dataDeletion(): Response
    {
        return Inertia::render('public/data-deletion', $this->siteProps());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function platformOptions(): array
    {
        return array_map(
            fn (Platform $platform): array => [
                'value' => $platform->value,
                'label' => $platform->label(),
            ],
            Platform::cases(),
        );
    }

    /**
     * The tool classes the MCP server registers. The server keeps them in a
     * protected property with no public accessor, so read its declared default.
     *
     * @return list<class-string<Tool>>
     */
    private function mcpToolClasses(): array
    {
        $tools = (new ReflectionClass(ShoutrrrServer::class))
            ->getProperty('tools')
            ->getDefaultValue();

        return array_values(array_filter(
            is_array($tools) ? $tools : [],
            fn (mixed $class): bool => is_string($class) && is_subclass_of($class, Tool::class),
        ));
    }

    /**
     * Operator-configured details every public page renders.
     *
     * Empty strings are passed through rather than filled in with a plausible
     * default: a privacy policy naming a company that never agreed to it, or a
     * contact address nobody reads, is worse than a page that says the operator
     * has not configured one.
     *
     * @return array{
     *     appName: string,
     *     company: string,
     *     contactEmail: string,
     *     address: string,
     *     jurisdiction: string,
     *     effectiveDate: string,
     *     registrationsEnabled: bool,
     *     repoUrl: string,
     * }
     */
    private function siteProps(): array
    {
        $repo = (string) config('instance.community.repo');

        return [
            'appName' => (string) config('app.name'),
            'company' => (string) config('instance.legal.company'),
            'contactEmail' => (string) config('instance.legal.contact_email'),
            'address' => (string) config('instance.legal.address'),
            'jurisdiction' => (string) config('instance.legal.jurisdiction'),
            'effectiveDate' => (string) config('instance.legal.effective_date'),
            // Every public page needs this, not just the home page: a "Get
            // started" button in the header that bounces off a closed
            // registration route is worse than no button.
            'registrationsEnabled' => $this->settings->registrationsEnabled(),
            // The footer's source link. Empty when an operator clears the repo,
            // in which case the link is hidden rather than pointed anywhere.
            'repoUrl' => $repo !== '' ? 'https://github.com/'.$repo : '',
        ];
    }
}
