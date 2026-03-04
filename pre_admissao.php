<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$status = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$selectedId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$allowedStatuses = ['', 'aguardando_captacao', 'tratamento_manual', 'em_captacao', 'admitido', 'cancelado'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}

$sql = 'SELECT d.id, d.title, d.location_city, d.location_state, d.specialty, d.status, d.assumed_by_user_id, d.created_at, d.origin_email, d.description,
               u.name AS assumed_by_name
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

$sql .= ' ORDER BY d.id DESC LIMIT 80';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$selected = null;
if ($selectedId > 0) {
    foreach ($rows as $r) {
        if ((int)$r['id'] === $selectedId) {
            $selected = $r;
            break;
        }
    }

    if ($selected === null) {
        $stmt = db()->prepare('SELECT d.*, u.name AS assumed_by_name FROM demands d LEFT JOIN users u ON u.id = d.assumed_by_user_id WHERE d.id = :id LIMIT 1');
        $stmt->execute(['id' => $selectedId]);
        $selected = $stmt->fetch() ?: null;
    }
}

view_header('Pré-admissão de Pacientes');

echo '<style>';
echo '.paFilters{display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin-bottom:18px}';
echo '.paSearch{position:relative;flex:1;max-width:420px}';
echo '.paSearchIcon{position:absolute;left:12px;top:50%;transform:translateY(-50%);width:16px;height:16px;border-radius:6px;background:hsla(var(--primary)/.12)}';
echo '.paSearch input{padding-left:36px}';
echo '.paList{display:flex;flex-direction:column;gap:12px}';
echo '.paCard{border:1px solid hsl(var(--border));box-shadow:var(--shadow-card);border-radius:calc(var(--radius) + 6px);background:hsl(var(--card));transition:box-shadow .15s ease;cursor:pointer}';
echo '.paCard:hover{box-shadow:var(--shadow-card-hover)}';
echo '.paCardBody{padding:16px}';
echo '.paTop{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:12px}';
echo '.paTitle{font-weight:800;color:hsl(var(--foreground))}';
echo '.paSub{margin-top:4px;color:hsl(var(--muted-foreground));font-size:13px}';
echo '.paStepsRow{display:flex;align-items:flex-start;gap:10px}';
echo '.paStep{flex:1;min-width:0;display:flex;flex-direction:column;align-items:center}';
echo '.paStepDot{width:32px;height:32px;border-radius:999px;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:900;border:1px solid hsl(var(--border));background:hsl(var(--card));color:hsl(var(--muted-foreground))}';
echo '.paStepDot.isDone{background:hsl(var(--primary));border-color:hsl(var(--primary));color:hsl(var(--primary-foreground))}';
echo '.paStepDot.isProgress{background:hsla(var(--info)/.12);border-color:hsla(var(--info)/.25);color:hsl(var(--info))}';
echo '.paStepDot.isPending{background:hsla(var(--warning)/.12);border-color:hsla(var(--warning)/.25);color:hsl(var(--warning))}';
echo '.paStepLabel{margin-top:6px;text-align:center;font-size:10px;line-height:1.2;color:hsl(var(--muted-foreground))}';
echo '.paConnectors{display:flex;align-items:center;margin-top:-42px;margin-bottom:18px;padding:0 16%}';
echo '.paConn{flex:1;height:2px;background:hsl(var(--border))}';
echo '.paConn.isDone{background:hsl(var(--primary))}';

echo '.drawerOverlay{position:fixed;inset:0;background:rgba(0,0,0,.24);backdrop-filter:blur(2px);z-index:60;display:none}';
echo '.drawer{position:fixed;top:0;right:0;height:100vh;width:480px;max-width:92vw;background:hsl(var(--card));border-left:1px solid hsl(var(--border));z-index:70;transform:translateX(100%);transition:transform .2s ease;box-shadow:var(--shadow-elevated);display:flex;flex-direction:column}';
echo '.drawer.isOpen{transform:translateX(0)}';
echo '.drawerHeader{padding:16px 16px 12px;border-bottom:1px solid hsl(var(--border))}';
echo '.drawerTitle{font-size:16px;font-weight:900}';
echo '.drawerBody{padding:16px;overflow:auto;flex:1 1 auto}';
echo '.tabList{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:14px}';
echo '.tabBtn{border:1px solid hsl(var(--border));background:hsla(var(--secondary)/.50);border-radius:10px;padding:8px 8px;font-size:11px;font-weight:800;color:hsl(var(--muted-foreground));cursor:pointer}';
echo '.tabBtn.isActive{background:hsl(var(--primary));border-color:hsl(var(--primary));color:hsl(var(--primary-foreground))}';
echo '.tabPanel{display:none;margin-top:14px}';
echo '.tabPanel.isActive{display:block}';
echo '</style>';

$steps = [
    ['key' => 'faturamento', 'label' => 'Faturamento/Login'],
    ['key' => 'whatsapp', 'label' => 'Confirmação WhatsApp'],
    ['key' => 'prontuario', 'label' => 'Prontuário'],
    ['key' => 'profissional', 'label' => 'Ficha Profissional'],
];

