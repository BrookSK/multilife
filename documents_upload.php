<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('documents.manage');

view_header('Novo documento');

echo '<div class="card">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900;margin-bottom:6px">Novo documento</div>';
echo '<div style="color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">O envio cria a versão v1. Atualizações criam v2, v3...</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/documents_list.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<div style="height:14px"></div>';

echo '<form method="post" action="/documents_upload_post.php" enctype="multipart/form-data" style="display:grid;gap:12px;max-width:860px">';

echo '<div class="grid">';

echo '<div class="col6">';
echo '<label>Tipo de entidade<select name="entity_type" required>';
echo '<option value="patient">patient</option>';
echo '<option value="professional">professional</option>';
echo '<option value="company">company</option>';
echo '</select></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label>ID da entidade (vazio para company)<input name="entity_id" placeholder="Ex: 123"></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label>Categoria<input name="category" required maxlength="60" placeholder="Ex: Faturamento"></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label>Título (opcional)<input name="title" maxlength="160" placeholder="Ex: COREN"></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label>Validade (opcional)<input type="date" name="valid_until"></label>';
echo '</div>';

echo '<div class="col12">';
echo '<label>Arquivo<input type="file" name="file" required></label>';
echo '</div>';

echo '</div>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end">';
echo '<a class="btn" href="/documents_list.php">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">Enviar</button>';
echo '</div>';

echo '</form>';

echo '</div>';

view_footer();
