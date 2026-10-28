<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
if (!rbac_user_can((int)auth_user_id(), 'chat.manage') && !rbac_user_can((int)auth_user_id(), 'demands.manage')) {
    rbac_require_permission('chat.manage');
}

require_once __DIR__ . '/app/imported_contacts_helper.php';
imported_contacts_ensure_schema();
location_users_ensure_schema(); // colunas user_id/specialty/city/state/neighborhood

// Especialidades cadastradas, indexadas pela chave canônica (para sugerir a do contato).
$specialtyOptions = [];
$specialtyByKey = [];
try {
    foreach (db()->query("SELECT name FROM specialties WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $sn) {
        $specialtyOptions[] = (string)$sn;
        $k = specialty_key((string)$sn);
        if ($k !== '' && !isset($specialtyByKey[$k])) {
            $specialtyByKey[$k] = (string)$sn;
        }
    }
} catch (Throwable $e) {}
$canPreRegister = rbac_user_can((int)auth_user_id(), 'users.manage') || rbac_user_can((int)auth_user_id(), 'demands.manage');

$seed = imported_contacts_seed_bundled(false);
if (!empty($seed['ran'])) {
    $done = imported_contacts_counts();
    flash_set('success', 'Agenda importada. Profissionais: ' . $done['profissional']
        . ', operadoras/clientes: ' . $done['operadora']
        . ', pacientes: ' . $done['paciente']
        . ', outros: ' . $done['outros'] . '.');
}

$labels = imported_contacts_categories();
$category = isset($_GET['category']) && isset($labels[$_GET['category']]) ? (string)$_GET['category'] : 'profissional';
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];
if ($category === 'operadora') {
    $where[] = "category IN ('operadora','cliente')";
} else {
    $where[] = 'category = :cat';
    $params['cat'] = $category;
}
if ($q !== '') {
    $like = '%' . $q . '%';
    $where[] = '(name LIKE :q_name OR phone LIKE :q_phone OR phone_raw LIKE :q_raw)';
    $params['q_name'] = $like;
    $params['q_phone'] = $like;
    $params['q_raw'] = $like;
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$countStmt = db()->prepare("SELECT COUNT(*) FROM imported_contacts $whereSql");
$countStmt->execute($params);
$totalFiltered = (int)$countStmt->fetchColumn();
$pages = max(1, (int)ceil($totalFiltered / $perPage));
if ($page > $pages) {
    $page = $pages;
    $offset = ($page - 1) * $perPage;
}

$stmt = db()->prepare("SELECT * FROM imported_contacts $whereSql ORDER BY name ASC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$counts = imported_contacts_counts();

view_header('Contatos Importados');

echo '<div class="grid">';
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Contatos Importados</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Profissionais: <strong>' . $counts['profissional'] . '</strong> · Operadoras/Clientes: <strong>' . $counts['operadora'] . '</strong> · Pacientes: <strong>' . $counts['paciente'] . '</strong> · Outros: <strong>' . $counts['outros'] . '</strong></div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/chat_web.php?type=contatos&cat=' . urlencode($category) . '">Abrir no WhatsApp</a>';
echo '<a class="btn btnPrimary" href="/contacts_import.php">Importar</a>';
echo '</div>';
echo '</div>';

echo '<div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap">';
foreach ($labels as $key => $label) {
    $active = $category === $key;
    $style = $active
        ? 'background:#00a884;color:#fff;border-color:#00a884'
        : 'background:#fff;color:#54656f;border-color:hsl(var(--border))';
    $href = '/imported_contacts_list.php?category=' . urlencode($key) . ($q !== '' ? '&q=' . urlencode($q) : '');
    echo '<a href="' . h($href) . '" style="padding:8px 12px;border-radius:999px;border:1px solid;font-size:13px;font-weight:700;text-decoration:none;' . $style . '">' . h($label) . ' (' . (int)$counts[$key] . ')</a>';
}
echo '</div>';

echo '<form method="get" action="/imported_contacts_list.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<input type="hidden" name="category" value="' . h($category) . '">';
echo '<input name="q" value="' . h($q) . '" placeholder="Buscar por nome ou telefone" style="flex:1;min-width:240px">';
echo '<button class="btn" type="submit">Filtrar</button>';
echo '</form>';
echo '</section>';

echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr><th>Nome</th><th>Telefone</th><th>Categoria</th><th></th></tr></thead><tbody>';
foreach ($rows as $r) {
    $cat = (string)$r['category'];
    if ($cat === 'cliente') {
        $cat = 'operadora';
    }
    $badgeColor = $cat === 'paciente' ? 'hsl(221,83%,53%)' : ($cat === 'profissional' ? 'hsl(142,76%,36%)' : ($cat === 'operadora' ? 'hsl(32,95%,44%)' : 'hsl(var(--muted-foreground))'));
    $phone = (string)($r['phone'] ?? '');
    $phoneShow = $phone !== '' ? imported_contacts_format_phone($phone) : (string)($r['phone_raw'] ?? '-');
    $inactive = (string)($r['notes'] ?? '') === 'nao_atende';
    echo '<tr>';
    echo '<td>' . h((string)$r['name']);
    if ($inactive) {
        echo ' <span class="badge" style="background:#fde8e8;color:#c81e1e;font-size:11px">Não atende</span>';
    }
    echo '</td>';
    echo '<td>' . h($phoneShow) . '</td>';
    echo '<td><span class="badge" style="background:' . $badgeColor . ';color:#fff;font-size:12px">' . h($labels[$cat] ?? $cat) . '</span></td>';
    echo '<td style="white-space:nowrap">';
    if ($phone !== '') {
        echo '<a class="btn btnPrimary" href="/chat_open_imported_contact.php?id=' . (int)$r['id'] . '">Abrir conversa</a>';
    }
    // Profissional da agenda: pré-cadastro rápido no sistema (entra nos grupos de captação
    // da especialidade/cidade). Contatos ainda não cadastrados já entram nos grupos pela
    // especialidade deduzida do nome.
    if ($cat === 'profissional' && $phone !== '') {
        if (!empty($r['user_id'])) {
            echo ' <a class="badge" style="background:hsl(142,76%,36%);color:#fff;font-size:11px" href="/users_edit.php?id=' . (int)$r['user_id'] . '">Pré-cadastrado</a>';
        } elseif ($canPreRegister) {
            $suggested = trim((string)($r['specialty'] ?? ''));
            if ($suggested === '') {
                $suggested = $specialtyByKey[specialty_key((string)$r['name'])] ?? '';
            }
            $payload = [
                'id' => (int)$r['id'],
                'name' => (string)$r['name'],
                'phone' => $phone,
                'specialty' => $suggested,
                'city' => (string)($r['city'] ?? ''),
                'state' => (string)($r['state'] ?? '') !== '' ? (string)$r['state'] : (string)(location_ddd_state($phone) ?? ''),
                'neighborhood' => (string)($r['neighborhood'] ?? ''),
            ];
            echo ' <button type="button" class="btn" onclick=\'icPreRegister(' . h(json_encode($payload, JSON_UNESCAPED_UNICODE)) . ')\'>Pré-cadastrar</button>';
        }
    }
    echo '</td>';
    echo '</tr>';
}
if (count($rows) === 0) {
    echo '<tr><td colspan="4" style="padding:14px">Nenhum contato neste grupo. Use Importar para carregar a agenda.</td></tr>';
}
echo '</tbody></table>';
echo '</div>';

if ($pages > 1) {
    echo '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px;flex-wrap:wrap">';
    $base = '/imported_contacts_list.php?category=' . urlencode($category) . ($q !== '' ? '&q=' . urlencode($q) : '');
    if ($page > 1) {
        echo '<a class="btn" href="' . h($base . '&page=' . ($page - 1)) . '">Anterior</a>';
    }
    echo '<span style="align-self:center;font-size:13px;color:hsl(var(--muted-foreground))">Página ' . $page . ' de ' . $pages . '</span>';
    if ($page < $pages) {
        echo '<a class="btn" href="' . h($base . '&page=' . ($page + 1)) . '">Próxima</a>';
    }
    echo '</div>';
}
echo '</section>';
echo '</div>';

if ($canPreRegister) {
    echo '<div id="icModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:200;align-items:center;justify-content:center">';
    echo '<section class="card" style="width:480px;max-width:92vw">';
    echo '<div style="display:flex;justify-content:space-between;align-items:center"><strong>Pré-cadastrar profissional</strong><button type="button" class="btn" onclick="document.getElementById(\'icModal\').style.display=\'none\'">Fechar</button></div>';
    echo '<div style="height:10px"></div><div style="display:grid;gap:10px">';
    echo '<input type="hidden" id="icId">';
    echo '<label>Nome *<input id="icName" maxlength="200"></label>';
    echo '<label>Telefone<input id="icPhone" readonly></label>';
    echo '<label>Especialidade<select id="icSpecialty"><option value="">— Selecione —</option>';
    foreach ($specialtyOptions as $sn) {
        echo '<option value="' . h($sn) . '">' . h($sn) . '</option>';
    }
    echo '</select></label>';
    echo '<div class="grid"><div class="col8"><label>Cidade<input id="icCity" maxlength="120"></label></div><div class="col4"><label>UF<input id="icState" maxlength="2" style="text-transform:uppercase"></label></div></div>';
    echo '<label>Bairro (define a zona em São Paulo/Rio)<input id="icNeighborhood" maxlength="120"></label>';
    echo '<div id="icMsg" style="font-size:13px;color:hsl(var(--muted-foreground))"></div>';
    echo '<div style="display:flex;justify-content:flex-end;gap:8px"><button type="button" class="btn" id="icForce" style="display:none">Cadastrar mesmo assim</button><button type="button" class="btn btnPrimary" id="icSave">Pré-cadastrar</button></div>';
    echo '</div></section></div>';
    ?>
<script>
function icPreRegister(c){
  document.getElementById('icId').value = c.id;
  document.getElementById('icName').value = c.name || '';
  document.getElementById('icPhone').value = c.phone || '';
  document.getElementById('icSpecialty').value = c.specialty || '';
  document.getElementById('icCity').value = c.city || '';
  document.getElementById('icState').value = c.state || '';
  document.getElementById('icNeighborhood').value = c.neighborhood || '';
  document.getElementById('icMsg').textContent = '';
  document.getElementById('icForce').style.display = 'none';
  document.getElementById('icModal').style.display = 'flex';
}
(function(){
  function send(force){
    var fd = new FormData();
    fd.append('imported_contact_id', document.getElementById('icId').value);
    fd.append('name', document.getElementById('icName').value);
    fd.append('phone', document.getElementById('icPhone').value);
    fd.append('specialty', document.getElementById('icSpecialty').value);
    fd.append('city', document.getElementById('icCity').value);
    fd.append('state', document.getElementById('icState').value);
    fd.append('neighborhood', document.getElementById('icNeighborhood').value);
    if (force) fd.append('force', '1');
    var msg = document.getElementById('icMsg'); msg.textContent = 'Salvando…';
    fetch('/professional_quick_create_post.php', { method: 'POST', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (j && j.ok) { msg.textContent = 'Pré-cadastrado.'; setTimeout(function(){ location.reload(); }, 600); return; }
        if (j && j.needs_confirmation) {
          msg.textContent = (j.message || 'Parecidos encontrados:') + ' ' + (j.duplicates || []).map(function(d){ return d.name + ' (' + d.phone + ')'; }).join('; ');
          document.getElementById('icForce').style.display = '';
          return;
        }
        msg.textContent = (j && j.message) ? j.message : 'Erro ao pré-cadastrar.';
      })
      .catch(function(){ msg.textContent = 'Falha de conexão.'; });
  }
  document.getElementById('icSave').addEventListener('click', function(){ send(false); });
  document.getElementById('icForce').addEventListener('click', function(){ send(true); });
})();
</script>
<?php
}

view_footer();
