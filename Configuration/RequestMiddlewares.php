<?php

return [
    'frontend' => [
        'frankenphp-early-hints' => [
            'target' => \Koh\FrankenPHP\Middleware\EarlyHintMiddleware::class,
            'before' => [
                'typo3/cms-frontend/timetracker',
            ],
        ],
        'frankenphp-surrogate-key' => [
            'target' => \Koh\FrankenPHP\Middleware\SurrogateKeyMiddleware::class,
            'after' => [
                'typo3/cms-frontend/page-resolver',
            ],
            'before' => [
                'typo3/cms-frontend/prepare-tsfe-rendering',
            ],
        ],
    ],
];
