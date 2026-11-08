<?php

declare(strict_types=1);

use Koh\FrankenPHP\Tests\Fixtures\ConnectionProbe;

/*
 * An autoload file that behaves like a request which queries the database:
 * it records which connection the pool offered, uses one, and fails as every
 * bootstrap in a unit test does.
 */
ConnectionProbe::useDefault();

throw new RuntimeException('bootstrap unavailable in a unit test');
