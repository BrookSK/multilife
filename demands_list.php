<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$status = isset($_GET['status']) ? (string)$_GET['status'] : '';
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

$allowedStatuses = ['','aguardando_captacao','tratamento_manual','em_captacao','admitido','cancelado'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}

$sql = 'SELECT d.id, d.title, d.specialty, d.location_city, d.location_state, d.status, d.assumed_by_user_id, d.created_at, u.name AS assumed_by_name
        FROM demands d
        LEFT JOIN users u ON u.id = d.assumed_by_user_id';

$where = [];
$params = [];

if ($status !== '') {
    $where[] = 'd.status = :status';
    $params['status'] = $status;
}

if ($q !== '') {
    $where[] = '(d.title LIKE :q OR d.specialty LIKE :q OR d.location_city LIKE :q OR d.origin_email LIKE :q)';
    $params['q'] = '%' . $q . '%';
}

if (count($where) > 0) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY d.id DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

view_header('Captação - Demandas');

$columns = [
    ['id' => 'aguardando_captacao', 'title' => 'Recebimento de E-mail', 'emoji' => '📥'],
    ['id' => 'tratamento_manual', 'title' => 'Tratamento Manual', 'emoji' => '📋'],
    ['id' => 'em_captacao', 'title' => 'Em Captação', 'emoji' => '🔗'],
    ['id' => 'admitido', 'title' => 'Admitido', 'emoji' => '✅'],
    ['id' => 'cancelado', 'title' => 'Cancelado', 'emoji' => '⛔'],
];

$byStatus = [
    'aguardando_captacao' => [],
    'tratamento_manual' => [],
    'em_captacao' => [],
    'admitido' => [],
    'cancelado' => [],
];

foreach ($rows as $r) {
    $st = (string)$r['status'];
    if (!isset($byStatus[$st])) {
        $byStatus[$st] = [];
    }
    $byStatus[$st][] = $r;
}

echo '<div class="grid">';
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:800">Demandas</div>';
echo '<div style="margin-top:6px;color:rgba(234,240,255,.72);font-size:14px;line-height:1.5">Cards de captação: e-mail → card → assumir → disparo em grupos.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/demands_create.php">Novo card</a>';
echo '<a class="btn" href="/whatsapp_groups_list.php">Grupos WhatsApp</a>';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<form method="get" action="/demands_list.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<select name="status" style="border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:10px 12px;outline:none;font-size:14px">';
$opts = [
    '' => 'Todos os status',
    'aguardando_captacao' => 'Aguardando Captação',
    'tratamento_manual' => 'Tratamento Manual',
    'em_captacao' => 'Em Captação',
    'admitido' => 'Admitido',
    'cancelado' => 'Cancelado',
];
foreach ($opts as $k => $label) {
    $sel = ($status === $k) ? ' selected' : '';
    echo '<option value="' . h($k) . '"' . $sel . '>' . h($label) . '</option>';
}
echo '</select>';
echo '<input name="q" value="' . h($q) . '" placeholder="Buscar (título, especialidade, cidade, origem)" style="flex:1;min-width:240px;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:10px 12px;outline:none;font-size:14px">';
echo '<button class="btn" type="submit">Filtrar</button>';
echo '</form>';

echo '</section>';

echo '<section class="card col12">';
echo '<div class="kanbanScroll">';
echo '<div class="kanbanRow">';

