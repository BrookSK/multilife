<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

$phone = isset($_GET['phone']) ? trim((string)$_GET['phone']) : '';
$chatId = isset($_GET['chat_id']) ? trim((string)$_GET['chat_id']) : '';

if (empty($phone)) {
    flash_set('error', 'Número de telefone não fornecido.');
    header('Location: /chat_web.php');
    exit;
}

// Redirecionar para página de cadastro de profissional com telefone pré-preenchido
header('Location: /professionals_create.php?phone=' . urlencode($phone) . '&from_chat=1');
exit;
