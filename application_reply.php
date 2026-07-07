<?php
/**
 * Página pública para candidato responder solicitação de complemento.
 * Acesso via token único (sem login).
 */

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$token = trim((string)($_GET['token'] ?? ''));

if ($token === '' || strlen($token) < 32) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Link inválido</title></head><body style="font-family:sans-serif;text-align:center;padding:60px"><h2>Link inválido ou expirado</h2><p>Verifique o link recebido por WhatsApp/e-mail.</p></body></html>';
    exit;
}

$stmt = db()->prepare('SELECT id, full_name, status, admin_note, reply_token_created_at FROM professional_applications WHERE reply_token = :token');
$stmt->execute(['token' => $token]);
$app = $stmt->fetch();

if (!$app) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Link inválido</title></head><body style="font-family:sans-serif;text-align:center;padding:60px"><h2>Link inválido ou expirado</h2><p>Verifique o link recebido por WhatsApp/e-mail.</p></body></html>';
    exit;
}

$appId = (int)$app['id'];
$name = (string)$app['full_name'];
$note = (string)($app['admin_note'] ?? '');

// Buscar respostas já enviadas
$repliesStmt = db()->prepare('SELECT * FROM professional_application_replies WHERE application_id = :id ORDER BY created_at ASC');
$repliesStmt->execute(['id' => $appId]);
$replies = $repliesStmt->fetchAll();

$successMsg = '';
if (isset($_GET['sent'])) {
    $successMsg = 'Resposta enviada com sucesso! Você pode enviar mais itens se necessário.';
}

// Logo
$logoUrl = (string)admin_setting_get('app.logo_url', '');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Complemento - Candidatura</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f7fa; color: #1a1a2e; line-height: 1.6; }
        .container { max-width: 640px; margin: 0 auto; padding: 24px 16px; }
        .card { background: white; border-radius: 12px; padding: 24px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,.06); }
        .logo { text-align: center; margin-bottom: 20px; }
        .logo img { max-height: 50px; }
        h1 { font-size: 22px; font-weight: 700; margin-bottom: 8px; color: #0ea5e9; }
        h2 { font-size: 16px; font-weight: 600; margin-bottom: 12px; }
        .note { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px 16px; border-radius: 0 8px 8px 0; margin-bottom: 20px; font-size: 14px; }
        .success { background: #d1fae5; border-left: 4px solid #10b981; padding: 12px 16px; border-radius: 0 8px 8px 0; margin-bottom: 20px; font-size: 14px; color: #065f46; }
        label { display: block; font-weight: 600; font-size: 14px; margin-bottom: 6px; }
        select, textarea, input[type="file"] { width: 100%; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; margin-bottom: 16px; }
        textarea { min-height: 100px; resize: vertical; }
        .btn { display: inline-block; padding: 12px 24px; background: #0ea5e9; color: white; border: none; border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer; text-decoration: none; }
        .btn:hover { background: #0284c7; }
        .reply-item { border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-bottom: 10px; }
        .reply-item .meta { font-size: 12px; color: #64748b; margin-bottom: 6px; }
        .reply-item .content { font-size: 14px; }
        .reply-item img { max-width: 100%; border-radius: 8px; margin-top: 8px; }
        .reply-item a { color: #0ea5e9; text-decoration: none; font-weight: 600; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; }
        .badge-text { background: #dbeafe; color: #1d4ed8; }
        .badge-file { background: #fef3c7; color: #92400e; }
        .badge-image { background: #d1fae5; color: #065f46; }
    </style>
</head>
<body>
<div class="container">
    <?php if ($logoUrl): ?>
    <div class="logo"><img src="<?= htmlspecialchars($logoUrl) ?>" alt="Logo"></div>
    <?php endif; ?>

    <div class="card">
        <h1>Complemento de Candidatura</h1>
        <p style="color:#64748b;font-size:14px;margin-bottom:4px">Olá, <strong><?= htmlspecialchars($name) ?></strong>!</p>
        <p style="color:#64748b;font-size:14px">Foi solicitado um complemento na sua candidatura. Envie as informações abaixo.</p>
    </div>

    <?php if ($note): ?>
    <div class="note">
        <strong>O que foi solicitado:</strong><br>
        <?= nl2br(htmlspecialchars(str_replace('Solicitação de complemento: ', '', $note))) ?>
    </div>
    <?php endif; ?>

    <?php if ($successMsg): ?>
    <div class="success"><?= htmlspecialchars($successMsg) ?></div>
    <?php endif; ?>

    <div class="card">
        <h2>Enviar Resposta</h2>
        <form method="post" action="/application_reply_post.php" enctype="multipart/form-data">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
            
            <label for="reply_type">Tipo de resposta</label>
            <select name="reply_type" id="reply_type" onchange="toggleFields()">
                <option value="text">📝 Texto</option>
                <option value="image">📷 Imagem</option>
                <option value="file">📎 Arquivo/Documento</option>
            </select>

            <div id="field-text">
                <label for="content">Mensagem</label>
                <textarea name="content" id="content" placeholder="Digite sua resposta aqui..."></textarea>
            </div>

            <div id="field-file" style="display:none">
                <label for="file">Selecione o(s) arquivo(s)</label>
                <input type="file" name="files[]" id="file" accept="*/*" multiple>
                <p style="font-size:12px;color:#64748b;margin-top:-10px;margin-bottom:16px">Máximo 10MB por arquivo. Pode selecionar vários de uma vez.</p>
            </div>

            <button type="submit" class="btn">Enviar</button>
        </form>
    </div>

    <?php if (count($replies) > 0): ?>
    <div class="card">
        <h2>Respostas já enviadas (<?= count($replies) ?>)</h2>
        <?php foreach ($replies as $reply): ?>
        <div class="reply-item">
            <div class="meta">
                <?php
                $type = (string)$reply['reply_type'];
                $badgeClass = $type === 'text' ? 'badge-text' : ($type === 'image' ? 'badge-image' : 'badge-file');
                $typeLabel = $type === 'text' ? '📝 Texto' : ($type === 'image' ? '📷 Imagem' : '📎 Arquivo');
                ?>
                <span class="badge <?= $badgeClass ?>"><?= $typeLabel ?></span>
                &nbsp; <?= date('d/m/Y H:i', strtotime((string)$reply['created_at'])) ?>
            </div>
            <div class="content">
                <?php if ($type === 'text'): ?>
                    <?= nl2br(htmlspecialchars((string)$reply['content'])) ?>
                <?php elseif ($type === 'image'): ?>
                    <img src="<?= htmlspecialchars((string)$reply['file_path']) ?>" alt="Imagem enviada">
                <?php else: ?>
                    <a href="<?= htmlspecialchars((string)$reply['file_path']) ?>" target="_blank">📎 <?= htmlspecialchars((string)$reply['file_name']) ?></a>
                    <span style="font-size:12px;color:#64748b">(<?= number_format(((int)$reply['file_size']) / 1024, 0) ?> KB)</span>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <p style="text-align:center;font-size:12px;color:#94a3b8;margin-top:20px">Você pode enviar múltiplas respostas. Cada envio será registrado.</p>
</div>

<script>
function toggleFields() {
    var type = document.getElementById('reply_type').value;
    document.getElementById('field-text').style.display = (type === 'text') ? 'block' : 'none';
    document.getElementById('field-file').style.display = (type !== 'text') ? 'block' : 'none';
    if (type === 'image') {
        document.getElementById('file').setAttribute('accept', 'image/*');
    } else if (type === 'file') {
        document.getElementById('file').setAttribute('accept', '*/*');
    }
}
</script>
</body>
</html>
