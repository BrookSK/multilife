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
foreach ($reactions as &$rx) {
    $rx['fonte'] = 'sistema';
}
unset($rx);

// 2a fonte: reações direto no WhatsApp (Evolution) dos grupos que receberam captação no
// período. Pega as reações que o sistema não recebeu (ex.: WhatsApp com "Suspeita de
// queda": a reação acontece, mas o aviso não chega ao sistema).
$seenReaction = [];
foreach ($reactions as $rx) {
    $seenReaction[$rx['message_id'] . '|' . substr(preg_replace('/\D+/', '', (string)$rx['reactor_jid']), -8)] = true;
}
$evoGroups = db()->prepare("SELECT DISTINCT wg.evolution_group_jid, wg.instance_name, wg.name
                            FROM demand_dispatch_logs dl
                            INNER JOIN whatsapp_groups wg ON wg.id = dl.group_id
                            WHERE dl.created_at >= DATE_SUB(NOW(), INTERVAL :dias2 DAY)
                              AND wg.evolution_group_jid LIKE '%@g.us'");
$evoGroups->execute(['dias2' => $days]);
$evoInfo = ['grupos' => 0, 'sem_resposta' => 0, 'reacoes' => 0];
$evoBase = (string)admin_setting_get('evolution.base_url', '');
$evoKey = (string)admin_setting_get('evolution.api_key', '');
$sinceTs = time() - $days * 86400;
foreach ($evoGroups->fetchAll(PDO::FETCH_ASSOC) as $g) {
    $evoInfo['grupos']++;
    $gJid = (string)$g['evolution_group_jid'];
    // Instância dona do grupo primeiro; se não responder, as outras conectadas.
    $names = array_values(array_unique(array_filter(array_merge(
        [(string)($g['instance_name'] ?? '')],
        db()->query("SELECT instance_name FROM whatsapp_instances WHERE status = 'active' AND connection_status = 'connected'")->fetchAll(PDO::FETCH_COLUMN)
    ))));
    $records = null;
    $usedInst = '';
    foreach ($names as $inst) {
        try {
            $res = (new EvolutionApiV1($evoBase, $evoKey, (string)$inst))->findRecentMessages($gJid, 200, 10);
        } catch (Throwable $e) {
            continue;
        }
        $j = is_array($res['json'] ?? null) ? $res['json'] : [];
        $recs = $j['messages']['records'] ?? ($j['records'] ?? (isset($j[0]) ? $j : null));
        if (is_array($recs) && (int)($res['status'] ?? 0) < 300) {
            $records = $recs;
            $usedInst = (string)$inst;
            break;
        }
    }
    if ($records === null) {
        $evoInfo['sem_resposta']++;
        continue;
    }
    foreach ($records as $rec) {
        $react = $rec['message']['reactionMessage'] ?? null;
        if (!is_array($react) || !empty($rec['key']['fromMe'])) {
            continue;
        }
        $emoji = (string)($react['text'] ?? '');
        $targetId = (string)($react['key']['id'] ?? '');
        $ts = (int)($rec['messageTimestamp'] ?? 0);
        if ($emoji === '' || $targetId === '' || ($ts > 0 && $ts < $sinceTs)) {
            continue;
        }
        $k = $rec['key'] ?? [];
        $reactor = (string)($k['participantPn'] ?? ($k['participantAlt'] ?? ($k['participant'] ?? '')));
        if ($reactor === '') {
            continue;
        }
        $seenKey = $targetId . '|' . substr(preg_replace('/\D+/', '', $reactor), -8);
        if (isset($seenReaction[$seenKey])) {
            continue;
        }
        $seenReaction[$seenKey] = true;
        $evoInfo['reacoes']++;
        $reactions[] = ['remote_jid' => $gJid, 'message_id' => $targetId, 'reactor_jid' => $reactor, 'emoji' => $emoji,
                        'reaction_timestamp' => $ts > 0 ? $ts : time(), 'instance_name' => $usedInst,
                        'group_name' => (string)$g['name'], 'fonte' => 'WhatsApp', 'push_name' => (string)($rec['pushName'] ?? '')];
    }
}

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
    $row = ['ts' => (int)$r['reaction_timestamp'], 'quando' => date('d/m H:i', (int)$r['reaction_timestamp']), 'grupo' => (string)$r['group_name'],
            'emoji' => (string)$r['emoji'], 'quem' => $phone, 'card' => '', 'situacao' => '', 'fonte' => (string)$r['fonte']];

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
            if (!$userName && trim((string)($r['push_name'] ?? '')) !== '') {
                $userName = trim((string)$r['push_name']);
            }
            if ($userName) {
                $row['quem'] = $userName;
            }
            if ($apply) {
                if ($r['fonte'] === 'WhatsApp') {
                    // Também no chat do sistema (a reação aparece na mensagem)
                    try {
                        db()->prepare("INSERT INTO chat_reactions (remote_jid, message_id, reactor_jid, emoji, reaction_timestamp)
                                       VALUES (?, ?, ?, ?, ?)
                                       ON DUPLICATE KEY UPDATE emoji = VALUES(emoji), reaction_timestamp = VALUES(reaction_timestamp)")
                            ->execute([(string)$r['remote_jid'], $mid, $reactor, (string)$r['emoji'], (int)$r['reaction_timestamp']]);
                    } catch (Throwable $e) { /* o interesse segue */ }
                }
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
echo '<br><small>Conferido direto no WhatsApp: ' . $evoInfo['grupos'] . ' grupo(s) com captação no período, '
   . $evoInfo['reacoes'] . ' reação(ões) que o sistema não tinha recebido'
   . ($evoInfo['sem_resposta'] > 0 ? ', ' . $evoInfo['sem_resposta'] . ' grupo(s) sem resposta do WhatsApp' : '') . '.</small>';
echo '</div>';
if (!$apply && $count['registrar'] > 0) {
    echo '<form method="post"><input type="hidden" name="nonce" value="' . h($nonce) . '"><input type="hidden" name="dias" value="' . $days . '">';
    echo '<button type="submit">Registrar interesses (' . $count['registrar'] . ')</button></form>';
}
echo '<div class="wrap"><table><tr><th>Quando</th><th>Grupo</th><th>Reação</th><th>Quem</th><th>Card</th><th>Situação</th><th>Veio de</th></tr>';
usort($rows, static fn($a, $b) => $b['ts'] <=> $a['ts']);
foreach ($rows as $row) {
    echo '<tr><td>' . h($row['quando']) . '</td><td>' . h($row['grupo']) . '</td><td>' . h($row['emoji']) . '</td><td>' . h($row['quem'])
       . '</td><td>' . h($row['card']) . '</td><td>' . h($row['situacao']) . '</td><td>' . h($row['fonte']) . '</td></tr>';
}
if (!$rows) {
    echo '<tr><td colspan="7">Nenhuma reação em grupo de captação nesse período.</td></tr>';
}
echo '</table></div></body></html>';
