<?php

declare(strict_types=1);

/**
 * "Preencher com IA" do formulário do card: lê o texto colado (e-mail da
 * operadora) e devolve os campos estruturados para o captador CONFERIR antes
 * de salvar. Não grava nada.
 */

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function aip_json(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (auth_user_id() === null) {
    aip_json(['ok' => false, 'error' => 'Sessão expirada. Faça login novamente.']);
}
if (!rbac_user_can((int)auth_user_id(), 'demands.manage')) {
    aip_json(['ok' => false, 'error' => 'Sem permissão.']);
}
// A IA pode levar vários segundos: não travar as outras telas do usuário.
session_release();

$text = trim((string)($_POST['text'] ?? ''));
if (mb_strlen($text) < 20) {
    aip_json(['ok' => false, 'error' => 'Texto muito curto.']);
}
$text = mb_substr($text, 0, 8000);

$specialtyNames = [];
try {
    $specialtyNames = db()->query("SELECT name FROM specialties WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}

$sys = "Você extrai dados de UMA solicitação de atendimento domiciliar (home care) para preencher um formulário.\n"
    . "Responda SOMENTE JSON com as chaves: patient_name, specialty, frequency, urgency, procedure_value, "
    . "location_zip, location_street, location_number, location_complement, location_neighborhood, location_city, "
    . "location_state, ai_summary, origin_email.\n"
    . "Regras:\n"
    . "- Use null quando não houver a informação. Não invente.\n"
    . "- specialty: escolha, se possível, exatamente um nome desta lista: " . implode(' | ', $specialtyNames) . ".\n"
    . "- frequency: um destes códigos: 1x_semana, 2x_semana, 3x_semana, 4x_semana, 5x_semana, 6x_semana, 7x_semana, quinzenal, mensal.\n"
    . "- urgency: urgente, normal ou baixa.\n"
    . "- location_state: UF com 2 letras maiúsculas. location_zip: só dígitos.\n"
    . "- procedure_value: número (sem R$).\n"
    . "- ai_summary: resumo clínico curto (2 a 4 frases) para profissionais, SEM nome do paciente, SEM valores e SEM telefone.";

try {
    $api = new OpenAiApi();
    $res = $api->chatCompletions(
        [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user', 'content' => $text],
        ],
        null,
        ['temperature' => 0.0, 'response_format' => ['type' => 'json_object']]
    );
} catch (Throwable $e) {
    aip_json(['ok' => false, 'error' => 'IA indisponível: ' . $e->getMessage()]);
}

$status = (int)($res['status'] ?? 0);
$raw = is_array($res['json'] ?? null) ? (string)($res['json']['choices'][0]['message']['content'] ?? '') : '';
$parsed = json_decode(trim($raw), true);
if ($status < 200 || $status >= 300 || !is_array($parsed)) {
    aip_json(['ok' => false, 'error' => 'A IA não retornou dados válidos (HTTP ' . $status . ').']);
}

$allowed = ['patient_name', 'specialty', 'frequency', 'urgency', 'procedure_value', 'location_zip', 'location_street',
    'location_number', 'location_complement', 'location_neighborhood', 'location_city', 'location_state', 'ai_summary', 'origin_email'];
$fields = [];
foreach ($allowed as $k) {
    $v = $parsed[$k] ?? null;
    if ($v === null || is_array($v)) {
        continue;
    }
    $v = trim((string)$v);
    if ($v === '' || strtolower($v) === 'null') {
        continue;
    }
    $fields[$k] = $v;
}
if (isset($fields['location_state'])) {
    $fields['location_state'] = strtoupper(mb_substr($fields['location_state'], 0, 2));
}
if (isset($fields['procedure_value'])) {
    $fields['procedure_value'] = str_replace('.', ',', (string)(float)str_replace(',', '.', $fields['procedure_value']));
}
if (isset($fields['urgency']) && $fields['urgency'] === 'normal') {
    $fields['urgency'] = ''; // "Normal" é a opção vazia do select
}
// Zona sugerida (São Paulo/Rio).
$zone = location_zone_for($fields['location_city'] ?? '', $fields['location_state'] ?? '', $fields['location_neighborhood'] ?? '');
if ($zone !== null) {
    $fields['location_zone'] = $zone;
}

aip_json(['ok' => true, 'fields' => $fields]);
