<?php

declare(strict_types=1);

/**
 * "Ler anexos e completar o card": para cards já criados de e-mails cujos dados
 * (endereço, paciente...) vieram em imagem, PDF ou planilha.
 * Busca os anexos (no IMAP, se preciso), lê com a IA e preenche SÓ os campos
 * vazios do card. Nunca sobrescreve o que a equipe já preencheu.
 */

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

demand_form_ensure_schema();
location_users_ensure_schema();
inbound_attachments_ensure_schema();

$id = (int)($_POST['id'] ?? 0);
$back = '/demands_view.php?id=' . $id;
$db = db();

$st = $db->prepare('SELECT * FROM demands WHERE id = :id LIMIT 1');
$st->execute(['id' => $id]);
$d = $st->fetch(PDO::FETCH_ASSOC);
if (!$d) {
    flash_set('error', 'Card não encontrado.');
    header('Location: /demands_list.php');
    exit;
}

$emailId = (int)($d['source_email_id'] ?? 0);
if ($emailId <= 0) {
    $q = $db->prepare('SELECT id FROM inbound_emails WHERE linked_demand_id = :d ORDER BY id ASC LIMIT 1');
    $q->execute(['d' => $id]);
    $emailId = (int)($q->fetchColumn() ?: 0);
}
if ($emailId <= 0) {
    flash_set('error', 'Este card não veio de um e-mail: não há anexos para ler.');
    header('Location: ' . $back);
    exit;
}

$finish = static function (string $type, string $msg) use ($back): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    flash_set($type, $msg);
    header('Location: ' . $back);
    exit;
};

// A leitura pode levar até alguns minutos (uma chamada da IA por imagem/PDF):
// libera a sessão para o usuário continuar usando o sistema em outra aba.
@set_time_limit(300);
ignore_user_abort(true);
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

try {
    $api = new OpenAiApi();
} catch (Throwable $e) {
    $finish('error', 'IA (OpenAI) não configurada: ' . $e->getMessage());
}

// Botão = pedido explícito: busca de novo no IMAP (não duplica o que já foi
// guardado e pega o que uma busca anterior não reconheceu).
$hadAtts = (bool)inbound_attachments_list($emailId);
$fetch = inbound_attachments_fetch_for_email($emailId);
if (!$hadAtts && !$fetch['ok'] && $fetch['saved'] === 0) {
    $finish('error', 'Não consegui buscar os anexos: ' . $fetch['message']);
}
$attText = inbound_attachments_extracted_text($emailId, $api, true);
$atts = inbound_attachments_list($emailId);
if ($attText === '') {
    $finish('error', $atts
        ? 'Os ' . count($atts) . ' anexo(s) foram verificados, mas nenhum tinha dados legíveis. Veja o motivo em "Anexos do e-mail" no card.'
        : 'Este e-mail não tem anexos nem imagens.');
}

$em = $db->prepare('SELECT subject, body_text, body_html FROM inbound_emails WHERE id = :id');
$em->execute(['id' => $emailId]);
$emailRow = $em->fetch(PDO::FETCH_ASSOC) ?: [];
$body = trim((string)($emailRow['body_text'] ?? ''));
if ($body === '') {
    $body = trim(strip_tags((string)($emailRow['body_html'] ?? '')));
}

$fields = ['patient_name', 'location_zip', 'location_street', 'location_number', 'location_complement',
    'location_neighborhood', 'location_city', 'location_state', 'specialty', 'frequency', 'urgency', 'ai_summary'];
$current = [];
foreach ($fields as $f) {
    $current[$f] = trim((string)($d[$f] ?? ''));
}

$system = "Você completa o card de uma solicitação de atendimento domiciliar (home care) a partir do e-mail e dos anexos/imagens.\n"
    . "Responda SOMENTE JSON com as chaves: patient_name, location_zip (8 dígitos), location_street, location_number, "
    . "location_complement, location_neighborhood, location_city, location_state (UF 2 letras), specialty, "
    . "frequency (códigos: 1x_semana..7x_semana, quinzenal, mensal, avaliacao, pontual), urgency (urgente|normal|baixa), "
    . "ai_summary (quadro clínico em até 3 parágrafos curtos: paciente/diagnóstico, serviço/frequência, observações), "
    . "other_patients (lista com o NOME de cada outro paciente que aparece no e-mail ou nos anexos e NÃO é o paciente deste card).\n"
    . "Regras: use null quando o dado não aparecer. Não invente. Se o e-mail tiver vários pacientes (ex.: um no texto e outro "
    . "na imagem), use SOMENTE os dados do paciente deste card (veja 'CARD ATUAL'; se o nome estiver vazio, o do corpo do e-mail) "
    . "e NUNCA misture endereço, convênio ou serviços de outro paciente. Ignore logotipos e assinaturas.";
