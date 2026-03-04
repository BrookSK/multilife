<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_logout();

flash_set('success', 'Você saiu do sistema.');
header('Location: /login.php');
exit;
