<?php

declare(strict_types=1);

/**
 * An autoload file that behaves like a TYPO3 request which failed after the
 * bootstrap registered its exception handler: the handler answers with a
 * status of its own and an error page, as TYPO3's does for a page that does
 * not exist on a site without error handling.
 *
 * The request wrote part of a page before it failed.
 */
set_exception_handler(static function (\Throwable $e): void {
    http_response_code(404);
    echo '<h1>Page Not Found</h1>';
});

echo 'half a page';

throw new RuntimeException('the page does not exist');
