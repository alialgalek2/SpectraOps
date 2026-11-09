#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * SpectraOps CLI Automated Lifecycle Test Runner
 * 
 * Usage:
 *   php bin/test-full-lifecycle.php
 */

require_once dirname(__DIR__) . '/tests/LifecycleTest.php';

use FleetHub\Tests\LifecycleTest;

$runner = new LifecycleTest();
$runner->run();
