<?php

declare(strict_types=1);

// Form submissions of the worker alternation test (SaveToDatabase plus
// RecordRequestFinisher).
return [
    'ctrl' => [
        'title' => 'Worker alternation form submission',
        'label' => 'name',
        'crdate' => 'crdate',
    ],
    'columns' => [
        'form' => ['label' => 'Form', 'config' => ['type' => 'input']],
        'name' => ['label' => 'Name', 'config' => ['type' => 'input']],
        'email' => ['label' => 'Email', 'config' => ['type' => 'input']],
        'topic' => ['label' => 'Topic', 'config' => ['type' => 'input']],
        'message' => ['label' => 'Message', 'config' => ['type' => 'text']],
        'site' => ['label' => 'Site', 'config' => ['type' => 'input']],
        'language' => ['label' => 'Language', 'config' => ['type' => 'number']],
        'host' => ['label' => 'Host', 'config' => ['type' => 'input']],
    ],
    'types' => [
        '0' => ['showitem' => 'form, name, email, topic, message, site, language, host'],
    ],
];
