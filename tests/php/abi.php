<?php
declare(strict_types=1);

require_once __DIR__ . '/../abi.php';
verifyTargetRuntime();
$library = getenv('OPAQUE_EXTENSION');
if ($library === false || $library === '') {
    throw new RuntimeException('OPAQUE_EXTENSION must identify the artifact being tested');
}
verifyModuleAbi($library);
