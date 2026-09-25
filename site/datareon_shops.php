<?php

declare(strict_types=1);

// Keep the first URL working while Datareon switches to datareon.php?type=shops.
$_GET['type'] = 'shops';
require __DIR__ . '/datareon.php';
