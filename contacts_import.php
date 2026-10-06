<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

require_once __DIR__ . '/app/imported_contacts_helper.php';
imported_contacts_ensure_schema();

$total = (int)(db()->query("SELECT COUNT(*) FROM imported_contacts")->fetchColumn() ?: 0);

view_header('Importar Contatos');

echo '<div class="grid">';
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Importar Contatos</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Importe os contatos exportados (nome, telefone, categoria) para que fiquem disponíveis para a captação. Já cadastrados: <strong>' . $total . '</strong>.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/imported_contacts_list.php">Ver contatos</a>';
echo '<a class="btn" href="/demands_list.php">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

echo '<section class="card col12">';
echo '<form method="post" action="/contacts_import_post.php" enctype="multipart/form-data" style="display:grid;gap:12px;max-width:720px">';

echo '<div style="padding:12px;background:hsla(var(--primary)/.05);border:1px solid hsl(var(--border));border-radius:8px;font-size:13px;line-height:1.6">';
echo '<strong>Formato aceito:</strong> arquivo CSV com colunas de <strong>nome</strong>, <strong>telefone</strong> e (opcional) <strong>categoria</strong> (cliente / profissional / outros).<br>';
echo 'As colunas são detectadas automaticamente pelo cabeçalho. Se não houver coluna de categoria, ela é inferida do próprio nome (ex.: "CLIENTE - Fulano").';
echo '</div>';

echo '<label>Arquivo CSV *<input type="file" name="contacts_file" accept=".csv,text/csv,text/plain" required></label>';

echo '<div class="grid">';
echo '<div class="col6"><label>Separador<select name="delimiter">';
echo '<option value=";">; (ponto e vírgula)</option>';
echo '<option value=",">, (vírgula)</option>';
echo '<option value="tab">Tab</option>';
echo '</select></label></div>';
echo '<div class="col6"><label>Categoria padrão (se não detectada)<select name="default_category">';
echo '<option value="outros">Outros</option>';
echo '<option value="cliente">Cliente</option>';
echo '<option value="profissional">Profissional</option>';
echo '</select></label></div>';
echo '</div>';

echo '<label style="display:flex;align-items:center;gap:8px;cursor:pointer"><input type="checkbox" name="has_header" value="1" checked> A primeira linha é cabeçalho</label>';

echo '<div style="display:flex;gap:10px;justify-content:flex-end">';
echo '<a class="btn" href="/demands_list.php">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">Importar</button>';
echo '</div>';

echo '</form>';
echo '</section>';
echo '</div>';

view_footer();
