<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('tech_logs.view');

$provider = isset($_GET['provider']) ? trim((string)$_GET['provider']) : '';
$status = isset($_GET['status']) ? (string)$_GET['status'] : '';

if (!in_array($status, ['', 'success', 'error'], true)) {
    $status = '';
}

$sql = 'SELECT id, provider, action, status, http_status, error_message, attempts, created_at
        FROM integration_logs
        WHERE 1=1';
$params = [];

if ($provider !== '') {
    $sql .= ' AND provider LIKE :p';
    $params['p'] = '%' . $provider . '%';
}

if ($status !== '') {
    $sql .= ' AND status = :s';
    $params['s'] = $status;
}

$sql .= ' ORDER BY id DESC LIMIT 500';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

view_header('Logs Técnicos');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Logs Técnicos</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Integrações (OpenAI/Evolution/ZapSign/SMTP/Webhooks).</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/integration_jobs_list.php">Jobs</a>';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<form method="get" action="/tech_logs_list.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<input name="provider" value="' . h($provider) . '" placeholder="Provider" style="flex:1;min-width:220px">';
echo '<select name="status" style="min-width:200px">';
echo '<option value=""' . ($status === '' ? ' selected' : '') . '>Todos</option>';
echo '<option value="success"' . ($status === 'success' ? ' selected' : '') . '>success</option>';
echo '<option value="error"' . ($status === 'error' ? ' selected' : '') . '>error</option>';
echo '</select>';
echo '<button class="btn" type="submit">Filtrar</button>';
echo '</form>';

echo '</section>';

// ------------------------------------------------- Manutenção (tamanho / limpeza)
if (rbac_user_can((int)auth_user_id(), 'admin.settings.manage')) {
    $st = integration_logs_stats();
    $daysOk = (int)admin_setting_get('logs.integration_retention_days_success', '7');
    $daysErr = (int)admin_setting_get('logs.integration_retention_days_error', '30');
    $archOn = (string)admin_setting_get('logs.integration_archive', '1') !== '0';
    $archMonths = (int)admin_setting_get('logs.integration_archive_months', '12');
    $fmtN = static fn($n) => number_format((int)$n, 0, ',', '.');

    echo '<section class="card col12">';
    echo '<div style="font-weight:900;font-size:16px;margin-bottom:10px">Tamanho e limpeza</div>';
    echo '<div style="display:flex;gap:24px;flex-wrap:wrap;margin-bottom:12px;font-size:14px">';
    echo '<div><div style="color:hsl(var(--muted-foreground))">Registros (aprox.)</div><div style="font-size:20px;font-weight:800">' . $fmtN($st['rows']) . '</div></div>';
    echo '<div><div style="color:hsl(var(--muted-foreground))">Tamanho no banco</div><div style="font-size:20px;font-weight:800">' . number_format($st['size_mb'], 1, ',', '.') . ' MB</div></div>';
    echo '<div><div style="color:hsl(var(--muted-foreground))">Mais antigo</div><div style="font-size:20px;font-weight:800">' . h($st['oldest'] ? date('d/m/Y', strtotime((string)$st['oldest'])) : '-') . '</div></div>';
    echo '<div><div style="color:hsl(var(--muted-foreground))">Fora do prazo (a limpar)</div><div style="font-size:20px;font-weight:800;color:' . ($st['old_rows'] > 0 ? '#d97706' : '#16a34a') . '">' . ($st['old_rows'] > 1000000 ? '+1.000.000' : $fmtN($st['old_rows'])) . '</div></div>';
    echo '</div>';

    echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));line-height:1.6;margin-bottom:12px">'
        . 'A limpeza roda sozinha a cada 10 minutos, em pequenos lotes. Antes de apagar, os registros são guardados em arquivos compactados no servidor (um por mês), que podem ser baixados abaixo. '
        . 'Consultas de status do WhatsApp que deram certo não são mais gravadas, e mídias (base64) não são mais salvas no log.</div>';

    echo '<form method="post" action="/tech_logs_maintenance_post.php" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-bottom:14px">';
    echo '<input type="hidden" name="action" value="settings">';
    echo '<label style="font-size:13px">Manter sucessos por (dias)<br><input type="number" name="days_success" min="1" max="365" value="' . $daysOk . '" style="width:110px"></label>';
    echo '<label style="font-size:13px">Manter erros por (dias)<br><input type="number" name="days_error" min="1" max="730" value="' . $daysErr . '" style="width:110px"></label>';
    echo '<label style="font-size:13px">Guardar arquivos por (meses)<br><input type="number" name="archive_months" min="1" max="60" value="' . $archMonths . '" style="width:110px"></label>';
    echo '<label style="font-size:13px;display:flex;gap:6px;align-items:center"><input type="checkbox" name="archive" value="1"' . ($archOn ? ' checked' : '') . '> Guardar em arquivo antes de apagar</label>';
    echo '<button class="btn" type="submit">Salvar prazos</button>';
    echo '</form>';

    echo '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">';
    echo '<button class="btn btnPrimary" type="button" id="logCleanBtn">Arquivar e limpar agora</button>';
    echo '<button class="btn" type="button" id="logOptimizeBtn" title="Devolve ao disco o espaço liberado pela limpeza">Compactar tabela</button>';
    echo '<span id="logCleanStatus" style="font-size:13px"></span>';
    echo '</div>';

    if ($st['files']) {
        echo '<div style="margin-top:14px;font-size:13px"><strong>Arquivos guardados no servidor:</strong> ';
        $links = [];
        foreach ($st['files'] as $f) {
            $links[] = '<a href="/tech_logs_archive_download.php?f=' . rawurlencode($f['name']) . '">' . h($f['name']) . '</a> (' . number_format($f['size'] / 1048576, 1, ',', '.') . ' MB)';
        }
        echo implode(' · ', $links) . '</div>';
    }
    echo '</section>';
    ?>
