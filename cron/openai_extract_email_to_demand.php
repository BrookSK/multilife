<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
if ($limit <= 0 || $limit > 100) {
    $limit = 10;
}

$db = db();

$db->beginTransaction();
try {
    $stmt = $db->prepare(
        "SELECT * FROM inbound_emails\n"
        . "WHERE status IN ('received','ai_pending')\n"
        . "ORDER BY received_at ASC, id ASC\n"
        . "LIMIT $limit\n"
        . "FOR UPDATE"
    );
    $stmt->execute();
    $emails = $stmt->fetchAll();

    if (count($emails) === 0) {
        $db->commit();
        echo "OK: no inbound_emails\n";
        exit;
    }

    $markPending = $db->prepare("UPDATE inbound_emails SET status = 'ai_pending' WHERE id = :id");
    foreach ($emails as $e) {
        $markPending->execute(['id' => (int)$e['id']]);
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

$api = new OpenAiApi();

$updOk = $db->prepare(
    "UPDATE inbound_emails\n"
    . "SET status = :st, linked_demand_id = :did, processed_at = :pa, error_message = NULL\n"
    . "WHERE id = :id"
);
$updErr = $db->prepare(
    "UPDATE inbound_emails\n"
    . "SET status = 'error', error_message = :err, processed_at = :pa\n"
    . "WHERE id = :id"
);

$insDemand = $db->prepare(
    'INSERT INTO demands (title, location_city, location_state, specialty, description, origin_email, status)'
    . ' VALUES (:t,:c,:s,:sp,:d,:o,:st)'
);
$insDemandLog = $db->prepare(
    'INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note)'
    . ' VALUES (:did, NULL, :ns, NULL, :note)'
);

$ok = 0;
$manual = 0;
$errors = 0;

foreach ($emails as $e) {
    $id = (int)$e['id'];
    $subject = (string)($e['subject'] ?? '');
    $fromEmail = (string)($e['from_email'] ?? '');
    $bodyText = (string)($e['body_text'] ?? '');
    $bodyHtml = (string)($e['body_html'] ?? '');

    $content = trim($bodyText);
    if ($content === '' && $bodyHtml !== '') {
        $content = trim(strip_tags($bodyHtml));
    }

    if ($content === '') {
        $errors++;
        $updErr->execute([
            'err' => 'E-mail sem corpo (body_text/body_html vazio).',
            'pa' => date('Y-m-d H:i:s'),
            'id' => $id,
        ]);
        continue;
    }

    $systemPrompt = (string)admin_setting_get(
        'openai.extract_prompt',
        "Você é um assistente que extrai dados de solicitações de atendimento domiciliar (home care) a partir de e-mails.\n"
        . "Retorne SOMENTE um JSON válido no seguinte formato:\n"
        . "{\"title\":string,\"location_city\":string|null,\"location_state\":string|null,\"specialty\":string|null,\"description\":string|null,\"origin\":string|null}\n"
        . "Regras: UF sempre com 2 letras maiúsculas quando existir.\n"
        . "Se não conseguir identificar um campo, use null."
    );

    $userPrompt = "ASSUNTO: " . $subject . "\n" . "REMETENTE: " . $fromEmail . "\n\n" . $content;

    try {
        $res = $api->chatCompletions(
            [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            null,
            ['temperature' => 0.2]
        );

        $json = $res['json'] ?? null;
        $raw = '';
        if (is_array($json)) {
            $raw = (string)($json['choices'][0]['message']['content'] ?? '');
        }
        $raw = trim($raw);

        if ($raw === '') {
            throw new RuntimeException('OpenAI retornou vazio.');
        }

        $parsed = json_decode($raw, true);
        if (!is_array($parsed)) {
            throw new RuntimeException('OpenAI não retornou JSON válido. Conteúdo: ' . mb_strimwidth($raw, 0, 180, '')); 
        }

        $title = trim((string)($parsed['title'] ?? ''));
        $city = trim((string)($parsed['location_city'] ?? ''));
        $state = strtoupper(trim((string)($parsed['location_state'] ?? '')));
        $specialty = trim((string)($parsed['specialty'] ?? ''));
        $desc = trim((string)($parsed['description'] ?? ''));
        $origin = trim((string)($parsed['origin'] ?? ''));

        if ($title === '') {
            $title = $subject !== '' ? $subject : 'Demanda recebida por e-mail';
        }

        if ($state !== '' && !preg_match('/^[A-Z]{2}$/', $state)) {
            $state = '';
        }

        $status = 'aguardando_captacao';
        $needsManual = ($city === '' || $state === '' || $specialty === '');
        if ($needsManual) {
            $status = 'tratamento_manual';
        }

        $db->beginTransaction();
        try {
            $insDemand->execute([
                't' => $title,
                'c' => $city !== '' ? $city : null,
                's' => $state !== '' ? $state : null,
                'sp' => $specialty !== '' ? $specialty : null,
                'd' => $desc !== '' ? $desc : null,
                'o' => $fromEmail !== '' ? $fromEmail : ($origin !== '' ? $origin : null),
                'st' => $status,
            ]);
            $demandId = (int)$db->lastInsertId();

            $note = 'criação automática via e-mail';
            if ($needsManual) {
                $missing = [];
                if ($city === '' || $state === '') {
                    $missing[] = 'localização';
                }
                if ($specialty === '') {
                    $missing[] = 'especialidade';
                }
                if (count($missing) > 0) {
                    $note .= ' (tratamento_manual: faltando ' . implode(', ', $missing) . ')';
                }
            }

            $insDemandLog->execute([
                'did' => $demandId,
                'ns' => $status,
                'note' => $note,
            ]);

            $updOk->execute([
                'st' => 'ai_processed',
                'did' => $demandId,
                'pa' => date('Y-m-d H:i:s'),
                'id' => $id,
            ]);

            $db->commit();
        } catch (Throwable $e2) {
            $db->rollBack();
            throw $e2;
        }

        if ($needsManual) {
            $manual++;
        } else {
            $ok++;
        }
    } catch (Throwable $ex) {
        $errors++;
        $updErr->execute([
            'err' => mb_strimwidth($ex->getMessage(), 0, 255, ''),
            'pa' => date('Y-m-d H:i:s'),
            'id' => $id,
        ]);
        continue;
    }
}

echo 'OK: created=' . ($ok + $manual) . ' manual=' . $manual . ' errors=' . $errors . "\n";
