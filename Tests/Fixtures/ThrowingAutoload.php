<?php

declare(strict_types=1);

/**
 * An autoload file that fails on purpose.
 *
 * FrontendWorker requires its autoload path and then bootstraps TYPO3 from what
 * it returns; neither can succeed in a unit test. Pointing at a missing file
 * would work too, but `require` on one emits a PHP warning before it fails, and
 * a test that leaves a warning behind is a test nobody can turn failOnWarning on
 * around. This throws cleanly instead, which is the same thing as far as the
 * catch block under test is concerned.
 */
throw new RuntimeException('bootstrap unavailable in a unit test');
