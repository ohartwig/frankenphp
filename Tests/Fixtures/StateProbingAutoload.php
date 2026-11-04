<?php

declare(strict_types=1);

use Koh\FrankenPHP\Tests\Fixtures\RequestStateProbe;

/**
 * An autoload file that behaves like a request which inherits and leaves
 * state: it records whether BE_USER was already set when it started, then
 * sets it itself, as a backend-authenticated bootstrap would, and fails.
 *
 * The two halves pin the two resets in the worker loop separately: what a
 * request finds at its start, and what is left once it is over.
 */
RequestStateProbe::$backendUserAtStart[] = isset($GLOBALS['BE_USER']);
$GLOBALS['BE_USER'] = new stdClass();

throw new RuntimeException('bootstrap unavailable in a unit test');
