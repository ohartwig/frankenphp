<?php

declare(strict_types=1);

/*
 * Autoload stand-in for the persistent bootstrap tests: returns the class
 * loader, as vendor/autoload.php does, and boots nothing itself. The test
 * hands PersistentBootstrap a boot function of its own.
 */

return require __DIR__ . '/../../vendor/autoload.php';
