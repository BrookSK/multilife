<?php

declare(strict_types=1);

/**
 * Demand Follow-up Helper — cobrança manual de captação com IA e envio espaçado.
 *
 * Regras (reunião 15/09):
 *  - Só cobra demanda que já foi captada no grupo (tem demand_dispatch_logs 'sent').
 *  - Envio no PRIVADO, mas em lotes pequenos com intervalo (anti-bloqueio).
 *  - Uma mensagem DIFERENTE por profissional, gerada por IA (com fallback).
 *  - A mensagem pergunta se ainda não quer a demanda e pede INDICAÇÃO de contato.
 */

/** Tamanho do lote por execução do cron (padrão 2). */
function demand_followup_batch_size(): int
{
    $v = (int)admin_setting_get('demand_followup.batch_size', '2');
    return $v > 0 ? $v : 2;
}

/** Intervalo mínimo (minutos) entre lotes (padrão 5). */
function demand_followup_interval_minutes(): int
{
    $v = (int)admin_setting_get('demand_followup.interval_minutes', '5');
    return $v > 0 ? $v : 5;
}

/** Delay por mensagem passado à Evolution (ms). */
function demand_followup_per_message_delay_ms(): int
{
    $v = (int)admin_setting_get('demand_followup.per_message_delay_ms', '1200');
    return $v >= 0 ? $v : 1200;
}

/**
 * Indica se a demanda foi realmente captada no grupo (tem disparo enviado).
 */
