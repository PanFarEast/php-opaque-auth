<?php
declare(strict_types=1);

require_once __DIR__ . '/abi.php';
verifyTargetRuntime();
echo 'Verified target: PHP ' . PHP_VERSION . (PHP_ZTS ? " ZTS\n" : " NTS\n");
