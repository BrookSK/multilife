<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

try {
    $api = new EvolutionApiV1();
    $res = $api->restartInstance();
    $httpCode = (int)($res['status'] ?? 0);
    
    if ($httpCode >= 200 && $httpCode < 300) {
        flash_set('success', 'Instância reiniciada com sucesso! Aguarde 10 segundos e tente enviar novamente.');
    } else {
        flash_set('error', 'Erro ao reiniciar instância. HTTP: ' . $httpCode);
    }
} catch (Throwable $e) {
    flash_set('error', 'Erro: ' . $e->getMessage());
}

$redirect = $_SERVER['HTTP_REFERER'] ?? '/admin_settings.php';
header('Location: ' . $redirect);
exit;