$computeStatuses = static function (string $demandStatus): array {
    if ($demandStatus === 'admitido') {
        return ['done', 'done', 'done', 'done'];
    }
    if ($demandStatus === 'em_captacao') {
        return ['done', 'progress', 'pending', 'pending'];
    }
    if ($demandStatus === 'tratamento_manual') {
        return ['progress', 'pending', 'pending', 'pending'];
    }
    if ($demandStatus === 'cancelado') {
        return ['pending', 'pending', 'pending', 'pending'];
    }
    return ['pending', 'pending', 'pending', 'pending'];
};

$labelStatus = static function (string $st): string {
    if ($st === 'done') {
        return 'Concluído';
    }
    if ($st === 'progress') {
        return 'Em andamento';
    }
    return 'Pendente';
};

echo '<div class="paFilters">';

echo '<form method="get" action="/pre_admissao.php" class="paFilters">';
echo '<div class="paSearch">';
echo '<span class="paSearchIcon" aria-hidden="true"></span>';
echo '<input name="q" value="' . h($q) . '" placeholder="Buscar paciente..." style="height:36px">';
echo '</div>';

echo '<select name="status" style="width:180px;height:36px">';
$opts = [
    '' => 'Todos',
    'tratamento_manual' => 'Tratamento Manual',
    'em_captacao' => 'Em Captação',
    'admitido' => 'Admitido',
    'aguardando_captacao' => 'Aguardando',
    'cancelado' => 'Cancelado',
];
foreach ($opts as $k => $lab) {
    $sel = ($status === $k) ? ' selected' : '';
    echo '<option value="' . h($k) . '"' . $sel . '>' . h($lab) . '</option>';
}
echo '</select>';

echo '<button class="btn" type="submit" style="height:36px">Filtrar</button>';
echo '</form>';

echo '</div>';

echo '<div class="paList">';
foreach ($rows as $r) {
    $stArr = $computeStatuses((string)$r['status']);
    $completed = 0;
    foreach ($stArr as $s) {
        if ($s === 'done') {
            $completed++;
        }
    }

    $loc = trim((string)($r['location_city'] ?? ''));
    $uf = trim((string)($r['location_state'] ?? ''));
    $locTxt = $loc !== '' ? ($loc . ($uf !== '' ? '/' . $uf : '')) : '-';

    $empresa = (string)($r['origin_email'] ?? '');
    if ($empresa === '') {
        $empresa = $locTxt;
    }

    $href = '/pre_admissao.php?id=' . (int)$r['id'];
    if ($q !== '') {
        $href .= '&q=' . urlencode($q);
    }
    if ($status !== '') {
        $href .= '&status=' . urlencode($status);
    }

    echo '<a class="paCard" href="' . h($href) . '" style="color:inherit;text-decoration:none">';
    echo '<div class="paCardBody">';

    echo '<div class="paTop">';
    echo '<div>';
    echo '<div class="paTitle">' . h((string)$r['title']) . '</div>';
    echo '<div class="paSub">' . h($empresa) . '</div>';
    echo '</div>';
    echo '<div style="font-size:12px;font-weight:800;color:hsl(var(--muted-foreground))">' . $completed . '/4 etapas</div>';
    echo '</div>';

    echo '<div class="paStepsRow">';
    for ($i = 0; $i < 4; $i++) {
        $st = (string)$stArr[$i];
        $cls = 'paStepDot';
        if ($st === 'done') {
            $cls .= ' isDone';
        } elseif ($st === 'progress') {
            $cls .= ' isProgress';
        } else {
            $cls .= ' isPending';
        }

        echo '<div class="paStep">';
        echo '<div class="' . h($cls) . '">' . h(mb_substr((string)$steps[$i]['label'], 0, 1)) . '</div>';
        echo '<div class="paStepLabel">' . h((string)$steps[$i]['label']) . '</div>';
        echo '</div>';
    }
    echo '</div>';

    echo '<div class="paConnectors">';
    for ($i = 0; $i < 3; $i++) {
        $c = 'paConn';
        if ((string)$stArr[$i] === 'done') {
            $c .= ' isDone';
        }
        echo '<div class="' . h($c) . '"></div>';
    }
    echo '</div>';

    echo '</div>';
    echo '</a>';
}

echo '</div>';

$drawerOpen = $selected !== null;
echo '<div class="drawerOverlay" id="drawerOverlay"' . ($drawerOpen ? ' style="display:block"' : '') . '></div>';

echo '<aside class="drawer" id="drawer"' . ($drawerOpen ? ' class="drawer isOpen"' : '') . '>';

echo '<div class="drawerHeader">';
echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px">';
echo '<div class="drawerTitle">' . h($selected ? (string)($selected['title'] ?? '') : '') . '</div>';

echo '<a class="btn" href="/pre_admissao.php" id="drawerCloseBtn" style="height:34px">Fechar</a>';
echo '</div>';

