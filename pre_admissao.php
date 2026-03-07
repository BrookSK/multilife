<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$status = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$selectedId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$allowedStatuses = ['', 'pending', 'confirmed', 'cancelled'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}

// Buscar apenas atribuições confirmadas (atendimentos que completaram cadastro de paciente, profissional e serviço)
$sql = 'SELECT pa.id, pa.demand_id, pa.created_at, pa.status,
               d.title, d.location_city, d.location_state, d.origin_email, d.description,
               pa.specialty, pa.service_type, pa.session_quantity, pa.session_frequency, 
               COALESCE(pa.agreed_value, pa.payment_value) as payment_value,
               pa.agreed_value, pa.authorized_value,
               p.full_name AS patient_name, p.phone_primary AS patient_phone,
               u.name AS professional_name, u.phone AS professional_phone,
               assigned_by.name AS assigned_by_name
        FROM patient_assignments pa
        INNER JOIN demands d ON d.id = pa.demand_id
        INNER JOIN patients p ON p.id = pa.patient_id
        LEFT JOIN users u ON u.id = pa.professional_user_id
        LEFT JOIN users assigned_by ON assigned_by.id = pa.assigned_by_user_id
        WHERE pa.status = \'confirmed\'
        AND pa.approved_at IS NULL';

$where = [];
$params = [];

if ($status !== '' && $status !== 'confirmed') {
    $where[] = 'pa.status = :status';
    $params['status'] = $status;
}

if ($q !== '') {
    $where[] = '(d.title LIKE :q OR pa.specialty LIKE :q OR d.location_city LIKE :q OR p.full_name LIKE :q OR u.name LIKE :q)';
    $params['q'] = '%' . $q . '%';
}

