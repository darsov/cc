<?php

declare(strict_types=1);

// Keep the root independent of sessions, the database and template includes.
header('X-CC-Index-Revision: 20260925-redirect');
header('Location: /login.php', true, 302);
exit;
