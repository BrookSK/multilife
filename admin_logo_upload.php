<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

view_header('Upload de Logo');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Upload de Logo</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Faça upload da logo da empresa para aparecer na sidebar.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/admin_settings.php">Voltar para Configurações</a>';
echo '</div>';
echo '</div>';
echo '</section>';

$currentLogo = admin_setting_get('app.logo_url');

echo '<section class="card col12">';
echo '<form method="post" action="/admin_logo_upload_post.php" enctype="multipart/form-data" style="display:grid;gap:14px">';

if (!empty($currentLogo)) {
    echo '<div>';
    echo '<div style="font-weight:600;margin-bottom:8px">Logo Atual:</div>';
    echo '<img src="' . h($currentLogo) . '" alt="Logo atual" style="max-height:80px;border:1px solid hsl(var(--border));border-radius:8px;padding:8px">';
    echo '</div>';
}

echo '<label>Selecione a nova logo (PNG, JPG, SVG)<input type="file" name="logo" accept="image/*" required></label>';

echo '<div style="color:hsl(var(--muted-foreground));font-size:14px">';
echo '• Formatos aceitos: PNG, JPG, JPEG, SVG<br>';
echo '• Tamanho máximo: 2MB<br>';
echo '• Recomendado: Imagem com fundo transparente (PNG)<br>';
echo '• Dimensões recomendadas: 200x50px ou similar';
echo '</div>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end">';
echo '<a class="btn" href="/admin_settings.php">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">Fazer Upload</button>';
echo '</div>';

echo '</form>';
echo '</section>';

echo '</div>';

view_footer();