if (count($where) > 0) {
    $sql .= ' AND ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY pa.id DESC LIMIT 80';

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
        $stmt = db()->prepare('
            SELECT pa.*, 
                   d.title, d.location_city, d.location_state, d.origin_email, d.description,
                   patient.name AS patient_name, patient.phone AS patient_phone,
                   prof.name AS professional_name, prof.phone AS professional_phone,
                   assigned_by.name AS assigned_by_name
            FROM patient_assignments pa
            INNER JOIN demands d ON d.id = pa.demand_id
            INNER JOIN users patient ON patient.id = pa.patient_id
            LEFT JOIN users prof ON prof.id = pa.professional_user_id
            LEFT JOIN users assigned_by ON assigned_by.id = pa.assigned_by_user_id
            WHERE pa.id = :id
            LIMIT 1
        ');
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
echo '.paCard{border:1px solid hsl(var(--border));box-shadow:var(--shadow-card);border-radius:calc(var(--radius) + 6px);background:hsl(var(--card));transition:box-shadow .15s ease;cursor:pointer;overflow:hidden}';
echo '.paCard:hover{box-shadow:var(--shadow-card-hover)}';
echo '.paCardBody{padding:18px 20px 22px}';
echo '.paTop{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:12px}';
echo '.paTitle{font-weight:900;color:hsl(var(--foreground))}';
echo '.paSub{margin-top:4px;color:hsl(var(--muted-foreground));font-size:13px}';
echo '.paStepsContainer{margin-top:16px;position:relative}';
echo '.paStepsRow{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;position:relative;z-index:2}';
echo '.paStep{display:flex;flex-direction:column;align-items:center;justify-content:flex-start}';
echo '.paStepDot{width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:900;border:2px solid hsl(var(--border));background:hsl(var(--card));color:hsl(var(--muted-foreground));margin:0 auto}';
echo '.paStepDot.isDone{background:hsl(var(--primary));border-color:hsl(var(--primary));color:hsl(var(--primary-foreground))}';
echo '.paStepDot.isProgress{background:hsla(var(--info)/.12);border-color:hsla(var(--info)/.25);color:hsl(var(--info))}';
echo '.paStepDot.isPending{background:hsla(var(--warning)/.12);border-color:hsla(var(--warning)/.25);color:hsl(var(--warning))}';
echo '.paStepLabel{margin-top:8px;text-align:center;font-size:10px;line-height:1.3;color:hsl(var(--muted-foreground));word-wrap:break-word;max-width:100%}';
echo '.paConnectors{display:grid;grid-template-columns:1fr 2fr 1fr 2fr 1fr 2fr 1fr;align-items:center;position:absolute;top:20px;left:0;right:0;padding:0 10px;z-index:1}';
echo '.paConnSpacer{width:40px}';
echo '.paConn{height:2px;background:hsl(var(--border))}';  
echo '.paConn.isDone{background:hsl(var(--primary))}';

echo '.drawerOverlay{position:fixed;inset:0;background:rgba(0,0,0,.24);z-index:60;display:none}';
echo '.drawer{position:fixed;top:0;right:0;height:100vh;width:480px;max-width:92vw;background:hsl(var(--card));border-left:1px solid hsl(var(--border));z-index:70;transform:translateX(100%);transition:transform .2s ease;box-shadow:var(--shadow-elevated);display:flex;flex-direction:column}';
echo '.drawer.isOpen{transform:translateX(0)}';
echo '.drawerHeader{padding:16px 16px 12px;border-bottom:1px solid hsl(var(--border))}';
echo '.drawerTitle{font-size:16px;font-weight:900}';
echo '.drawerBody{padding:16px;overflow:auto;flex:1 1 auto}';
echo '.tabList{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:14px}';
echo '.tabBtn{border:1px solid hsl(var(--border));background:hsla(var(--secondary)/.50);border-radius:10px;padding:8px 8px;font-size:11px;font-weight:900;color:hsl(var(--muted-foreground));cursor:pointer}';
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
echo '<input name="q" value="' . h($q) . '" placeholder="Buscar paciente...">';
echo '</div>';

echo '<select name="status" style="width:180px">';
$opts = [
    '' => 'Todos',
    'confirmed' => 'Confirmado',
    'pending' => 'Pendente',
    'cancelled' => 'Cancelado',
];
foreach ($opts as $k => $lab) {
    $sel = ($status === $k) ? ' selected' : '';
    echo '<option value="' . h($k) . '"' . $sel . '>' . h($lab) . '</option>';
}
echo '</select>';

echo '<button class="btn" type="submit">Filtrar</button>';
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
    echo '<div style="font-size:12px;font-weight:900;color:hsl(var(--muted-foreground))">' . $completed . '/4 etapas</div>';
    echo '</div>';

    echo '<div class="paStepsContainer">';
    
    echo '<div class="paConnectors">';
    echo '<div class="paConnSpacer"></div>';
    for ($i = 0; $i < 3; $i++) {
        $c = 'paConn';
        if ((string)$stArr[$i] === 'done') {
            $c .= ' isDone';
        }
        echo '<div class="' . h($c) . '"></div>';
        echo '<div class="paConnSpacer"></div>';
    }
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
    
    echo '</div>';

    echo '</div>';
    echo '</a>';
}

echo '</div>';

$drawerOpen = $selected !== null;
echo '<div class="drawerOverlay" id="drawerOverlay"' . ($drawerOpen ? ' style="display:block"' : '') . '></div>';

echo '<aside class="drawer' . ($drawerOpen ? ' isOpen' : '') . '" id="drawer">';

echo '<div class="drawerHeader">';
echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px">';
echo '<div class="drawerTitle">' . h($selected ? (string)($selected['title'] ?? '') : '') . '</div>';

$closeBtnUrl = '/pre_admissao.php';
if ($q !== '' || $status !== '') {
    $closeBtnParams = [];
    if ($q !== '') $closeBtnParams[] = 'q=' . urlencode($q);
    if ($status !== '') $closeBtnParams[] = 'status=' . urlencode($status);
    $closeBtnUrl .= '?' . implode('&', $closeBtnParams);
}
echo '<a class="btn" href="' . h($closeBtnUrl) . '" id="drawerCloseBtn" style="height:34px">Fechar</a>';
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
    $assignmentId = (int)$selected['id'];
    $demandId = (int)($selected['demand_id'] ?? 0);
    $loc = trim((string)($selected['location_city'] ?? ''));
    $uf = trim((string)($selected['location_state'] ?? ''));
    $locTxt = $loc !== '' ? ($loc . ($uf !== '' ? '/' . $uf : '')) : '-';
    
    $patientName = (string)($selected['patient_name'] ?? '-');
    $patientPhone = (string)($selected['patient_phone'] ?? '-');
    $professionalName = (string)($selected['professional_name'] ?? '-');
    $professionalPhone = (string)($selected['professional_phone'] ?? '-');
    $specialty = (string)($selected['specialty'] ?? '-');
    $serviceType = (string)($selected['service_type'] ?? '-');
    $sessionQty = (int)($selected['session_quantity'] ?? 0);
    $sessionFreq = (string)($selected['session_frequency'] ?? '-');
    $paymentValue = (float)($selected['payment_value'] ?? 0);

    echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
    echo '<a class="btn" href="/demands_view.php?id=' . $demandId . '">Abrir card</a>';
    echo '<a class="btn" href="/demands_edit.php?id=' . $demandId . '">Editar card</a>';
    echo '</div>';

    echo '<div style="height:12px"></div>';
    
    echo '<div style="background:hsla(var(--primary)/.08);padding:12px;border-radius:8px;margin-bottom:16px">';
    echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));margin-bottom:8px">Atribuição confirmada</div>';
    echo '<div style="font-weight:600">Paciente: ' . h($patientName) . '</div>';
    echo '<div style="font-size:13px;color:hsl(var(--muted-foreground))">Telefone: ' . h($patientPhone) . '</div>';
    echo '<div style="font-weight:600;margin-top:8px">Profissional: ' . h($professionalName) . '</div>';
    echo '<div style="font-size:13px;color:hsl(var(--muted-foreground))">Telefone: ' . h($professionalPhone) . '</div>';
    echo '</div>';

    echo '<div class="tabPanel isActive" data-panel="faturamento">';
    echo '<div style="display:grid;gap:12px">';
    echo '<label>Empresa/Origem<input value="' . h((string)($selected['origin_email'] ?? '')) . '" readonly></label>';
    echo '<label>Local<input value="' . h($locTxt) . '" readonly></label>';
    echo '<label>Especialidade<input value="' . h($specialty) . '" readonly></label>';
    echo '<label>Tipo de Serviço<input value="' . h($serviceType) . '" readonly></label>';
    echo '<label>Sessões<input value="' . h($sessionQty . 'x - ' . $sessionFreq) . '" readonly></label>';
    echo '<label>Valor por Sessão<input value="R$ ' . h(number_format($paymentValue, 2, ',', '.')) . '" readonly></label>';
    echo '<label>Plano<input placeholder="Plano do convênio"></label>';
    echo '<label>Dados financeiros<input placeholder="Nº contrato / autorização"></label>';
    echo '</div>';
    echo '</div>';

    echo '<div class="tabPanel" data-panel="whatsapp">';
    echo '<div style="display:grid;gap:12px">';
    echo '<label>Paciente<input value="' . h($patientName . ' - ' . $patientPhone) . '" readonly></label>';
    echo '<label>Profissional<input value="' . h($professionalName . ' - ' . $professionalPhone) . '" readonly></label>';
    echo '<div style="margin-top:8px;padding:12px;background:hsla(var(--info)/.08);border-radius:6px;font-size:13px;color:hsl(var(--muted-foreground))">';
    echo '💬 Use o Chat ao Vivo para enviar mensagens de confirmação ao paciente e profissional.';
    echo '</div>';
    echo '</div>';
    echo '</div>';

    echo '<div class="tabPanel" data-panel="prontuario">';
    echo '<div style="display:grid;gap:12px">';
    echo '<label>Paciente<input value="' . h($patientName) . '" readonly></label>';
    echo '<label>Diagnóstico principal<input placeholder="CID / Diagnóstico"></label>';
    echo '<label>Medicamentos<input placeholder="Medicações em uso"></label>';
    echo '<label>Observações clínicas<textarea placeholder="Notas" rows="3"></textarea></label>';
    echo '</div>';
    echo '</div>';

    echo '<div class="tabPanel" data-panel="profissional">';
    echo '<div style="display:grid;gap:12px">';
    echo '<label>Profissional vinculado<input value="' . h($professionalName) . '" readonly></label>';
    echo '<label>Especialidade<input value="' . h($specialty) . '" readonly></label>';
    echo '<label>Telefone<input value="' . h($professionalPhone) . '" readonly></label>';
    echo '<label>COREN/CRM<input placeholder="Registro profissional"></label>';
    echo '</div>';
    echo '</div>';

    echo '<div style="height:14px"></div>';
    
    // Botão de aprovar atendimento
    echo '<form method="post" action="/pre_admissao_approve.php" style="margin-top:20px">';
    echo '<input type="hidden" name="assignment_id" value="' . $assignmentId . '">';
    echo '<input type="hidden" name="demand_id" value="' . $demandId . '">';
    echo '<button class="btn btnPrimary" type="submit" style="width:100%">✅ Aprovar Atendimento</button>';
    echo '</form>';
}

