<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

view_header('Criar Instância Evolution');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Criar Nova Instância</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Crie uma nova instância WhatsApp na Evolution API.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/evolution_instances.php">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

echo '<section class="card col12">';
echo '<form method="post" action="/evolution_instance_create_post.php" style="display:grid;gap:14px">';

echo '<label>Nome da Instância<input name="instance_name" required placeholder="Ex: multilife_whatsapp"></label>';

echo '<div style="padding:12px;background:hsla(var(--primary)/.05);border:1px solid hsl(var(--border));border-radius:8px;font-size:13px;color:hsl(var(--muted-foreground));line-height:1.6">';
echo '<strong>Dica:</strong> Use um nome descritivo e único para identificar esta instância.';
echo '</div>';

echo '<div style="display:flex;justify-content:flex-end;gap:10px">';
echo '<a href="/evolution_instances.php" class="btn">Cancelar</a>';
echo '<button type="submit" class="btn btnPrimary">Criar Instância</button>';
echo '</div>';

echo '</form>';
echo '</section>';

echo '</div>';

view_footer();
