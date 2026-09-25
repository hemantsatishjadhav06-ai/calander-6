<?php

declare(strict_types=1);

return [
    'self_hosted' => env('SELF_HOSTED', false),

    /*
     * Allows DefaultUserSeeder to run outside local/testing. It creates a
     * pre-verified instance owner with a known password, so this must stay
     * false anywhere real users exist; it is only for throwaway demo
     * instances.
     */
    'allow_default_user_seed' => (bool) env('ALLOW_DEFAULT_USER_SEED', false),

    /*
     * Community links shown in the sidebar footer. These default to this
     * product's own repository — they previously pointed at the upstream
     * project this was forked from, which asked our users to star and
     * financially sponsor a different organisation. `sponsor_url` is empty by
     * default: sponsorship is opt-in, and an unset link is hidden rather than
     * pointed at someone.
     */
    'community' => [
        'repo' => env('INSTANCE_GITHUB_REPO', 'hemantsatishjadhav06-ai/calander-6'),
        'sponsor_url' => env('INSTANCE_SPONSOR_URL', ''),
    ],

    /*
     * Details the public pages (privacy policy, terms, data deletion) render.
     * They are deliberately unset by default: an instance must not publish a
     * company name or a contact address that its operator never agreed to.
     * `contact_email` falls back to the sender address mail already uses, and
     * the pages say plainly when nothing is configured rather than inventing
     * a contact that does not answer.
     */
    'legal' => [
        'company' => env('INSTANCE_COMPANY_NAME', ''),
        'contact_email' => env('INSTANCE_CONTACT_EMAIL', env('MAIL_FROM_ADDRESS', '')),
        'address' => env('INSTANCE_POSTAL_ADDRESS', ''),
        'jurisdiction' => env('INSTANCE_JURISDICTION', ''),
        'effective_date' => env('INSTANCE_LEGAL_EFFECTIVE_DATE', '2026-09-25'),
    ],

    'defaults' => [
        'registrations_enabled' => env('INSTANCE_REGISTRATIONS_ENABLED', false),
        'workspace_creation_enabled' => env(
            'INSTANCE_WORKSPACE_CREATION_ENABLED',
            env('WORKSPACES_CAN_CREATE_WORKSPACE', true),
        ),
        'usage_tracking_enabled' => env('INSTANCE_USAGE_TRACKING_ENABLED', false),
        'quote_tweets_enabled' => env('INSTANCE_QUOTE_TWEETS_ENABLED', false),
        'linkedin_community_management_enabled' => env('INSTANCE_LINKEDIN_COMMUNITY_MANAGEMENT_ENABLED', false),
        'polling' => [
            'engagement' => [
                'x' => 360,
                'bluesky' => 15,
                'linkedin' => 15,
            ],
            'post_metrics' => [
                'x' => 360,
                'bluesky' => 15,
                'linkedin' => 15,
            ],
            'account_metrics' => [
                'x' => 1440,
                'bluesky' => 1440,
                'linkedin' => 1440,
            ],
        ],
    ],
];
