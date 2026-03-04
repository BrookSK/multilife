<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('professional_docs.submit');

view_header('Novo formulário');

echo '<div class="card">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900;margin-bottom:6px">Novo formulário de documentação</div>';
echo '<div style="color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Preencha e salve como rascunho. Depois clique em Enviar.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/professional_docs_list.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<div style="height:14px"></div>';

echo '<form method="post" action="/professional_docs_create_post.php" style="display:grid;gap:12px;max-width:820px">';
echo '<label>Paciente (referência)<input name="patient_ref" required maxlength="160" placeholder="Nome do paciente / ID"></label>';

echo '<label>Quantidade de atendimentos<input type="number" name="sessions_count" min="1" value="1" required></label>';

echo '<label>Documentos de faturamento (descrição / links / nomes)<textarea name="billing_docs" rows="3"></textarea></label>';

echo '<label>Documentos de produtividade (descrição / links / nomes)<textarea name="productivity_docs" rows="3"></textarea></label>';

echo '<label>Observações<textarea name="notes" rows="3"></textarea></label>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end">';
echo '<a class="btn" href="/professional_docs_list.php">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">Salvar rascunho</button>';
echo '</div>';
echo '</form>';

echo '</div>';

view_footer();
