<?php

declare(strict_types=1);

// Wraps everything after site resolution, so it sees the state once the page
// has been rendered.
return [
    'frontend' => [
        // Changes the configuration services after the page is done, see
        // ConfigServiceMutator.
        'koh/worker-alternation/config-service-mutator' => [
            'target' => \Koh\FrankenPHP\Tests\Integration\WorkerAlternation\Markers\ConfigServiceMutator::class,
            'after' => [
                'typo3/cms-frontend/site',
            ],
        ],
        'koh/worker-alternation/late-state' => [
            'target' => \Koh\FrankenPHP\Tests\Integration\WorkerAlternation\Markers\LateStateMiddleware::class,
            'after' => [
                'typo3/cms-frontend/site',
            ],
        ],
    ],
];