echo '<div class="tabList" id="tabList">';
$tabs = [
    ['key' => 'faturamento', 'label' => 'Faturamento'],
    ['key' => 'whatsapp', 'label' => 'WhatsApp'],
    ['key' => 'prontuario', 'label' => 'Prontuário'],
    ['key' => 'profissional', 'label' => 'Profissional'],
];
foreach ($tabs as $idx => $t) {
    $isActive = $idx === 0;
    echo '<button type="button" class="tabBtn' . ($isActive ? ' isActive' : '') . '" data-tab="' . h($t['key']) . '">' . h($t['label']) . '</button>';
}
echo '</div>';

echo '</div>';

echo '<div class="drawerBody">';

if ($selected) {
    $id = (int)$selected['id'];
    $loc = trim((string)($selected['location_city'] ?? ''));
    $uf = trim((string)($selected['location_state'] ?? ''));
    $locTxt = $loc !== '' ? ($loc . ($uf !== '' ? '/' . $uf : '')) : '-';

    echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
    echo '<a class="btn" href="/demands_view.php?id=' . $id . '">Abrir card</a>';
    echo '<a class="btn" href="/demands_edit.php?id=' . $id . '">Editar card</a>';
    echo '</div>';

    echo '<div style="height:12px"></div>';

    echo '<div class="tabPanel isActive" data-panel="faturamento">';
    echo '<div style="display:grid;gap:12px">';
    echo '<label>Empresa/Origem<input value="' . h((string)($selected['origin_email'] ?? '')) . '" readonly></label>';
    echo '<label>Local<input value="' . h($locTxt) . '" readonly></label>';
    echo '<label>Plano<input placeholder="Plano do convênio"></label>';
    echo '<label>Dados financeiros<input placeholder="Nº contrato / autorização"></label>';
    echo '</div>';
    echo '</div>';

    echo '<div class="tabPanel" data-panel="whatsapp">';
    echo '<div class="card" style="box-shadow:var(--shadow-card)">';
    echo '<div style="text-align:center">';
    echo '<div style="margin:10px auto 6px;width:42px;height:42px;border-radius:999px;background:hsla(var(--primary)/.10);display:flex;align-items:center;justify-content:center;color:hsl(var(--primary));font-weight:900">WA</div>';
    echo '<div style="font-weight:900">Confirmação</div>';
    echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:13px">Use o card de Captação para envio/controle de mensagens.</div>';
    echo '</div>';
    echo '</div>';
    echo '</div>';

    echo '<div class="tabPanel" data-panel="prontuario">';
    echo '<div style="display:grid;gap:12px">';
    echo '<label>Diagnóstico principal<input placeholder="CID / Diagnóstico"></label>';
    echo '<label>Medicamentos<input placeholder="Medicações em uso"></label>';
    echo '<label>Observações clínicas<textarea placeholder="Notas" rows="3"></textarea></label>';
    echo '</div>';
    echo '</div>';

    echo '<div class="tabPanel" data-panel="profissional">';
    echo '<div style="display:grid;gap:12px">';
    echo '<label>Profissional vinculado<input placeholder="Nome do profissional"></label>';
    echo '<label>Especialidade<input placeholder="Especialidade"></label>';
    echo '<label>COREN/CRM<input placeholder="Registro"></label>';
    echo '</div>';
    echo '</div>';

    echo '<div style="height:14px"></div>';

    echo '<form method="post" action="/demands_set_status_post.php" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">';
    echo '<input type="hidden" name="id" value="' . $id . '">';
    echo '<label style="min-width:240px">Status<select name="status">';
    $allowed = ['aguardando_captacao', 'tratamento_manual', 'em_captacao', 'admitido', 'cancelado'];
    foreach ($allowed as $st) {
        $sel = ((string)$selected['status'] === $st) ? ' selected' : '';
        echo '<option value="' . h($st) . '"' . $sel . '>' . h($st) . '</option>';
    }
    echo '</select></label>';
    echo '<label style="flex:1;min-width:220px">Observação<input name="note" placeholder="Observação (opcional)"></label>';
    echo '<button class="btn btnPrimary" type="submit" style="height:38px">Salvar alterações</button>';
    echo '</form>';
}

echo '</div>';

echo '</aside>';

echo '<script>';
echo '(function(){var drawer=document.getElementById("drawer");var overlay=document.getElementById("drawerOverlay");if(!drawer||!overlay)return;';
echo 'var open=' . ($drawerOpen ? 'true' : 'false') . ';';
echo 'var setOpen=function(v){open=v; if(v){drawer.classList.add("isOpen"); overlay.style.display="block";} else {drawer.classList.remove("isOpen"); overlay.style.display="none";}};';
echo 'overlay.addEventListener("click", function(){window.location.href="/pre_admissao.php";});';
echo 'var tabBtns=drawer.querySelectorAll(".tabBtn"); var panels=drawer.querySelectorAll(".tabPanel");';
echo 'var activate=function(key){tabBtns.forEach(function(b){b.classList.toggle("isActive", b.getAttribute("data-tab")===key);}); panels.forEach(function(p){p.classList.toggle("isActive", p.getAttribute("data-panel")===key);});};';
echo 'tabBtns.forEach(function(b){b.addEventListener("click", function(){activate(b.getAttribute("data-tab"));});});';
echo '})();';
echo '</script>';

view_footer();
