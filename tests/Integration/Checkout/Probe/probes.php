<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

/*
 * Loads the order-page template probes. CI runs the integration suite with the SHOP's bootstrap
 * (`--bootstrap=/var/www/source/bootstrap.php`), whose autoloader does not map the module's
 * `Tests\` namespace, so a probe class in a file of its own is only reachable when required
 * explicitly. The test files `require_once` this loader.
 */
require_once __DIR__ . '/MollieOrderProbePayment.php';
require_once __DIR__ . '/MollieOrderProbeView.php';
require_once __DIR__ . '/MollieOrderProbeBasket.php';
require_once __DIR__ . '/MollieOrderProbeViewConf.php';
