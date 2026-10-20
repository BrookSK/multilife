<?php

declare(strict_types=1);

/**
 * Ação em MASSA (oculta): cancela todos os cards que estão em "Tratamento Manual".
 *
 * Usada para limpar rapidamente a fila de cards que a IA não conseguiu estruturar.
 * Não fica visível por padrão na tela — é acionada por um controle discreto no
 * cabeçalho da coluna "Tratamento Manual" do Kanban (duplo clique).
 *
 * Processa em lotes (limit) para não travar com milhares de cards.
 */

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /demands_list.php');
    exit;
}

$reason = trim((string)($_POST['cancellation_reason'] ?? ''));
if ($reason === '') {
    $reason = 'Limpeza em massa — Tratamento Manual';
}

// Lote por execução (evita travar com grandes volumes). Pode clicar de novo
// para limpar o restante, ou deixar o auto-encadeamento (chain) terminar.
$limit = 300;

$db = db();

// Selecionar os cards em tratamento_manual (lote).
$sel = $db->prepare("SELECT id, status FROM demands WHERE status = 'tratamento_manual' ORDER BY id ASC LIMIT " . (int)$limit);
$sel->execute();
$rows = $sel->fetchAll(PDO::FETCH_ASSOC);

$total = count($rows);
if ($total === 0) {
    flash_set('success', 'Nenhum card em Tratamento Manual para cancelar.');
    header('Location: /demands_list.php');
    exit;
}

$cancelled = 0;
$db->beginTransaction();
try {
    $upd = $db->prepare("UPDATE demands SET status = 'cancelado', cancellation_reason = :reason, cancelled_at = NOW(), cancelled_by_user_id = :uid WHERE id = :id AND status = 'tratamento_manual'");
    $insLog = $db->prepare(
        "INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note)
         VALUES (:did, 'tratamento_manual', 'cancelado', :uid, :note)"
    );
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $upd->execute(['reason' => $reason, 'uid' => auth_user_id(), 'id' => $id]);
        if ($upd->rowCount() > 0) {
            $insLog->execute(['did' => $id, 'uid' => auth_user_id(), 'note' => 'Cancelado (massa): ' . $reason]);
            $cancelled++;
        }
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    flash_set('error', 'Erro ao cancelar em massa: ' . $e->getMessage());
    header('Location: /demands_list.php');
    exit;
}

audit_log('bulk_cancel', 'demands', 'tratamento_manual', null, ['cancelled' => $cancelled, 'reason' => $reason]);

// Quantos ainda restam (para informar se precisa clicar de novo).
$remaining = 0;
try {
    $remaining = (int)$db->query("SELECT COUNT(*) FROM demands WHERE status = 'tratamento_manual'")->fetchColumn();
} catch (Throwable $e) {}

if ($remaining > 0) {
    flash_set('success', $cancelled . ' card(s) de Tratamento Manual cancelados. Ainda restam ' . $remaining . ' — acione novamente para limpar o restante.');
} else {
    flash_set('success', $cancelled . ' card(s) de Tratamento Manual cancelados. Fila limpa.');
}

header('Location: /demands_list.php');
exit;
