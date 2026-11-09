<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Integration\WorkerAlternation\Markers\Domain\Repository;

use Koh\FrankenPHP\Tests\Integration\WorkerAlternation\Markers\Domain\Model\Greeting;
use TYPO3\CMS\Extbase\Persistence\Repository;

/**
 * @extends Repository<Greeting>
 */
final class GreetingRepository extends Repository
{
    /**
     * The greeting in the language of the current context, by the uid of its
     * default-language record. findByUid() asks the persistence session's
     * identity map first: a session kept from an earlier request would answer
     * with the object it loaded then, in that request's language.
     */
    public function findInCurrentLanguage(int $uid): ?Greeting
    {
        $result = $this->findByUid($uid);

        return $result instanceof Greeting ? $result : null;
    }
}