foreach ($columns as $col) {
    $colId = (string)$col['id'];
    $items = $byStatus[$colId] ?? [];

    echo '<div class="kanbanCol">';
    echo '<div class="kanbanColHead">';
    echo '<span class="kanbanEmoji">' . h((string)$col['emoji']) . '</span>';
    echo '<div class="kanbanTitle">' . h((string)$col['title']) . '</div>';
    echo '<div class="kanbanCount">' . (int)count($items) . '</div>';
    echo '</div>';

    echo '<div class="kanbanLane">';

    if (count($items) === 0) {
        echo '<div class="kanbanEmpty">Vazio</div>';
    } else {
        foreach ($items as $r) {
            $loc = trim((string)$r['location_city']);
            $uf = trim((string)$r['location_state']);
            $locTxt = $loc !== '' ? ($loc . ($uf !== '' ? '/' . $uf : '')) : '-';
            $assumed = $r['assumed_by_name'] ? (string)$r['assumed_by_name'] : '-';

            $badgeCls = 'badgeInfo';
            if ($colId === 'admitido') {
                $badgeCls = 'badgeSuccess';
            } elseif ($colId === 'em_captacao' || $colId === 'tratamento_manual') {
                $badgeCls = 'badgeWarn';
            } elseif ($colId === 'cancelado') {
                $badgeCls = 'badgeDanger';
            }

            echo '<a class="kanbanCard" href="/demands_view.php?id=' . (int)$r['id'] . '">';
            echo '<div class="kanbanCardBody">';
            echo '<div class="kanbanCardTop">';
            echo '<div class="kanbanCardTitle">' . h((string)$r['title']) . '</div>';
            echo '</div>';
            echo '<div class="kanbanMeta">' . h($locTxt) . ' • ' . h((string)($r['specialty'] ?? '-')) . '</div>';
            echo '<div class="kanbanMeta">' . h($assumed) . ' • ' . h((string)$r['created_at']) . '</div>';
            echo '<span class="badge ' . h($badgeCls) . '">' . h((string)$r['status']) . '</span>';
            echo '</div>';
            echo '</a>';
        }
    }

    echo '</div>';
    echo '</div>';
}

echo '</div>';
echo '</div>';
echo '</section>';

echo '</div>';

echo '<button class="fab" type="button" id="newDemandFab" aria-label="Novo card">+</button>';

echo '<div id="newDemandOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.24);backdrop-filter:blur(2px);z-index:70"></div>';
echo '<div id="newDemandModal" style="display:none;position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);width:640px;max-width:92vw;z-index:80">';
echo '<section class="card" style="box-shadow:var(--shadow-elevated)">';
echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:16px;font-weight:900">Nova Captação</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:13px;line-height:1.5">Crie um novo card para o fluxo de captação.</div>';
echo '</div>';
echo '<button class="btn" type="button" id="newDemandClose" style="height:34px">Fechar</button>';
echo '</div>';

echo '<div style="height:12px"></div>';

echo '<form method="post" action="/demands_create_post.php" style="display:grid;gap:12px">';
echo '<label>Nome do paciente / Título<input name="title" required maxlength="200" placeholder="Nome completo"></label>';
echo '<div class="grid">';
echo '<div class="col6"><label>Empresa/Convênio (origem e-mail)<input name="origin_email" maxlength="190" placeholder="ex: contato@empresa.com"></label></div>';
echo '<div class="col6"><label>Tipo / Especialidade<input name="specialty" maxlength="120" placeholder="Ex: Fisioterapia"></label></div>';
echo '<div class="col6"><label>Cidade<input name="location_city" maxlength="120" placeholder="Ex: São Paulo"></label></div>';
echo '<div class="col6"><label>UF<input name="location_state" maxlength="2" placeholder="SP" style="text-transform:uppercase"></label></div>';
echo '</div>';
echo '<label>Observações<textarea name="description" rows="3" placeholder="Observações adicionais..."></textarea></label>';

echo '<input type="hidden" name="status" value="aguardando_captacao">';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end">';
echo '<button class="btn" type="button" id="newDemandCancel">Cancelar</button>';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '</div>';
echo '</form>';

echo '</section>';
echo '</div>';

echo '<script>';
echo '(function(){var fab=document.getElementById("newDemandFab");var ov=document.getElementById("newDemandOverlay");var m=document.getElementById("newDemandModal");var close=document.getElementById("newDemandClose");var cancel=document.getElementById("newDemandCancel");if(!fab||!ov||!m)return;var open=function(){ov.style.display="block";m.style.display="block";try{var i=m.querySelector("input[name=title]");if(i)i.focus();}catch(e){}};var shut=function(){ov.style.display="none";m.style.display="none";};fab.addEventListener("click",open);if(close)close.addEventListener("click",shut);if(cancel)cancel.addEventListener("click",shut);ov.addEventListener("click",shut);document.addEventListener("keydown",function(e){if(e.key==="Escape")shut();});})();';
echo '</script>';

view_footer();
