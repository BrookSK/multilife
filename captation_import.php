<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

// Contagem de cards "em andamento" (candidatos a zerar).
$inProgressStatuses = ['aguardando_captacao', 'tratamento_manual', 'em_captacao'];
$inProgressCount = 0;
try {
    $ph = implode(',', array_fill(0, count($inProgressStatuses), '?'));
    $st = db()->prepare("SELECT COUNT(*) FROM demands WHERE status IN ($ph)");
    $st->execute($inProgressStatuses);
    $inProgressCount = (int)$st->fetchColumn();
} catch (Throwable $e) {}

view_header('Importar Captações');

echo '<div class="grid">';
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Importar Captações (planilha)</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Importe a planilha de captações (ex.: fechados de setembro). As captações viram cards no sistema.</div>';
echo '</div>';
echo '<a class="btn" href="/demands_list.php">Voltar</a>';
echo '</div>';
echo '</section>';

echo '<section class="card col12">';
echo '<form method="post" action="/captation_import_post.php" enctype="multipart/form-data" style="display:grid;gap:12px;max-width:760px">';

echo '<div style="padding:12px;background:hsla(var(--primary)/.05);border:1px solid hsl(var(--border));border-radius:8px;font-size:13px;line-height:1.6">';
echo '<strong>Formato aceito:</strong> CSV com colunas de <strong>paciente</strong>, <strong>profissional</strong>, <strong>especialidade</strong>, <strong>cidade</strong>, <strong>frequência/PAD</strong> e <strong>status</strong> (ex.: fechado/admitido). As colunas são detectadas pelo cabeçalho.';
echo '</div>';

echo '<label>Arquivo CSV *<input type="file" name="captation_file" accept=".csv,text/csv,text/plain" required></label>';

echo '<div class="grid">';
echo '<div class="col6"><label>Separador<select name="delimiter">';
echo '<option value=";">; (ponto e vírgula)</option>';
echo '<option value=",">, (vírgula)</option>';
echo '<option value="tab">Tab</option>';
echo '</select></label></div>';
echo '<div class="col6"><label>Especialidade padrão (se não detectada)<input name="default_specialty" placeholder="Ex: Fisioterapia"></label></div>';
echo '</div>';

echo '<label style="display:flex;align-items:center;gap:8px;cursor:pointer"><input type="checkbox" name="has_header" value="1" checked> A primeira linha é cabeçalho</label>';

// Opção DESTRUTIVA: zerar os em andamento anteriores.
echo '<div style="padding:14px;background:hsla(0,84%,60%,.07);border:1px solid hsl(0,84%,60%);border-radius:10px">';
echo '<label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:700"><input type="checkbox" name="zero_in_progress" value="1" id="zeroChk"> Zerar os cards "em andamento" anteriores à importação</label>';
echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-top:6px;line-height:1.5">Move para <strong>cancelado</strong> os cards com status <em>aguardando captação, tratamento manual e em captação</em> criados ANTES de agora (' . $inProgressCount . ' candidato(s)). Esta ação é irreversível em massa — use com cuidado.</div>';
echo '<label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-top:8px;font-size:13px"><input type="checkbox" name="zero_confirm" value="1"> Confirmo que entendo que os cards em andamento anteriores serão cancelados.</label>';
echo '</div>';

echo '<div style="display:flex;gap:10px;justify-content:flex-end">';
echo '<a class="btn" href="/demands_list.php">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">Importar</button>';
echo '</div>';

echo '</form>';
echo '</section>';
echo '</div>';

view_footer();
