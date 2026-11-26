<?php

declare(strict_types=1);

/**
 * API do Chat Interno (JSON). Tela: internal_chat.php.
 * GET  action=bootstrap | conversations | messages | poll | unread
 * POST action=send | upload | start_direct | create_group | rename | add_members |
 *      remove_member | leave | mark_read | edit | delete
 */

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/internal_chat.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$respond = static function (array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

$uid = (int)(auth_user_id() ?? 0);
if ($uid <= 0) {
    $respond(['ok' => false, 'error' => 'Sessão expirada. Entre de novo.'], 401);
}
if (!ichat_user_allowed($uid)) {
    $respond(['ok' => false, 'error' => 'O chat interno é só para a equipe.'], 403);
}
session_release(); // polling não trava as outras telas
ichat_ensure_schema();

$action = (string)($_GET['action'] ?? ($_POST['action'] ?? ''));
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$convId = (int)($_GET['conversation_id'] ?? ($_POST['conversation_id'] ?? 0));

$requireMember = static function (int $cid) use ($uid, $respond): array {
    $conv = $cid > 0 ? ichat_conversation($cid) : null;
    $mem = $conv ? ichat_membership($cid, $uid) : null;
    if (!$conv || !$mem) {
        $respond(['ok' => false, 'error' => 'Conversa não encontrada ou você não participa dela.'], 404);
    }
    return [$conv, $mem];
};
$requireGroupAdmin = static function (array $conv, array $mem) use ($respond): void {
    if ($conv['type'] !== 'group' || $mem['role'] !== 'admin') {
        $respond(['ok' => false, 'error' => 'Só o administrador do grupo pode fazer isso.'], 403);
    }
};

try {
    if (!$isPost) {
        switch ($action) {
            case 'bootstrap':
                $users = array_values(array_filter(ichat_staff_users(), static fn($u) => $u['id'] !== $uid));
                $respond(['ok' => true, 'me' => ['id' => $uid, 'name' => ichat_user_name($uid)], 'users' => $users,
                    'conversations' => ichat_conversations($uid), 'max_file_mb' => INTERNAL_CHAT_MAX_FILE_MB]);
            case 'conversations':
                $respond(['ok' => true, 'conversations' => ichat_conversations($uid), 'unread' => ichat_unread_total($uid)]);
            case 'unread':
                $respond(['ok' => true, 'unread' => ichat_unread_total($uid)]);
            case 'messages':
                [$conv, $mem] = $requireMember($convId);
                $before = (int)($_GET['before_id'] ?? 0);
                $msgs = ichat_messages($convId, $uid, 0, $before, 60);
                $respond(['ok' => true, 'conversation' => ['id' => (int)$conv['id'], 'type' => $conv['type'], 'title' => $conv['title'], 'my_role' => $mem['role']],
                    'members' => ichat_members($convId), 'messages' => $msgs, 'has_more' => count($msgs) >= 60]);
            case 'poll':
                $after = (int)($_GET['after_id'] ?? 0);
                $out = ['ok' => true, 'unread' => ichat_unread_total($uid)];
                if ($convId > 0 && ichat_membership($convId, $uid)) {
                    $out['messages'] = ichat_messages($convId, $uid, $after, 0, 100);
                    // edições/apagadas recentes nas mensagens já na tela
                    $st = db()->prepare("SELECT m.*, u.name AS user_name FROM internal_chat_messages m LEFT JOIN users u ON u.id = m.user_id
                                         WHERE m.conversation_id = ? AND m.id <= ? AND (m.edited_at > NOW() - INTERVAL 2 MINUTE OR m.deleted_at > NOW() - INTERVAL 2 MINUTE)");
                    $st->execute([$convId, $after]);
                    $out['changed'] = array_map(static fn($r) => ichat_message_view($r, $uid), $st->fetchAll(PDO::FETCH_ASSOC));
                    $out['members'] = ichat_members($convId);
                }
                if (!empty($_GET['with_list'])) {
                    $out['conversations'] = ichat_conversations($uid);
                }
                $respond($out);
        }
        $respond(['ok' => false, 'error' => 'Ação inválida.'], 400);
    }

    switch ($action) {
        case 'send':
            [$conv] = $requireMember($convId);
            $id = ichat_send($convId, $uid, (string)($_POST['body'] ?? ''), (int)($_POST['reply_to_id'] ?? 0) ?: null);
            $respond(['ok' => true, 'message' => ichat_messages($convId, $uid, $id - 1, 0, 1)[0] ?? null]);
        case 'upload':
            [$conv] = $requireMember($convId);
            if (empty($_FILES['file'])) {
                $respond(['ok' => false, 'error' => 'Escolha um arquivo.'], 400);
            }
            $file = ichat_store_upload($_FILES['file']);
            $id = ichat_send($convId, $uid, (string)($_POST['body'] ?? ''), (int)($_POST['reply_to_id'] ?? 0) ?: null, $file);
            $respond(['ok' => true, 'message' => ichat_messages($convId, $uid, $id - 1, 0, 1)[0] ?? null]);
        case 'start_direct':
            $other = (int)($_POST['user_id'] ?? 0);
            if (!ichat_user_allowed($other)) {
                $respond(['ok' => false, 'error' => 'Essa pessoa não usa o chat interno.'], 400);
            }
            $respond(['ok' => true, 'conversation_id' => ichat_direct($uid, $other)]);
        case 'create_group':
            $ids = array_map('intval', (array)($_POST['member_ids'] ?? []));
            $respond(['ok' => true, 'conversation_id' => ichat_create_group($uid, (string)($_POST['title'] ?? ''), $ids)]);
        case 'rename':
            [$conv, $mem] = $requireMember($convId);
            $requireGroupAdmin($conv, $mem);
            $title = trim(mb_substr((string)($_POST['title'] ?? ''), 0, 120));
            if ($title === '') {
                $respond(['ok' => false, 'error' => 'Nome vazio.'], 400);
            }
            db()->prepare('UPDATE internal_chat_conversations SET title = ? WHERE id = ?')->execute([$title, $convId]);
            ichat_system_message($convId, ichat_user_name($uid) . ' mudou o nome do grupo para "' . $title . '"');
            $respond(['ok' => true]);
        case 'add_members':
            [$conv, $mem] = $requireMember($convId);
            $requireGroupAdmin($conv, $mem);
            $added = ichat_add_members($convId, (array)($_POST['member_ids'] ?? []), $uid);
            $respond(['ok' => true, 'added' => $added]);
        case 'remove_member':
            [$conv, $mem] = $requireMember($convId);
            $requireGroupAdmin($conv, $mem);
            $target = (int)($_POST['user_id'] ?? 0);
            if ($target === $uid) {
                $respond(['ok' => false, 'error' => 'Para sair, use "Sair do grupo".'], 400);
            }
            db()->prepare('UPDATE internal_chat_members SET left_at = NOW() WHERE conversation_id = ? AND user_id = ? AND left_at IS NULL')->execute([$convId, $target]);
            ichat_system_message($convId, ichat_user_name($uid) . ' removeu ' . ichat_user_name($target));
            $respond(['ok' => true]);
        case 'make_admin':
            [$conv, $mem] = $requireMember($convId);
            $requireGroupAdmin($conv, $mem);
            db()->prepare("UPDATE internal_chat_members SET role = 'admin' WHERE conversation_id = ? AND user_id = ? AND left_at IS NULL")->execute([$convId, (int)($_POST['user_id'] ?? 0)]);
            $respond(['ok' => true]);
        case 'leave':
            [$conv, $mem] = $requireMember($convId);
            if ($conv['type'] !== 'group') {
                $respond(['ok' => false, 'error' => 'Só dá para sair de grupos.'], 400);
            }
            db()->prepare('UPDATE internal_chat_members SET left_at = NOW() WHERE conversation_id = ? AND user_id = ?')->execute([$convId, $uid]);
            // grupo sem administrador: o participante mais antigo assume
            $st = db()->prepare("SELECT COUNT(*) FROM internal_chat_members WHERE conversation_id = ? AND role = 'admin' AND left_at IS NULL");
            $st->execute([$convId]);
            if ((int)$st->fetchColumn() === 0) {
                db()->prepare("UPDATE internal_chat_members SET role = 'admin' WHERE conversation_id = ? AND left_at IS NULL ORDER BY joined_at ASC LIMIT 1")->execute([$convId]);
            }
            ichat_system_message($convId, ichat_user_name($uid) . ' saiu do grupo');
            $respond(['ok' => true]);
        case 'mark_read':
            [$conv] = $requireMember($convId);
            ichat_mark_read($convId, $uid, (int)($_POST['last_id'] ?? 0));
            $respond(['ok' => true, 'unread' => ichat_unread_total($uid)]);
        case 'edit':
        case 'delete':
            $mid = (int)($_POST['message_id'] ?? 0);
            $st = db()->prepare('SELECT * FROM internal_chat_messages WHERE id = ?');
            $st->execute([$mid]);
            $m = $st->fetch(PDO::FETCH_ASSOC);
            if (!$m || (int)($m['user_id'] ?? 0) !== $uid || !ichat_membership((int)$m['conversation_id'], $uid) || !empty($m['deleted_at'])) {
                $respond(['ok' => false, 'error' => 'Só dá para mudar as suas próprias mensagens.'], 403);
            }
            if ($action === 'delete') {
                db()->prepare('UPDATE internal_chat_messages SET deleted_at = NOW() WHERE id = ?')->execute([$mid]);
            } else {
                $body = rtrim(str_replace("\r\n", "\n", (string)($_POST['body'] ?? '')));
                if ($body === '' && $m['kind'] === 'text') {
                    $respond(['ok' => false, 'error' => 'Mensagem vazia.'], 400);
                }
                db()->prepare('UPDATE internal_chat_messages SET body = ?, edited_at = NOW() WHERE id = ?')->execute([mb_substr($body, 0, 8000), $mid]);
            }
            $st->execute([$mid]);
            $respond(['ok' => true, 'message' => ichat_message_view($st->fetch(PDO::FETCH_ASSOC) + ['user_name' => ichat_user_name($uid)], $uid)]);
    }
    $respond(['ok' => false, 'error' => 'Ação inválida.'], 400);
} catch (Throwable $e) {
    error_log('[INTERNAL_CHAT] ' . $action . ': ' . $e->getMessage());
    $respond(['ok' => false, 'error' => $e instanceof RuntimeException ? $e->getMessage() : 'Erro ao processar: ' . $e->getMessage()], 400);
}
