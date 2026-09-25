<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Platform;
use App\Support\InstanceSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The pages an instance serves to people who are not logged in: the marketing
 * home page and the three legal pages.
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
            'platforms' => array_map(
                fn (Platform $platform): array => [
                    'value' => $platform->value,
                    'label' => $platform->label(),
                ],
                Platform::cases(),
            ),
        ]);
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
     * }
     */
    private function siteProps(): array
    {
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
        ];
    }
}
