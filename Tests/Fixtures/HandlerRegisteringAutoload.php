<?php

declare(strict_types=1);

/**
 * An autoload file that behaves like a bootstrap which got as far as
 * registering its error and exception handler before failing.
 *
 * That is the case the handler cleanup has to cover: TYPO3 registers both
 * during Bootstrap::init(), and a request that dies afterwards must not leave
 * them on the stack for the next one.
 */
set_error_handler(static fn(): bool => false);
set_exception_handler(static function (\Throwable $e): void {});

throw new RuntimeException('bootstrap unavailable in a unit test');
