<?php

declare(strict_types=1);

/**
 * "Versão" da lista de conversas: o id da última mensagem gravada.
 * O chat consulta a cada poucos segundos e só recarrega a lista quando muda
 * (consulta pela chave primária: praticamente sem custo).
 */

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('chat.manage');
session_release();

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

try {
    $v = (int)(db()->query('SELECT MAX(id) FROM chat_messages')->fetchColumn() ?: 0);
} catch (Throwable $e) {
    $v = 0;
}
echo json_encode(['v' => $v]);