$user = "CARD ATUAL (campos vazios precisam ser completados):\n" . json_encode($current, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    . "\n\nASSUNTO: " . (string)($emailRow['subject'] ?? '')
    . "\n\nCORPO DO E-MAIL:\n" . mb_substr($body, 0, 8000)
    . "\n\n" . $attText;

$res = $api->chatCompletions(
    [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
    null,
    ['temperature' => 0.1, 'response_format' => ['type' => 'json_object']]
);
$status = (int)($res['status'] ?? 0);
$parsed = $status >= 200 && $status < 300
    ? json_decode(trim((string)($res['json']['choices'][0]['message']['content'] ?? '')), true)
    : null;
if (!is_array($parsed)) {
    $finish('error', 'A IA não conseguiu interpretar os anexos (HTTP ' . $status . '). Tente de novo em instantes.');
}

// Outros pacientes do e-mail (ex.: um no texto e outro na imagem) que ainda não
// têm card vindo deste e-mail: o aviso orienta a reprocessar.
$otherPatientsWithoutCard = [];
$others = is_array($parsed['other_patients'] ?? null) ? $parsed['other_patients'] : [];
if ($others) {
    $haveKeys = [inbound_patient_name_key((string)($d['patient_name'] ?? '')) => true];
    try {
        $ex = $db->prepare("SELECT patient_name FROM demands WHERE (source_email_id = :e OR id = :d) AND status <> 'cancelado'");
        $ex->execute(['e' => $emailId, 'd' => $id]);
        foreach ($ex->fetchAll(PDO::FETCH_COLUMN) as $pn) {
            $haveKeys[inbound_patient_name_key((string)$pn)] = true;
        }
    } catch (Throwable $e) {}
    foreach ($others as $o) {
        $o = is_scalar($o) ? trim((string)$o) : '';
        $k = inbound_patient_name_key($o);
        if ($k !== '' && !isset($haveKeys[$k])) {
            $otherPatientsWithoutCard[] = mb_substr($o, 0, 80);
            $haveKeys[$k] = true;
        }
    }
}

// Normaliza o que a IA devolveu.
$found = [];
foreach ($fields as $f) {
    $v = $parsed[$f] ?? null;
    if (!is_scalar($v)) {
        continue;
    }
    $v = trim((string)$v);
    if ($v === '' || strtolower($v) === 'null') {
        continue;
    }
    $found[$f] = $v;
}
if (isset($found['location_zip'])) {
    $found['location_zip'] = preg_replace('/\D+/', '', $found['location_zip']) ?? '';
    if (strlen($found['location_zip']) !== 8) {
        unset($found['location_zip']);
    }
}
if (isset($found['location_state'])) {
    $found['location_state'] = strtoupper($found['location_state']);
    if (!preg_match('/^[A-Z]{2}$/', $found['location_state'])) {
        unset($found['location_state']);
    }
}
if (isset($found['frequency'])) {
    $norm = isset(FREQUENCY_WEEKDAYS_MAP[$found['frequency']]) ? $found['frequency'] : frequency_normalize($found['frequency']);
    if ($norm !== '') {
        $found['frequency'] = $norm;
    } else {
        unset($found['frequency']);
    }
}
if (isset($found['urgency']) && !in_array($found['urgency'], ['urgente', 'normal', 'baixa'], true)) {
    unset($found['urgency']);
}
$maxLen = ['patient_name' => 200, 'location_street' => 200, 'location_number' => 20, 'location_complement' => 120,
    'location_neighborhood' => 120, 'location_city' => 120, 'specialty' => 120, 'frequency' => 120];
foreach ($maxLen as $f => $max) {
    if (isset($found[$f])) {
        $found[$f] = mb_substr($found[$f], 0, $max);
    }
}

// CEP: completa rua/bairro/cidade/UF que continuarem vazios.
$zipForLookup = $current['location_zip'] !== '' ? $current['location_zip'] : ($found['location_zip'] ?? '');
if ($zipForLookup !== '') {
    $cep = inbound_cep_lookup($zipForLookup);
    if ($cep) {
        foreach (['location_street' => 'street', 'location_neighborhood' => 'neighborhood', 'location_city' => 'city', 'location_state' => 'state'] as $f => $k) {
            if ($current[$f] === '' && empty($found[$f]) && $cep[$k] !== '') {
                $found[$f] = $cep[$k];
            }
        }
    }
}

// Só os campos VAZIOS do card, e só colunas que existem.
$cols = demand_form_columns();
$labels = demand_form_field_labels();
$updates = [];
foreach ($found as $f => $v) {
    if (isset($cols[$f]) && $current[$f] === '') {
        $updates[$f] = $v;
    }
}

$merged = array_merge($d, $updates);
// Zona (cidades divididas por zona) pelo bairro.
if (isset($cols['location_zone']) && trim((string)($d['location_zone'] ?? '')) === ''
    && location_city_has_zones((string)$merged['location_city'], (string)$merged['location_state'])) {
    $zone = location_zone_for((string)$merged['location_city'], (string)$merged['location_state'], (string)($merged['location_neighborhood'] ?? ''));
    if ($zone !== null) {
        $updates['location_zone'] = $zone;
        $merged['location_zone'] = $zone;
    }
}
// Título genérico ("Demanda recebida por e-mail" / assunto) passa a ter o paciente.
if (!empty($updates['patient_name']) && trim((string)$d['patient_name']) === '') {
    $updates['title'] = mb_strimwidth($updates['patient_name'] . ' - ' . (trim((string)$merged['specialty']) !== '' ? $merged['specialty'] : 'Atendimento'), 0, 200, '');
}
// Texto lido dos anexos fica no card (uma vez).
$desc = (string)($d['description'] ?? '');
if (isset($cols['description']) && !str_contains($desc, '=== TEXTO LIDO DOS ANEXOS')) {
    $updates['description'] = trim($desc . "\n\n" . $attText);
}

$oldStatus = (string)$d['status'];
$missing = demand_missing_fields($merged);
$newStatus = ($oldStatus === 'tratamento_manual' && !$missing['blocking']) ? 'aguardando_captacao' : null;

$filled = array_values(array_intersect_key($labels, array_diff_key($updates, ['description' => 1, 'title' => 1])));
if (!$filled && !isset($updates['description'])) {
    $finish('success', 'Anexos lidos, mas não havia campo vazio que eles pudessem completar.'
        . ($otherPatientsWithoutCard ? ' O e-mail também tem ' . implode(', ', $otherPatientsWithoutCard)
            . ', que ainda não tem card: use "Reprocessar" no e-mail para criar (os cards que já existem não são duplicados).' : ''));
}

$db->beginTransaction();
try {
    if ($updates) {
        $sets = [];
        $params = ['__id' => $id];
        foreach ($updates as $f => $v) {
            $sets[] = $f . ' = :' . $f;
            $params[$f] = $v;
        }
        if ($newStatus !== null) {
            $sets[] = 'status = :__st';
            $params['__st'] = $newStatus;
        }
        $db->prepare('UPDATE demands SET ' . implode(', ', $sets) . ' WHERE id = :__id')->execute($params);
    }
    $note = $filled ? 'completado pelos anexos do e-mail: ' . implode(', ', $filled) : 'texto dos anexos do e-mail incluído';
    $db->prepare('INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (:did, :os, :ns, :uid, :note)')
        ->execute([
            'did' => $id, 'os' => $oldStatus, 'ns' => $newStatus ?? $oldStatus, 'uid' => auth_user_id(),
            'note' => mb_strimwidth($note . ($newStatus !== null ? ' — liberado para captação' : ''), 0, 255, '…'),
        ]);
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[DEMAND_FILL_ATT] #' . $id . ': ' . $e->getMessage());
    $finish('error', 'Erro ao salvar o card: ' . $e->getMessage());
}

$auditNew = $updates;
unset($auditNew['description']);
audit_log('update', 'demands', (string)$id, array_intersect_key($d, $auditNew), $auditNew + ['_origem' => 'anexos do e-mail #' . $emailId]);
page_history_log('/demands_view.php?id=' . $id, 'Demanda', 'update', 'Completou o card com os anexos do e-mail', 'demand', $id);

$msg = $filled ? 'Card completado com os anexos: ' . implode(', ', $filled) . '.' : 'Texto dos anexos incluído no card.';
$otherMsg = $otherPatientsWithoutCard
    ? ' O e-mail também tem ' . implode(', ', $otherPatientsWithoutCard) . ', que ainda não tem card: use "Reprocessar" no e-mail para criar (os cards que já existem não são duplicados).'
    : '';
$msg .= $otherMsg;
if ($newStatus !== null) {
    $msg .= ' O card foi liberado para captação.';
} elseif ($missing['blocking']) {
    $msg .= ' Ainda falta: ' . implode(', ', $missing['blocking']) . '.';
}
$finish('success', $msg);
