<?php

declare(strict_types=1);

// login.php redirects authenticated users to card_refund.php.
header('Location: login.php', true, 302);
exit;
