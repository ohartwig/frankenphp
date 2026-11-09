<?php

declare(strict_types=1);

// The test sends requests for two hosts to one listener.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['trustedHostsPattern'] = '.*';
// Exceptions go to the log the runner prints on failure, not into the page.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['displayErrors'] = 0;
$GLOBALS['TYPO3_CONF_VARS']['SYS']['devIPmask'] = '';
