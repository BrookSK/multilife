<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/insurer_orientations_helper.php';

auth_require_login();
rbac_require_any_permission(['admin.settings.manage', 'cadastros.manage']);

header('Content-Type: application/json');

insurer_orientations_ensure_schema();

$db = db();

// GET: listar variações de orientação de uma operadora.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = (string)($_GET['action'] ?? '');
    $insurerId = (int)($_GET['insurer_id'] ?? 0);
    if ($action === 'list' && $insurerId > 0) {
        echo json_encode([
            'success' => true,
            'orientations' => insurer_orientations_list($insurerId, false),
        ]);
        exit;
    }
    echo json_encode(['success' => false, 'error' => 'Parâmetros inválidos']);
    exit;
}

// POST: salvar (criar/editar) ou excluir.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contentType = (string)($_SERVER['CONTENT_TYPE'] ?? '');
    $input = $_POST;
    if (strpos($contentType, 'application/json') !== false) {
        $json = json_decode((string)file_get_contents('php://input'), true);
        if (is_array($json)) {
            $input = $json;
        }
    }

    $action = (string)($input['action'] ?? '');

    if ($action === 'delete') {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'error' => 'ID inválido']);
            exit;
        }
        $db->prepare("DELETE FROM health_insurer_orientations WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'save') {
        $id = (int)($input['id'] ?? 0);
        $insurerId = (int)($input['insurer_id'] ?? 0);
        $label = trim((string)($input['label'] ?? ''));
        $body = trim((string)($input['body'] ?? ''));
        $requiresCredentials = (int)(!empty($input['requires_credentials']) && (string)$input['requires_credentials'] !== '0');
        $isActive = isset($input['is_active']) ? (int)(!empty($input['is_active']) && (string)$input['is_active'] !== '0') : 1;
        $sortOrder = (int)($input['sort_order'] ?? 0);

        if ($insurerId <= 0 || $label === '' || $body === '') {
            echo json_encode(['success' => false, 'error' => 'Informe rótulo e texto da orientação.']);
            exit;
        }

        if ($id > 0) {
            $stmt = $db->prepare(
                "UPDATE health_insurer_orientations
                 SET label = ?, body = ?, requires_credentials = ?, is_active = ?, sort_order = ?
                 WHERE id = ? AND health_insurer_id = ?"
            );
            $stmt->execute([$label, $body, $requiresCredentials, $isActive, $sortOrder, $id, $insurerId]);
            echo json_encode(['success' => true, 'id' => $id]);
            exit;
        }

        $stmt = $db->prepare(
            "INSERT INTO health_insurer_orientations
               (health_insurer_id, label, body, requires_credentials, is_active, sort_order, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$insurerId, $label, $body, $requiresCredentials, $isActive, $sortOrder, auth_user_id()]);
        echo json_encode(['success' => true, 'id' => (int)$db->lastInsertId()]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Ação inválida']);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Método não suportado']);
