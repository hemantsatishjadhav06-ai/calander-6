<?php

declare(strict_types=1);

return [
    'publishing_enabled' => env('BLOG_PUBLISHING_ENABLED', false),
    'owner_email' => env('BLOG_PUBLISHING_OWNER_EMAIL'),

    'sites' => [
        '47e0a5cc-d9d9-428b-a36b-beea806bff6f' => 'https://neopolisinfra.com',
        '964e086b-1cf2-47f7-8b78-16909d268319' => 'https://morespace.netlify.app',
    ],
];
