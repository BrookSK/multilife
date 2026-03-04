<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('openai.manage');

$model = trim((string)($_POST['model'] ?? ''));
$temperature = (float)($_POST['temperature'] ?? 0.2);
$system = trim((string)($_POST['system'] ?? ''));
$user = trim((string)($_POST['user'] ?? ''));
$emailText = trim((string)($_POST['email_text'] ?? ''));
$forceJson = (string)($_POST['force_json'] ?? '') === '1';
$jsonSchema = trim((string)($_POST['json_schema'] ?? ''));

if ($model === '') {
    $model = admin_setting_get('openai.model', 'gpt-4o-mini') ?? 'gpt-4o-mini';
}

$messages = [];
if ($system !== '') {
    $messages[] = ['role' => 'system', 'content' => $system];
}

$mode = 'chat';

if ($emailText !== '') {
    $mode = 'email_extract';

    $schemaTxt = '';
    if ($jsonSchema !== '') {
        $schemaTxt = "\n\nSchema esperado (exemplo):\n" . $jsonSchema;
    }

    $messages[] = [
        'role' => 'system',
        'content' => 'Você é um extrator de dados. Retorne APENAS JSON válido, sem markdown e sem texto extra.'
    ];

    $messages[] = [
        'role' => 'user',
        'content' => "Extraia os campos estruturados do e-mail abaixo e retorne JSON." . $schemaTxt . "\n\nEMAIL:\n" . $emailText,
    ];
} else {
    if ($user === '') {
        flash_set('error', 'Informe a mensagem do usuário ou cole um e-mail para extração.');
        header('Location: /admin_openai_console.php');
        exit;
    }

    $messages[] = ['role' => 'user', 'content' => $user];
}

$extra = [
    'temperature' => max(0, min(2, $temperature)),
];

// Se for extração, tentamos pedir response_format json_object (quando suportado)
if ($mode === 'email_extract' && $forceJson) {
    $extra['response_format'] = ['type' => 'json_object'];
}

$api = new OpenAiApi();

try {
    $res = $api->chatCompletions($messages, $model, $extra);
} catch (Throwable $e) {
    flash_set('error', 'Erro ao chamar OpenAI: ' . $e->getMessage());
    header('Location: /admin_openai_console.php');
    exit;
}

$textOut = '';
$jsonOut = null;

if (is_array($res['json'])) {
    $choice = $res['json']['choices'][0]['message']['content'] ?? '';
    $textOut = is_string($choice) ? $choice : '';

    $try = trim($textOut);
    if ($try !== '' && (str_starts_with($try, '{') || str_starts_with($try, '['))) {
        $jsonOut = json_decode($try, true);
    }
}

view_header('OpenAI Resultado');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="font-size:22px;font-weight:800">Resultado</div>';
echo '<div style="margin-top:8px;color:rgba(234,240,255,.72);font-size:14px;line-height:1.6">';
echo '<strong>Modo:</strong> ' . h($mode) . ' &nbsp; <strong>HTTP:</strong> ' . h((string)($res['status'] ?? ''));
echo '</div>';

echo '<div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/admin_openai_console.php">Voltar</a>';
echo '<a class="btn" href="/tech_logs_list.php?provider=openai">Ver Logs TI</a>';
echo '</div>';

echo '</section>';

if ($jsonOut !== null) {
    $pretty = json_encode($jsonOut, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    echo '<section class="card col12">';
    echo '<div style="font-weight:800;margin-bottom:8px">JSON (parseado)</div>';
    echo '<pre style="white-space:pre-wrap;background:rgba(10,14,28,.35);border:1px solid rgba(255,255,255,.12);border-radius:14px;padding:12px;overflow:auto">' . h((string)$pretty) . '</pre>';
    echo '</section>';
}

echo '<section class="card col12">';
echo '<div style="font-weight:800;margin-bottom:8px">Resposta (texto)</div>';
echo '<pre style="white-space:pre-wrap;background:rgba(10,14,28,.35);border:1px solid rgba(255,255,255,.12);border-radius:14px;padding:12px;overflow:auto">' . h($textOut) . '</pre>';
echo '</section>';

echo '<section class="card col12">';
echo '<div style="font-weight:800;margin-bottom:8px">Resposta (raw)</div>';
echo '<pre style="white-space:pre-wrap;background:rgba(10,14,28,.35);border:1px solid rgba(255,255,255,.12);border-radius:14px;padding:12px;overflow:auto">' . h((string)($res['body_raw'] ?? '')) . '</pre>';
echo '</section>';

echo '</div>';

view_footer();
