<?php

return [
    'frontend' => [
        'frankenphp-early-hints' => [
            'target' => \Koh\FrankenPHP\Middleware\EarlyHintMiddleware::class,
            'before' => [
                'typo3/cms-frontend/timetracker',
            ],
        ],
    ],
];
