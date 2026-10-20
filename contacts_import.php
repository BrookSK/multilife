<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
if (!rbac_user_can((int)auth_user_id(), 'chat.manage') && !rbac_user_can((int)auth_user_id(), 'demands.manage')) {
    rbac_require_permission('chat.manage');
}

require_once __DIR__ . '/app/imported_contacts_helper.php';
imported_contacts_ensure_schema();

$total = (int)(db()->query("SELECT COUNT(*) FROM imported_contacts")->fetchColumn() ?: 0);
$bundled = is_file(__DIR__ . '/docs/contatos.csv');

view_header('Importar Contatos');

echo '<div class="grid">';
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Importar Contatos</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">A agenda é separada em profissionais, operadoras/clientes e pacientes. Já cadastrados: <strong>' . $total . '</strong>.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/imported_contacts_list.php">Ver contatos</a>';
echo '<a class="btn" href="/chat_web.php?type=contatos">Aba no WhatsApp</a>';
echo '</div>';
echo '</div>';
echo '</section>';

if ($bundled) {
    echo '<section class="card col12">';
    echo '<div style="font-weight:800;margin-bottom:8px">Agenda já enviada</div>';
    echo '<p style="margin:0 0 12px;color:hsl(var(--muted-foreground));font-size:14px">O arquivo <strong>docs/contatos.csv</strong> está no sistema. A importação classifica cada contato pelo nome (especialidade, paciente ou operadora/cliente).</p>';
    echo '<form method="post" action="/contacts_import_post.php">';
    echo '<input type="hidden" name="bundled" value="1">';
    echo '<button class="btn btnPrimary" type="submit">Importar agenda de contatos</button>';
    echo '</form>';
    echo '</section>';
}

echo '<section class="card col12">';
echo '<form method="post" action="/contacts_import_post.php" enctype="multipart/form-data" style="display:grid;gap:12px;max-width:720px">';

echo '<div style="padding:12px;background:hsla(var(--primary)/.05);border:1px solid hsl(var(--border));border-radius:8px;font-size:13px;line-height:1.6">';
echo '<strong>Também aceita</strong> a exportação do Google Contatos ou um CSV com colunas de nome, telefone e categoria (profissional, operadora, paciente).';
echo '</div>';

echo '<label>Arquivo CSV *<input type="file" name="contacts_file" accept=".csv,text/csv,text/plain" required></label>';

echo '<label>Categoria padrão (quando o nome não indica o grupo)<select name="default_category">';
echo '<option value="outros">Outros</option>';
echo '<option value="profissional">Profissional</option>';
echo '<option value="operadora">Operadora/Cliente</option>';
echo '<option value="paciente">Paciente</option>';
echo '</select></label>';

echo '<div style="display:flex;gap:10px;justify-content:flex-end">';
echo '<a class="btn" href="/imported_contacts_list.php">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">Importar arquivo</button>';
echo '</div>';

echo '</form>';
echo '</section>';
echo '</div>';

view_footer();
