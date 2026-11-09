<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Integration\WorkerAlternation\Markers\Domain\Model;

use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

final class Greeting extends AbstractEntity
{
    protected string $title = '';

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getLanguageUid(): int
    {
        return (int) $this->_languageUid;
    }
}