echo '</div>';

echo '</aside>';

echo '<script>';
echo '(function(){var drawer=document.getElementById("drawer");var overlay=document.getElementById("drawerOverlay");if(!drawer||!overlay)return;';
echo 'var open=' . ($drawerOpen ? 'true' : 'false') . ';';
echo 'var setOpen=function(v){open=v; if(v){drawer.classList.add("isOpen"); overlay.style.display="block";} else {drawer.classList.remove("isOpen"); overlay.style.display="none";}};';
$closeUrl = '/pre_admissao.php';
if ($q !== '' || $status !== '') {
    $closeParams = [];
    if ($q !== '') $closeParams[] = 'q=' . urlencode($q);
    if ($status !== '') $closeParams[] = 'status=' . urlencode($status);
    $closeUrl .= '?' . implode('&', $closeParams);
}
echo 'overlay.addEventListener("click", function(){window.location.href="' . h($closeUrl) . '";});';
echo 'var tabBtns=drawer.querySelectorAll(".tabBtn"); var panels=drawer.querySelectorAll(".tabPanel");';
echo 'var activate=function(key){tabBtns.forEach(function(b){b.classList.toggle("isActive", b.getAttribute("data-tab")===key);}); panels.forEach(function(p){p.classList.toggle("isActive", p.getAttribute("data-panel")===key);});};';
echo 'tabBtns.forEach(function(b){b.addEventListener("click", function(){activate(b.getAttribute("data-tab"));});});';
echo '})();';
echo '</script>';

view_footer();
