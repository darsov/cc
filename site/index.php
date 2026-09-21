<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

ccRequireAuth();
header('Location: card_refund.php');
exit;
