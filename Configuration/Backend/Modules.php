<?php

declare(strict_types=1);

use Koh\FrankenPHP\Controller\CacheManagementController;

return [
    'tools_frankenphp' => [
        'parent' => 'tools',
        'position' => [],
        'access' => 'admin',
        'iconIdentifier' => 'frankenphp',
        'labels' => 'LLL:EXT:frankenphp/Resources/Private/Language/locallang.xlf',
        'extensionName' => 'FrankenPHP',
        'routes' => [
            '_default' => [
                'target' => CacheManagementController::class . '::indexAction',
            ],
        ],
        'controllerActions' => [
            CacheManagementController::class => [
                'index',
                'flush',
                'flushSite',
            ],
        ],
    ],
];
