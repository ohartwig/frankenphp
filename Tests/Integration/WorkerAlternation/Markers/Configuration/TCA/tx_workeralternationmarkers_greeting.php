<?php

declare(strict_types=1);

// One greeting per site and language; read through Extbase and directly.
return [
    'ctrl' => [
        'title' => 'Worker alternation greeting',
        'label' => 'title',
        'languageField' => 'sys_language_uid',
        'transOrigPointerField' => 'l10n_parent',
        'translationSource' => 'l10n_source',
    ],
    'columns' => [
        'title' => [
            'label' => 'Title',
            'config' => [
                'type' => 'input',
            ],
        ],
    ],
    'types' => [
        '0' => ['showitem' => 'title'],
    ],
];
