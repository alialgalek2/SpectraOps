<?php
declare(strict_types=1);

// This installation is self-contained.  The old absolute development-machine
// include made the endpoint fail after deployment.
require_once dirname(__DIR__, 2) . '/telemetry_collector.php';
