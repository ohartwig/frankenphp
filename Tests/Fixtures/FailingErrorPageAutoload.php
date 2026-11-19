<?php

declare(strict_types=1);

/**
 * An autoload file whose exception handler fails while it renders its error
 * page, after it has set a status and printed part of the page.
 */
set_exception_handler(static function (\Throwable $e): void {
    http_response_code(404);
    echo 'part of an error page';

    throw new LogicException('the error page could not be rendered');
});

throw new RuntimeException('the page does not exist');
