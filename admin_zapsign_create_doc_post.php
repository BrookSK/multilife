<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('zapsign.manage');

$name = trim((string)($_POST['name'] ?? ''));
$lang = trim((string)($_POST['lang'] ?? 'pt-br'));
$urlPdf = trim((string)($_POST['url_pdf'] ?? ''));
$urlDocx = trim((string)($_POST['url_docx'] ?? ''));
$markdown = trim((string)($_POST['markdown_text'] ?? ''));
$disableEmails = (string)($_POST['disable_signer_emails'] ?? '') === '1';

$s1Name = trim((string)($_POST['signer1_name'] ?? ''));
$s1Email = trim((string)($_POST['signer1_email'] ?? ''));
$s2Name = trim((string)($_POST['signer2_name'] ?? ''));
$s2Email = trim((string)($_POST['signer2_email'] ?? ''));

if ($name === '' || $s1Name === '' || $s1Email === '') {
    flash_set('error', 'Preencha nome do documento e signer 1.');
    header('Location: /admin_zapsign_console.php');
    exit;
}

if (!filter_var($s1Email, FILTER_VALIDATE_EMAIL)) {
    flash_set('error', 'E-mail do signer 1 inválido.');
    header('Location: /admin_zapsign_console.php');
    exit;
}

if ($s2Email !== '' && !filter_var($s2Email, FILTER_VALIDATE_EMAIL)) {
    flash_set('error', 'E-mail do signer 2 inválido.');
    header('Location: /admin_zapsign_console.php');
    exit;
}

if ($urlPdf === '' && $urlDocx === '' && $markdown === '') {
    flash_set('error', 'Informe url_pdf ou url_docx ou markdown_text.');
    header('Location: /admin_zapsign_console.php');
    exit;
}

$payload = [
    'name' => $name,
    'lang' => $lang !== '' ? $lang : 'pt-br',
    'signers' => [
        [
            'name' => $s1Name,
            'email' => $s1Email,
        ],
    ],
];

if ($disableEmails) {
    $payload['disable_signer_emails'] = true;
}

if ($s2Name !== '' && $s2Email !== '') {
    $payload['signers'][] = [
        'name' => $s2Name,
        'email' => $s2Email,
    ];
}

if ($urlPdf !== '') {
    $payload['url_pdf'] = $urlPdf;
}
if ($urlDocx !== '') {
    $payload['url_docx'] = $urlDocx;
}
if ($markdown !== '') {
    $payload['markdown_text'] = $markdown;
}

$api = new ZapSignApi();
$res = $api->createDoc($payload);

$docToken = '';
if (is_array($res['json'])) {
    $docToken = (string)($res['json']['token'] ?? '');
}

view_header('ZapSign Resultado');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="font-size:22px;font-weight:800">Documento criado</div>';
echo '<div style="margin-top:8px;color:rgba(234,240,255,.72);font-size:14px;line-height:1.6"><strong>HTTP:</strong> ' . h((string)($res['status'] ?? '')) . '</div>';
if ($docToken !== '') {
    echo '<div class="pill" style="display:block;margin-top:12px;padding:12px">';
    echo '<strong>doc_token:</strong> ' . h($docToken);
    echo '<div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap">';
    echo '<form method="post" action="/admin_zapsign_detail_doc_post.php" style="display:inline">';
    echo '<input type="hidden" name="doc_token" value="' . h($docToken) . '">';
    echo '<button class="btn" type="submit">Detalhar agora</button>';
    echo '</form>';
    echo '<a class="btn" href="/tech_logs_list.php?provider=zapsign">Ver Logs TI</a>';
    echo '<a class="btn" href="/admin_zapsign_console.php">Voltar</a>';
    echo '</div>';
    echo '</div>';
} else {
    echo '<div style="margin-top:12px"><a class="btn" href="/admin_zapsign_console.php">Voltar</a> <a class="btn" href="/tech_logs_list.php?provider=zapsign">Ver Logs TI</a></div>';
}

echo '</section>';

echo '<section class="card col12">';
echo '<div style="font-weight:800;margin-bottom:8px">Resposta (raw)</div>';
echo '<pre style="white-space:pre-wrap;background:rgba(10,14,28,.35);border:1px solid rgba(255,255,255,.12);border-radius:14px;padding:12px;overflow:auto">' . h((string)($res['body_raw'] ?? '')) . '</pre>';
echo '</section>';

echo '</div>';

view_footer();
