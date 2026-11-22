<?php
/**
 * REPROCESSAR REAÇÕES DE CAPTAÇÃO QUE NÃO VIRARAM INTERESSE.
 *
 * Abra no navegador, logado como admin:
 *   https://SEU-DOMINIO/testes/reprocessar_reacoes_captacao.php            (só mostra, não grava)
 *   https://SEU-DOMINIO/testes/reprocessar_reacoes_captacao.php?dias=7     (janela de dias, padrão 3)
 * Depois de conferir, clique em "Registrar interesses".
 *
 * Pega as reações em GRUPOS gravadas em chat_reactions que não viraram interesse
 * (ex.: o disparo ficou sem o id da mensagem e a reação foi gravada como "msg não é
 * captação"). Acha a captação pela mesma regra do webhook (id do disparo, texto no
 * chat ou texto da mensagem direto no WhatsApp pelo token #CAP) e registra o
 * interesse com a data/hora da reação. Não registra reação do próprio WhatsApp do
 * sistema nem em card admitido/concluído/cancelado. Pode rodar de novo: não duplica.
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/whatsapp_webhook_handler.php';

auth_require_login();
if (!rbac_user_has_role((int)auth_user_id(), 'admin')) {
    http_response_code(403);
    exit('Somente admin.');
}
@set_time_limit(300);

$days = max(1, min(30, (int)($_GET['dias'] ?? $_POST['dias'] ?? 3)));
$apply = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if ($apply && !hash_equals((string)($_SESSION['reproc_reacoes_nonce'] ?? ''), (string)($_POST['nonce'] ?? ''))) {
    http_response_code(400);
    exit('Página expirada. Abra de novo e clique em "Registrar interesses".');
}
$nonce = bin2hex(random_bytes(16));
$_SESSION['reproc_reacoes_nonce'] = $nonce;

$st = db()->prepare("SELECT cr.remote_jid, cr.message_id, cr.reactor_jid, cr.emoji, cr.reaction_timestamp,
                            wg.instance_name, wg.name AS group_name
                     FROM chat_reactions cr
                     INNER JOIN whatsapp_groups wg ON wg.evolution_group_jid = cr.remote_jid
                     WHERE cr.remote_jid LIKE '%@g.us' AND cr.reactor_jid <> 'me'
                       AND cr.created_at >= DATE_SUB(NOW(), INTERVAL :dias DAY)
                     ORDER BY cr.created_at ASC");
$st->execute(['dias' => $days]);
$reactions = $st->fetchAll(PDO::FETCH_ASSOC);

// Números dos WhatsApps do sistema: reação deles não é interesse de profissional.
$ownKeys = [];
foreach (db()->query("SELECT owner_number, owner_phone_formatted FROM whatsapp_instances")->fetchAll(PDO::FETCH_ASSOC) as $wi) {
    foreach ([$wi['owner_number'], $wi['owner_phone_formatted']] as $n) {
        $d = preg_replace('/\D+/', '', (string)$n);
        if (strlen($d) >= 8) {
            $ownKeys[substr($d, -8)] = true;
        }
    }
}

$dispatchCache = [];
$rows = [];
$count = ['registrar' => 0, 'ja_existe' => 0, 'nao_captacao' => 0, 'encerrada' => 0, 'whatsapp_sistema' => 0, 'registrado' => 0];
$exists = db()->prepare("SELECT 1 FROM demand_interested_professionals WHERE demand_id = ? AND dispatch_log_id = ? AND phone = ? LIMIT 1");
$findUser = db()->prepare("SELECT id, name FROM users
                           WHERE REPLACE(REPLACE(REPLACE(REPLACE(phone, '-', ''), ' ', ''), '(', ''), ')', '') LIKE ? LIMIT 1");
$ins = db()->prepare("INSERT IGNORE INTO demand_interested_professionals
                      (demand_id, dispatch_log_id, phone, phone_jid, push_name, user_id, emoji, reacted_at)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

foreach ($reactions as $r) {
    $mid = (string)$r['message_id'];
    if (!array_key_exists($mid, $dispatchCache)) {
        try {
            $dispatchCache[$mid] = wa_wh_find_dispatch_for_message($mid, (string)$r['remote_jid'], (string)($r['instance_name'] ?? ''));
        } catch (Throwable $e) {
            $dispatchCache[$mid] = null;
        }
    }
    $dispatch = $dispatchCache[$mid];
    $reactor = (string)$r['reactor_jid'];
    if (substr($reactor, -4) === '@lid' && function_exists('chat_lid_lookup')) {
        $known = chat_lid_lookup($reactor);
        if ($known !== '') {
            $reactor = $known;
        }
    }
    $isLid = substr($reactor, -4) === '@lid';
    $phone = (string)preg_replace('/[^0-9]/', '', (string)preg_replace('/[:@].+$/', '', $reactor));
    $row = ['quando' => date('d/m H:i', (int)$r['reaction_timestamp']), 'grupo' => (string)$r['group_name'], 'emoji' => (string)$r['emoji'],
            'quem' => $phone, 'card' => '', 'situacao' => ''];

    if (!$dispatch) {
        $row['situacao'] = 'mensagem não é de captação';
        $count['nao_captacao']++;
    } elseif (in_array((string)$dispatch['demand_status'], ['admitido', 'concluido', 'cancelado'], true)) {
        $row['card'] = '#' . (int)$dispatch['demand_id'];
        $row['situacao'] = 'card encerrado';
        $count['encerrada']++;
    } elseif (!$isLid && $phone !== '' && isset($ownKeys[substr($phone, -8)])) {
        $row['card'] = '#' . (int)$dispatch['demand_id'];
        $row['situacao'] = 'WhatsApp do sistema (ignorado)';
        $count['whatsapp_sistema']++;
    } else {
        $row['card'] = '#' . (int)$dispatch['demand_id'];
        $exists->execute([(int)$dispatch['demand_id'], (int)$dispatch['id'], $phone]);
        if ($exists->fetchColumn()) {
            $row['situacao'] = 'interesse já registrado';
            $count['ja_existe']++;
        } else {
            $userId = null;
            $userName = null;
            if ($phone !== '' && !$isLid) {
                $findUser->execute(['%' . substr($phone, -8) . '%']);
                if ($u = $findUser->fetch(PDO::FETCH_ASSOC)) {
                    $userId = (int)$u['id'];
                    $userName = (string)$u['name'];
                }
            }
            if ($userName) {
                $row['quem'] = $userName;
            }
            if ($apply) {
                $ins->execute([(int)$dispatch['demand_id'], (int)$dispatch['id'], $phone,
                    $phone !== '' ? $phone . ($isLid ? '@lid' : '@s.whatsapp.net') : '',
                    $userName, $userId, (string)$r['emoji'], date('Y-m-d H:i:s', (int)$r['reaction_timestamp'])]);
                $row['situacao'] = $ins->rowCount() > 0 ? 'REGISTRADO agora' : 'interesse já registrado';
                $count[$ins->rowCount() > 0 ? 'registrado' : 'ja_existe']++;
            } else {
                $row['situacao'] = 'vai registrar';
                $count['registrar']++;
            }
        }
    }
    $rows[] = $row;
}

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
echo '<title>Reprocessar reações de captação</title>';
echo '<style>body{font-family:Segoe UI,Arial,sans-serif;margin:16px;color:#111b21}table{border-collapse:collapse;width:100%;font-size:13px}'
   . 'th,td{border-bottom:1px solid #e5e7eb;padding:6px 8px;text-align:left;vertical-align:top}th{background:#f3f4f6}'
   . '.box{padding:10px 14px;border-radius:8px;background:#f0fdf4;border:1px solid #bbf7d0;margin:12px 0}'
   . 'button{padding:10px 18px;border:0;border-radius:8px;background:#00a884;color:#fff;font-weight:600;font-size:14px;cursor:pointer}'
   . '.wrap{overflow-x:auto}</style></head><body>';
echo '<h2>Reações de captação dos últimos ' . $days . ' dia(s)</h2>';
echo '<div class="box">';
if ($apply) {
    echo '<strong>' . $count['registrado'] . ' interesse(s) registrado(s) agora.</strong> ';
} else {
    echo '<strong>' . $count['registrar'] . ' reação(ões) vão virar interesse.</strong> ';
}
echo $count['ja_existe'] . ' já estavam registradas · ' . $count['nao_captacao'] . ' não são de captação · '
   . $count['encerrada'] . ' em card encerrado · ' . $count['whatsapp_sistema'] . ' do próprio WhatsApp do sistema.';
echo '</div>';
if (!$apply && $count['registrar'] > 0) {
    echo '<form method="post"><input type="hidden" name="nonce" value="' . h($nonce) . '"><input type="hidden" name="dias" value="' . $days . '">';
    echo '<button type="submit">Registrar interesses (' . $count['registrar'] . ')</button></form>';
}
echo '<div class="wrap"><table><tr><th>Quando</th><th>Grupo</th><th>Reação</th><th>Quem</th><th>Card</th><th>Situação</th></tr>';
foreach (array_reverse($rows) as $row) {
    echo '<tr><td>' . h($row['quando']) . '</td><td>' . h($row['grupo']) . '</td><td>' . h($row['emoji']) . '</td><td>' . h($row['quem'])
       . '</td><td>' . h($row['card']) . '</td><td>' . h($row['situacao']) . '</td></tr>';
}
if (!$rows) {
    echo '<tr><td colspan="6">Nenhuma reação em grupo de captação nesse período.</td></tr>';
}
echo '</table></div></body></html>';