function demand_followup_demand_was_dispatched(PDO $db, int $demandId): bool
{
    try {
        $stmt = $db->prepare("SELECT 1 FROM demand_dispatch_logs WHERE demand_id = :d AND dispatch_status = 'sent' LIMIT 1");
        $stmt->execute(['d' => $demandId]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Normaliza um telefone para JID do WhatsApp (55DDNUMERO@s.whatsapp.net).
 * Retorna '' se não for um número válido (ex.: LID).
 */
function demand_followup_phone_to_jid(?string $phone): string
{
    $digits = preg_replace('/\D+/', '', (string)$phone);
    if ($digits === '' || strlen($digits) > 13) {
        return '';
    }
    if (strlen($digits) === 10 || strlen($digits) === 11) {
        $digits = '55' . $digits;
    }
    if (strlen($digits) < 12 || strlen($digits) > 13) {
        return '';
    }
    return $digits . '@s.whatsapp.net';
}

/**
 * Lista os profissionais candidatos à cobrança de uma demanda.
 *
 * Fonte: alvos daquele disparo (demand_dispatch_targets, quando houver) +
 * profissionais compatíveis com a especialidade. Marca quem já reagiu
 * (para a atendente saber quem NÃO precisa cobrar) e quem já foi cobrado.
 *
 * @return array<int,array{user_id:?int,name:string,phone:string,phone_jid:string,reacted:bool,already_charged:bool}>
 */
function demand_followup_candidates(PDO $db, int $demandId): array
{
    // Especialidade da demanda.
    $specialty = '';
    try {
        $s = $db->prepare('SELECT specialty FROM demands WHERE id = :d LIMIT 1');
        $s->execute(['d' => $demandId]);
        $specialty = trim((string)($s->fetchColumn() ?: ''));
    } catch (Throwable $e) {}

    // Profissionais compatíveis com a especialidade.
    // Usa o MESMO match progressivo e flexível do disparo (demands_dispatch_whatsapp_post.php):
    //  1) match exato;
    //  2) specialty do profissional contém o termo da demanda;
    //  3) match INVERSO: o termo da demanda contém a specialty do profissional
    //     (ex.: demanda "Fisioterapia Domiciliar" casa com profissional "Fisioterapia");
    //  4) primeira palavra (ex.: "Fisioterapia%" casa com "Fisioterapia Esportiva");
    //  5) case-insensitive pela primeira palavra.
    $rows = [];
    try {
        if ($specialty === '') {
            // Sem especialidade na demanda: lista todos os profissionais com telefone.
            $q = $db->prepare("
                SELECT DISTINCT u.id AS user_id, u.name, u.phone
                FROM users u
                INNER JOIN user_roles ur ON ur.user_id = u.id
                INNER JOIN roles r ON r.id = ur.role_id AND r.slug = 'profissional'
                WHERE u.phone IS NOT NULL AND u.phone <> ''
                ORDER BY u.name ASC
            ");
            $q->execute();
        } else {
            $firstWord = explode(' ', trim($specialty))[0];
            $q = $db->prepare("
                SELECT DISTINCT u.id AS user_id, u.name, u.phone
                FROM users u
                INNER JOIN user_roles ur ON ur.user_id = u.id
                INNER JOIN roles r ON r.id = ur.role_id AND r.slug = 'profissional'
                WHERE (
                    u.specialty = :exact
                    OR u.specialty LIKE :contains
                    OR :inverse LIKE CONCAT('%', u.specialty, '%')
                    OR u.specialty LIKE :firstWord
                    OR LOWER(u.specialty) LIKE LOWER(:firstWordCi)
                )
                AND u.phone IS NOT NULL AND u.phone <> ''
                ORDER BY u.name ASC
            ");
            $q->execute([
                'exact' => $specialty,
                'contains' => '%' . $specialty . '%',
                'inverse' => $specialty,
                'firstWord' => $firstWord . '%',
                'firstWordCi' => '%' . $firstWord . '%',
            ]);
        }
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $rows = [];
    }

    // Quem já reagiu (não precisa cobrar).
    $reacted = [];
    try {
        $r = $db->prepare('SELECT phone, user_id FROM demand_interested_professionals WHERE demand_id = :d');
        $r->execute(['d' => $demandId]);
        foreach ($r->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $suffix = substr(preg_replace('/\D+/', '', (string)$row['phone']), -8);
            if ($suffix !== '') { $reacted[$suffix] = true; }
            if (!empty($row['user_id'])) { $reacted['u' . (int)$row['user_id']] = true; }
        }
    } catch (Throwable $e) {}

    // Quem já foi cobrado nesta demanda (anti-repetição).
    $charged = [];
    try {
        $c = $db->prepare('SELECT phone FROM demand_followup_items WHERE demand_id = :d');
        $c->execute(['d' => $demandId]);
        foreach ($c->fetchAll(PDO::FETCH_COLUMN) as $ph) {
            $suffix = substr(preg_replace('/\D+/', '', (string)$ph), -8);
            if ($suffix !== '') { $charged[$suffix] = true; }
        }
    } catch (Throwable $e) {}

    $out = [];
    foreach ($rows as $row) {
        $jid = demand_followup_phone_to_jid($row['phone'] ?? '');
        if ($jid === '') {
            continue; // sem número válido para privado
        }
        $suffix = substr(preg_replace('/\D+/', '', (string)$row['phone']), -8);
        $out[] = [
            'user_id' => $row['user_id'] !== null ? (int)$row['user_id'] : null,
            'name' => (string)($row['name'] ?? ''),
            'phone' => preg_replace('/\D+/', '', (string)$row['phone']),
            'phone_jid' => $jid,
            'reacted' => isset($reacted[$suffix]) || (isset($row['user_id']) && isset($reacted['u' . (int)$row['user_id']])),
            'already_charged' => isset($charged[$suffix]),
        ];
    }
    return $out;
}

/**
 * Gera a mensagem de cobrança para UM profissional.
 * Tenta a IA (uma variação única); se falhar, usa fallback com pequena variação.
 */
function demand_followup_generate_message(array $demand, string $professionalName): string
{
    $title = trim((string)($demand['title'] ?? 'a demanda'));
    $specialty = trim((string)($demand['specialty'] ?? ''));
    $city = trim((string)($demand['location_city'] ?? ''));
    $firstName = trim((string)preg_split('/\s+/', trim($professionalName))[0] ?? '');
    $greetingName = $firstName !== '' ? $firstName : 'profissional';

    // Tentar IA.
    try {
        $api = new OpenAiApi();
        $sys = 'Você escreve mensagens curtas e cordiais de WhatsApp, em português do Brasil, '
            . 'para uma equipe de captação de profissionais de saúde. A mensagem deve: '
            . '1) cumprimentar pelo primeiro nome; '
            . '2) perguntar, de forma gentil, se o profissional realmente não tem interesse/disponibilidade na demanda; '
            . '3) pedir que, se não puder, indique o contato de outro profissional que possa atender; '
            . '4) ser breve (até ~50 palavras), sem parecer spam, sem links, tom humano e levemente variado. '
            . 'Não use dados sensíveis do paciente. Assine como "Equipe MultiLife".';
        $user = "Profissional: {$greetingName}\n"
            . 'Demanda: ' . $title . "\n"
            . ($specialty !== '' ? 'Especialidade: ' . $specialty . "\n" : '')
            . ($city !== '' ? 'Cidade: ' . $city . "\n" : '')
            . 'Gere UMA mensagem única (variada), pronta para envio.';

        $res = $api->chatCompletions([
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user', 'content' => $user],
        ], null, ['temperature' => 0.9, 'max_tokens' => 200]);

        if (($res['status'] ?? 0) >= 200 && ($res['status'] ?? 0) < 300) {
            $text = trim((string)($res['json']['choices'][0]['message']['content'] ?? ''));
            if ($text !== '') {
                return $text;
            }
        }
    } catch (Throwable $e) {
        // cai no fallback
    }

    // Fallback determinístico com leve variação por nome.
    $variants = [
        "Olá, {$greetingName}! Tudo bem? Passando para saber se você realmente não tem interesse/disponibilidade na demanda \"{$title}\". Se não puder assumir, você conhece algum colega que possa? Pode nos passar o contato. Obrigado! Equipe MultiLife",
        "Oi, {$greetingName}! Sobre a demanda \"{$title}\": ainda dá para você atender? Se não for possível, teria alguém para indicar? É só nos enviar o contato. Agradecemos! Equipe MultiLife",
        "{$greetingName}, tudo certo? Estamos finalizando a captação da demanda \"{$title}\". Você tem interesse? Caso não, poderia indicar outro profissional? Fico no aguardo. Equipe MultiLife",
    ];
    $idx = abs(crc32($greetingName . $title)) % count($variants);
    return $variants[$idx];
}

/**
 * Processa UM lote (até batch_size itens pendentes) de um batch de cobrança:
 * gera a mensagem por IA para cada item e envia no privado, marcando sent/failed.
 *
 * Usado tanto pelo cron (lotes seguintes, espaçados) quanto pelo endpoint de
 * enfileiramento (PRIMEIRO lote, enviado imediatamente ao clicar).
 *
 * @param PDO $db
 * @param int $batchId
 * @return array{sent:int,failed:int,processed:int}
 */
function demand_followup_process_one_batch(PDO $db, int $batchId): array
{
    $result = ['sent' => 0, 'failed' => 0, 'processed' => 0];

    // Carregar o batch.
    $b = $db->prepare('SELECT * FROM demand_followup_batches WHERE id = :id LIMIT 1');
    $b->execute(['id' => $batchId]);
    $batch = $b->fetch(PDO::FETCH_ASSOC);
    if (!$batch) {
        return $result;
    }
    $demandId = (int)$batch['demand_id'];

    // Dados da demanda (para a IA).
    $demand = [];
    try {
        $d = $db->prepare('SELECT id, title, specialty, location_city FROM demands WHERE id = :d LIMIT 1');
        $d->execute(['d' => $demandId]);
        $demand = $d->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}

    $batchSize = demand_followup_batch_size();
    $perMsgDelay = demand_followup_per_message_delay_ms();

    // Próximos itens pendentes.
    $it = $db->prepare("SELECT * FROM demand_followup_items WHERE batch_id = :b AND status = 'pending' ORDER BY id ASC LIMIT :lim");
    $it->bindValue('b', $batchId, PDO::PARAM_INT);
    $it->bindValue('lim', $batchSize, PDO::PARAM_INT);
    $it->execute();
    $items = $it->fetchAll(PDO::FETCH_ASSOC);

    if (empty($items)) {
        $db->prepare("UPDATE demand_followup_batches SET status = 'done' WHERE id = :id")->execute(['id' => $batchId]);
        return $result;
    }

    $db->prepare("UPDATE demand_followup_batches SET status = 'processing' WHERE id = :id")->execute(['id' => $batchId]);

    // API do WhatsApp da instância de quem criou (fallback: padrão).
    $api = null;
    try {
        $api = whatsapp_get_api_for_user((int)($batch['created_by_user_id'] ?? 0) ?: null);
    } catch (Throwable $e) {
        $api = null;
    }

    foreach ($items as $item) {
        $itemId = (int)$item['id'];
        $jid = (string)$item['phone_jid'];
        $name = (string)($item['push_name'] ?? '');
        $result['processed']++;

        if ($api === null) {
            $db->prepare("UPDATE demand_followup_items SET status='failed', attempts=attempts+1, error_message='WhatsApp indisponível' WHERE id=:id")
                ->execute(['id' => $itemId]);
            $db->prepare("UPDATE demand_followup_batches SET failed_items = failed_items + 1 WHERE id=:id")->execute(['id' => $batchId]);
            $result['failed']++;
            continue;
        }

        $message = demand_followup_generate_message($demand, $name);

        try {
            $res = $api->sendText($jid, $message, ['delay' => $perMsgDelay]);
            $ok = isset($res['status']) && (int)$res['status'] >= 200 && (int)$res['status'] < 300;
            if ($ok) {
                $db->prepare("UPDATE demand_followup_items SET status='sent', generated_message=:m, attempts=attempts+1, sent_at=NOW(), error_message=NULL WHERE id=:id")
                    ->execute(['m' => $message, 'id' => $itemId]);
                $db->prepare("UPDATE demand_followup_batches SET sent_items = sent_items + 1 WHERE id=:id")->execute(['id' => $batchId]);
                $result['sent']++;
                try {
                    $db->prepare('INSERT INTO chat_messages (remote_jid, instance_name, message_text, from_me, message_timestamp) VALUES (?, ?, ?, 1, ?)')
                        ->execute([$jid, $batch['instance_name'] ?? null, $message, time()]);
                } catch (Throwable $e) {}
            } else {
                $err = 'HTTP ' . (string)($res['status'] ?? '');
                $db->prepare("UPDATE demand_followup_items SET status='failed', generated_message=:m, attempts=attempts+1, error_message=:e WHERE id=:id")
                    ->execute(['m' => $message, 'e' => $err, 'id' => $itemId]);
                $db->prepare("UPDATE demand_followup_batches SET failed_items = failed_items + 1 WHERE id=:id")->execute(['id' => $batchId]);
                $result['failed']++;
            }
        } catch (Throwable $e) {
            $db->prepare("UPDATE demand_followup_items SET status='failed', attempts=attempts+1, error_message=:e WHERE id=:id")
                ->execute(['e' => mb_strimwidth($e->getMessage(), 0, 240, ''), 'id' => $itemId]);
            $db->prepare("UPDATE demand_followup_batches SET failed_items = failed_items + 1 WHERE id=:id")->execute(['id' => $batchId]);
            $result['failed']++;
        }

        usleep(1500000); // 1.5s entre as mensagens do mesmo lote
    }

    // Marca o horário deste lote (base do intervalo dos próximos) e conclui se acabou.
    $db->prepare("UPDATE demand_followup_batches SET last_batch_sent_at = NOW() WHERE id = :id")->execute(['id' => $batchId]);
    $remaining = (int)$db->query("SELECT COUNT(*) FROM demand_followup_items WHERE batch_id = " . (int)$batchId . " AND status = 'pending'")->fetchColumn();
    if ($remaining === 0) {
        $db->prepare("UPDATE demand_followup_batches SET status = 'done' WHERE id = :id")->execute(['id' => $batchId]);
    }

    return $result;
}
