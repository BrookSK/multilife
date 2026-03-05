<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('patients.manage');

view_header('Novo paciente');

echo '<div class="card">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900;margin-bottom:6px">Novo paciente</div>';
echo '<div style="color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Cadastro inicial do paciente.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/patients_list.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<div style="height:14px"></div>';

echo '<form method="post" action="/patients_create_post.php" style="display:grid;gap:12px;max-width:980px">';

echo '<div style="font-weight:900">Identificação</div>';
echo '<div class="grid">';
echo '<div class="col6"><label>Nome completo<input name="full_name" required maxlength="160" placeholder="Nome do paciente"></label></div>';
echo '<div class="col6"><label>CPF<input name="cpf" maxlength="20" placeholder="000.000.000-00"></label></div>';
echo '<div class="col6"><label>RG<input name="rg" maxlength="30"></label></div>';
echo '<div class="col6"><label>Data de nascimento<input type="date" name="birth_date"></label></div>';
echo '</div>';

echo '<div style="font-weight:900;margin-top:6px">Contato</div>';
echo '<div class="grid">';
echo '<div class="col6"><label>WhatsApp<input name="whatsapp" maxlength="30" placeholder="5511999999999"></label></div>';
echo '<div class="col6"><label>Telefone principal<input name="phone_primary" maxlength="30" placeholder="5511999999999"></label></div>';
echo '<div class="col6"><label>Telefone secundário<input name="phone_secondary" maxlength="30" placeholder="5511999999999"></label></div>';
echo '<div class="col6"><label>E-mail<input type="email" name="email" maxlength="190" placeholder="email@exemplo.com"></label></div>';
echo '</div>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;margin-top:6px">';
echo '<a class="btn" href="/patients_list.php">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '</div>';

echo '</form>';
echo '</div>';

view_footer();