<script>
(function(){
  var st = document.getElementById('logCleanStatus');
  function post(action){
    var fd = new FormData(); fd.append('action', action);
    return fetch('/tech_logs_maintenance_post.php', { method:'POST', body: fd, headers:{'X-Requested-With':'XMLHttpRequest'} })
      .then(function(r){ return r.json(); });
  }
  var cleanBtn = document.getElementById('logCleanBtn');
  cleanBtn.addEventListener('click', function(){
    if (!confirm('Arquivar e apagar da tabela os registros fora do prazo?')) return;
    cleanBtn.disabled = true;
    var total = 0;
    (function step(){
      st.textContent = 'Limpando... ' + total.toLocaleString('pt-BR') + ' removido(s) até agora. Não feche a página.';
      post('clean').then(function(j){
        if (!j.ok) { st.textContent = j.message || 'Falhou.'; cleanBtn.disabled = false; return; }
        total += (j.deleted || 0);
        if (j.more) { step(); return; }
        st.textContent = 'Pronto: ' + total.toLocaleString('pt-BR') + ' registro(s) removido(s). Agora clique em "Compactar tabela" para liberar o espaço em disco.';
        cleanBtn.disabled = false;
      }).catch(function(){ st.textContent = 'Erro de conexão. Clique de novo para continuar de onde parou.'; cleanBtn.disabled = false; });
    })();
  });
  var optBtn = document.getElementById('logOptimizeBtn');
  optBtn.addEventListener('click', function(){
    if (!confirm('Compactar a tabela agora? Pode levar alguns minutos se ela for grande. Faça fora do horário de uso.')) return;
    optBtn.disabled = true;
    st.textContent = 'Compactando... aguarde.';
    post('optimize').then(function(j){ st.textContent = j.message || ''; optBtn.disabled = false; })
      .catch(function(){ st.textContent = 'A compactação pode continuar rodando no banco. Recarregue a página em alguns minutos.'; optBtn.disabled = false; });
  });
})();
</script>
    <?php
}

echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr>';
echo '<th>ID</th><th>Provider</th><th>Ação</th><th>Status</th><th>HTTP</th><th>Tentativas</th><th>Erro</th><th>Quando</th><th style="text-align:right">Ações</th>';
echo '</tr></thead><tbody>';
foreach ($rows as $r) {
    echo '<tr>';
    echo '<td>' . (int)$r['id'] . '</td>';
    echo '<td style="font-weight:700">' . h((string)$r['provider']) . '</td>';
    echo '<td>' . h((string)$r['action']) . '</td>';
    echo '<td>' . h((string)$r['status']) . '</td>';
    echo '<td>' . h((string)($r['http_status'] ?? '')) . '</td>';
    echo '<td>' . h((string)$r['attempts']) . '</td>';
    echo '<td>' . h(mb_strimwidth((string)($r['error_message'] ?? ''), 0, 80, '...')) . '</td>';
    echo '<td>' . h((string)$r['created_at']) . '</td>';
    echo '<td style="text-align:right">';
    echo '<a class="btn" href="/tech_logs_view.php?id=' . (int)$r['id'] . '">Abrir</a>';
    echo '</td>';
    echo '</tr>';
}
if (count($rows) === 0) {
    echo '<tr><td colspan="9" class="pill" style="display:table-cell;padding:12px">Sem logs.</td></tr>';
}

echo '</tbody></table>';
echo '</div>';
echo '</section>';

echo '</div>';

view_footer();
