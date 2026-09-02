<?php
/**
 * API de Respostas Rápidas (Quick Replies) para o Chat ao Vivo.
 *
 * Endpoints (via ?action=):
 *   - list   : lista respostas disponíveis para o usuário (globais + próprias). GET
 *   - create : cria uma nova resposta. POST (title, content, scope)
 *   - update : atualiza uma resposta. POST (id, title, content, scope)
 *   - delete : remove uma resposta. POST (id)
 *
 * Regras:
 *   - Respostas globais (scope=global) só podem ser criadas/editadas por quem
 *     tem a permissão 'quick_replies.manage_global'.
 *   - Respostas individuais pertencem ao usuário que as criou.
 */

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('chat.manage');

header('Content-Type: application/json; charset=utf-8');

$uid = (int)(auth_user_id() ?? 0);
$canManageGlobal = rbac_user_can($uid, 'quick_replies.manage_global');
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

/**
 * Garante que a tabela existe (fallback caso a migration não tenha sido rodada).
 */
function ensureQuickRepliesTable(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS quick_replies (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NULL,
        title VARCHAR(120) NOT NULL,
        content TEXT NOT NULL,
        scope ENUM('global','individual') NOT NULL DEFAULT 'individual',
        created_by_user_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_quick_replies_user (user_id),
        KEY idx_quick_replies_scope (scope)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

try {
    ensureQuickRepliesTable();
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Erro ao preparar tabela: ' . $e->getMessage()]);
    exit;
}

try {
    if ($action === 'list') {
        // Retorna globais + individuais do próprio usuário
        $stmt = db()->prepare("
            SELECT id, title, content, scope, user_id
            FROM quick_replies
            WHERE scope = 'global' OR user_id = :uid
            ORDER BY scope ASC, title ASC
        ");
        $stmt->execute(['uid' => $uid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $items = array_map(function ($r) {
            return [
                'id' => (int)$r['id'],
                'title' => (string)$r['title'],
                'content' => (string)$r['content'],
                'scope' => (string)$r['scope'],
            ];
        }, $rows);

        echo json_encode(['ok' => true, 'items' => $items, 'can_manage_global' => $canManageGlobal]);
        exit;
    }

    if ($action === 'create') {
        $title = trim((string)($_POST['title'] ?? ''));
        $content = trim((string)($_POST['content'] ?? ''));
        $scope = (string)($_POST['scope'] ?? 'individual');

        if ($title === '' || $content === '') {
            echo json_encode(['ok' => false, 'error' => 'Preencha título e conteúdo.']);
            exit;
        }

        if ($scope === 'global' && !$canManageGlobal) {
            echo json_encode(['ok' => false, 'error' => 'Sem permissão para criar resposta global.']);
            exit;
        }

        if (!in_array($scope, ['global', 'individual'], true)) {
            $scope = 'individual';
        }

        // Global: user_id NULL. Individual: user_id do próprio usuário.
        $ownerId = $scope === 'global' ? null : $uid;

        $stmt = db()->prepare("
            INSERT INTO quick_replies (user_id, title, content, scope, created_by_user_id)
            VALUES (:user_id, :title, :content, :scope, :created_by)
        ");
        $stmt->execute([
            'user_id' => $ownerId,
            'title' => $title,
            'content' => $content,
            'scope' => $scope,
            'created_by' => $uid,
        ]);

        echo json_encode(['ok' => true, 'id' => (int)db()->lastInsertId()]);
        exit;
    }

    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $content = trim((string)($_POST['content'] ?? ''));
        $scope = (string)($_POST['scope'] ?? 'individual');

        if ($id <= 0 || $title === '' || $content === '') {
            echo json_encode(['ok' => false, 'error' => 'Dados inválidos.']);
            exit;
        }

        // Verificar propriedade
        $stmt = db()->prepare("SELECT scope, user_id FROM quick_replies WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo json_encode(['ok' => false, 'error' => 'Resposta não encontrada.']);
            exit;
        }

        $isGlobal = $row['scope'] === 'global';
        $isOwner = (int)$row['user_id'] === $uid;

        if ($isGlobal && !$canManageGlobal) {
            echo json_encode(['ok' => false, 'error' => 'Sem permissão para editar resposta global.']);
            exit;
        }
        if (!$isGlobal && !$isOwner) {
            echo json_encode(['ok' => false, 'error' => 'Você só pode editar suas próprias respostas.']);
            exit;
        }

        // Não permitir trocar de individual para global sem permissão
        if ($scope === 'global' && !$canManageGlobal) {
            $scope = $row['scope'];
        }
        if (!in_array($scope, ['global', 'individual'], true)) {
            $scope = $row['scope'];
        }
        $ownerId = $scope === 'global' ? null : $uid;

        $stmt = db()->prepare("
            UPDATE quick_replies
            SET title = :title, content = :content, scope = :scope, user_id = :user_id
            WHERE id = :id
        ");
        $stmt->execute([
            'title' => $title,
            'content' => $content,
            'scope' => $scope,
            'user_id' => $ownerId,
            'id' => $id,
        ]);

        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['ok' => false, 'error' => 'ID inválido.']);
            exit;
        }

        $stmt = db()->prepare("SELECT scope, user_id FROM quick_replies WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo json_encode(['ok' => false, 'error' => 'Resposta não encontrada.']);
            exit;
        }

        $isGlobal = $row['scope'] === 'global';
        $isOwner = (int)$row['user_id'] === $uid;

        if ($isGlobal && !$canManageGlobal) {
            echo json_encode(['ok' => false, 'error' => 'Sem permissão para excluir resposta global.']);
            exit;
        }
        if (!$isGlobal && !$isOwner) {
            echo json_encode(['ok' => false, 'error' => 'Você só pode excluir suas próprias respostas.']);
            exit;
        }

        $stmt = db()->prepare("DELETE FROM quick_replies WHERE id = :id");
        $stmt->execute(['id' => $id]);

        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Ação desconhecida.']);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
