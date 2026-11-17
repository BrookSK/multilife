<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/insurer_orientations_helper.php';

auth_require_login();
rbac_require_permission('demands.manage');

insurer_orientations_ensure_schema();
clients_ensure_schema(); // polo_id em demands/patient_assignments/health_insurers

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$status = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$selectedId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$allowedStatuses = ['', 'pending', 'confirmed', 'cancelled'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}

// Buscar apenas atribuições confirmadas (atendimentos que completaram cadastro de paciente, profissional e serviço)
$sql = 'SELECT pa.id, pa.demand_id, pa.patient_id, pa.created_at, pa.status,
               d.title, d.location_city, d.location_state, d.origin_email, d.description,
               pa.specialty, pa.service_type, pa.session_quantity, pa.session_frequency, 
               COALESCE(pa.agreed_value, pa.payment_value) as payment_value,
               pa.agreed_value, pa.authorized_value,
               p.full_name AS patient_name, p.phone_primary AS patient_phone,
               u.name AS professional_name, u.phone AS professional_phone,
               assigned_by.name AS assigned_by_name,
               hi.name AS health_insurer_name
        FROM patient_assignments pa
        INNER JOIN demands d ON d.id = pa.demand_id
        INNER JOIN patients p ON p.id = pa.patient_id
        LEFT JOIN users u ON u.id = pa.professional_user_id
        LEFT JOIN users assigned_by ON assigned_by.id = pa.assigned_by_user_id
        LEFT JOIN health_insurers hi ON hi.id = pa.health_insurer_id
        WHERE pa.status = \'confirmed\'
        AND pa.approved_at IS NULL';

$where = [];
$params = [];

if ($status !== '' && $status !== 'confirmed') {
    $where[] = 'pa.status = :status';
    $params['status'] = $status;
}

if ($q !== '') {
    $where[] = '(d.title LIKE :q1 OR pa.specialty LIKE :q2 OR d.location_city LIKE :q3 OR p.full_name LIKE :q4 OR u.name LIKE :q5)';
    $qLike = '%' . $q . '%';
    $params['q1'] = $qLike;
    $params['q2'] = $qLike;
    $params['q3'] = $qLike;
    $params['q4'] = $qLike;
    $params['q5'] = $qLike;
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
                   d.polo_id AS demand_polo_id, d.health_insurer_id AS demand_insurer_id,
                   p.full_name AS patient_name, p.phone_primary AS patient_phone,
                   prof.name AS professional_name, prof.phone AS professional_phone,
                   assigned_by.name AS assigned_by_name,
                   hi.name AS health_insurer_name
            FROM patient_assignments pa
            INNER JOIN demands d ON d.id = pa.demand_id
            INNER JOIN patients p ON p.id = pa.patient_id
            LEFT JOIN users prof ON prof.id = pa.professional_user_id
            LEFT JOIN users assigned_by ON assigned_by.id = pa.assigned_by_user_id
            LEFT JOIN health_insurers hi ON hi.id = pa.health_insurer_id
            WHERE pa.id = :id
            LIMIT 1
        ');
        $stmt->execute(['id' => $selectedId]);
        $selected = $stmt->fetch() ?: null;
    }
}

view_header('Pré-admissão de Pacientes');

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Pré-admissão de Pacientes</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Aprove e gerencie pacientes aguardando admissão no sistema</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

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

echo '.drawerOverlay{position:fixed;inset:0;background:rgba(0,0,0,.24);z-index:10010;display:none}';
echo '.drawer{position:fixed;top:0;right:0;height:100vh;width:480px;max-width:92vw;background:hsl(var(--card));border-left:1px solid hsl(var(--border));z-index:10020;transform:translateX(100%);transition:transform .2s ease;box-shadow:var(--shadow-elevated);display:flex;flex-direction:column}';
echo '.drawer.isOpen{transform:translateX(0)}';
echo '.drawer.isOpen ~ script + div[style*="position:fixed"]{display:none !important}';
echo '.drawerHeader{padding:16px 16px 12px;border-bottom:1px solid hsl(var(--border));position:relative;z-index:10030}';echo '.drawerTitle{font-size:16px;font-weight:900}';
echo '.drawerBody{padding:16px;overflow:auto;flex:1 1 auto}';
echo '.tabList{display:grid;grid-template-columns:repeat(5,1fr);gap:8px;margin-top:14px}';
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

echo '<form method="get" action="/pre_admissao.php" class="paFilters" style="margin-top:20px">';
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
echo '</section>';

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
echo '<a class="btn" href="' . h($closeBtnUrl) . '" id="drawerCloseBtn" style="height:34px;position:relative;z-index:10030">✕ Fechar</a>';
echo '</div>';

echo '<div class="tabList" id="tabList">';
$tabs = [
    ['key' => 'faturamento', 'label' => 'Faturamento'],
    ['key' => 'agendamento', 'label' => 'Agendamento'],
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

    // Buscar dados da proposta/autorização original (se existir)
    $authRequest = null;
    try {
        $stmtAuth = db()->prepare("
            SELECT ar.start_date, ar.start_time, ar.end_time, ar.frequency, ar.frequency_details,
                   ar.sessions_per_week, ar.total_sessions, ar.duration_weeks, ar.is_indefinite,
                   ar.operator_email, ar.operator_name, ar.proposal_value, ar.agreed_value
            FROM authorization_requests ar
            WHERE ar.demand_id = ? AND ar.patient_id = (SELECT patient_id FROM patient_assignments WHERE id = ?)
            ORDER BY ar.id DESC LIMIT 1
        ");
        $stmtAuth->execute([$demandId, $assignmentId]);
        $authRequest = $stmtAuth->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
    
    // Detectar operadora automaticamente pelo domínio do e-mail de origem
    $insurerName = $selected['health_insurer_name'] ?? '';
    $detectedInsurer = $insurerName ?: 'Não informado';
    if ($detectedInsurer === 'Não informado' || empty($detectedInsurer)) {
        $originEmail = (string)($selected['origin_email'] ?? '');
        if (!empty($originEmail) && strpos($originEmail, '@') !== false) {
            $emailDomain = strtolower(substr($originEmail, strpos($originEmail, '@') + 1));
            try {
                $stmtIns = db()->prepare("SELECT name FROM health_insurers WHERE is_active = 1 AND email_domain = ? LIMIT 1");
                $stmtIns->execute([$emailDomain]);
                $insRow = $stmtIns->fetch();
                if ($insRow) {
                    $detectedInsurer = $insRow['name'];
                } else {
                    // Fallback: buscar por nome baseado no domínio
                    $domainBase = explode('.', $emailDomain)[0];
                    if (strlen($domainBase) >= 3) {
                        $stmtIns2 = db()->prepare("SELECT name FROM health_insurers WHERE is_active = 1 AND LOWER(name) LIKE ? LIMIT 1");
                        $stmtIns2->execute(['%' . $domainBase . '%']);
                        $insRow2 = $stmtIns2->fetch();
                        if ($insRow2) $detectedInsurer = $insRow2['name'];
                        else $detectedInsurer = ucfirst($domainBase) . ' (detectado)';
                    }
                }
            } catch (Exception $e) {}
        }
    }

    echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
    echo '<a class="btn" href="/demands_view.php?id=' . $demandId . '">Abrir card</a>';
    echo '<a class="btn" href="/demands_edit.php?id=' . $demandId . '">Editar card</a>';
    echo '</div>';

    echo '<div style="height:12px"></div>';
    
    echo '<div style="background:hsla(var(--primary)/.08);padding:12px;border-radius:8px;margin-bottom:16px">';
    echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));margin-bottom:8px">Atribuição confirmada</div>';
    // Endereço e contatos do paciente (vão na mensagem ao profissional ao aprovar).
    $paContact = patient_contact_fetch((int)($selected['patient_id'] ?? 0));
    $paAddress = patient_format_address($paContact);
    $paPhones = patient_format_phones($paContact);
    $paFamily = patient_format_family($paContact);
    $paMissing = '<span style="color:hsl(var(--warning));font-weight:600">não informado</span>';
    echo '<div style="font-weight:600">Paciente: ' . h($patientName) . '</div>';
    echo '<div style="font-size:13px;color:hsl(var(--muted-foreground))">Telefone: ' . ($paPhones !== '' ? h($paPhones) : $paMissing) . '</div>';
    echo '<div style="font-size:13px;color:hsl(var(--muted-foreground))">Endereço: ' . ($paAddress !== '' ? h($paAddress) : $paMissing) . '</div>';
    echo '<div style="font-size:13px;color:hsl(var(--muted-foreground))">Contato da família: ' . ($paFamily !== '' ? h($paFamily) : $paMissing) . '</div>';
    if ($paAddress === '' || ($paPhones === '' && $paFamily === '')) {
        echo '<div style="font-size:12px;margin-top:6px"><a href="/patients_edit.php?id=' . (int)($selected['patient_id'] ?? 0) . '" target="_blank">Completar o cadastro do paciente</a> antes de aprovar: estes dados vão na mensagem ao profissional.</div>';
    }
    echo '<div style="font-weight:600;margin-top:8px">Profissional: ' . h($professionalName) . '</div>';
    echo '<div style="font-size:13px;color:hsl(var(--muted-foreground))">Telefone: ' . h($professionalPhone) . '</div>';
    echo '<div style="font-weight:600;margin-top:8px">Operadora / Cliente: ' . h($detectedInsurer) . '</div>';
    echo '</div>';

    // Buscar operadoras para dropdown
    $insurersStmt = db()->query("SELECT id, name, client_id, polo_id FROM health_insurers WHERE is_active = 1 ORDER BY name ASC");
    $insurersList = $insurersStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Detectar ID da operadora: a do atendimento/card; senão, pelo nome identificado.
    $detectedInsurerId = (int)($selected['health_insurer_id'] ?? 0) ?: (int)($selected['demand_insurer_id'] ?? 0);
    if ($detectedInsurerId === 0 && $detectedInsurer !== 'Não informado' && !empty($detectedInsurer)) {
        foreach ($insurersList as $ins) {
            if (strcasecmp($ins['name'], $detectedInsurer) === 0) {
                $detectedInsurerId = (int)$ins['id'];
                break;
            }
        }
    }

    echo '<div class="tabPanel isActive" data-panel="faturamento">';
    echo '<div style="display:grid;gap:12px">';
    
    // Dropdown de operadora (pré-seleciona se identificada)
    echo '<label>Operadora / Cliente *<select name="health_insurer_id" form="approveForm" required style="font-weight:600">';
    echo '<option value="">Selecione a operadora / cliente...</option>';
    foreach ($insurersList as $ins) {
        $sel = ((int)$ins['id'] === $detectedInsurerId) ? ' selected' : '';
        echo '<option value="' . (int)$ins['id'] . '" data-polo="' . (int)($ins['polo_id'] ?? 0) . '" data-client="' . (int)($ins['client_id'] ?? 0) . '"' . $sel . '>' . h($ins['name']) . '</option>';
    }
    echo '</select></label>';

    // Polo do atendimento (cliente -> polo -> operadora). Pré-seleciona o do
    // atendimento, depois o do card e por fim o polo padrão da operadora.
    $paPolos = polos_list(null, true);
    $curPoloId = (int)($selected['polo_id'] ?? 0) ?: (int)($selected['demand_polo_id'] ?? 0);
    if ($curPoloId === 0 && $detectedInsurerId > 0) {
        foreach ($insurersList as $ins) {
            if ((int)$ins['id'] === $detectedInsurerId) { $curPoloId = (int)($ins['polo_id'] ?? 0); break; }
        }
    }
    echo '<label>Polo<select name="polo_id" form="approveForm" id="paPoloSelect">';
    echo '<option value="">— Sem polo —</option>';
    foreach ($paPolos as $po) {
        $sel = (int)$po['id'] === $curPoloId ? ' selected' : '';
        echo '<option value="' . (int)$po['id'] . '" data-client="' . (int)($po['client_id'] ?? 0) . '"' . $sel . '>' . h(polos_label($po)) . '</option>';
    }
    echo '</select></label>';
    echo '<script>(function(){var i=document.querySelector("select[name=health_insurer_id][form=approveForm]"),p=document.getElementById("paPoloSelect");'
        . 'if(!i||!p)return;i.addEventListener("change",function(){var o=i.options[i.selectedIndex];if(!o)return;var dp=o.getAttribute("data-polo");if(dp&&dp!=="0"){p.value=dp;}});})();</script>';
    
    echo '<label>E-mail Origem<input value="' . h((string)($selected['origin_email'] ?? '')) . '" readonly></label>';
    echo '<label>Local<input value="' . h($locTxt) . '" readonly></label>';
    echo '<label>Especialidade<input value="' . h($specialty) . '" readonly></label>';
    echo '<label>Tipo de Serviço<input value="' . h($serviceType) . '" readonly></label>';
    echo '<label>Sessões<input value="' . h($sessionQty . 'x - ' . $sessionFreq) . '" readonly></label>';
    echo '<label>Valor por Sessão<input value="R$ ' . h(number_format($paymentValue, 2, ',', '.')) . '" readonly></label>';
    echo '<label>Dados financeiros<input placeholder="Nº contrato / autorização"></label>';
    echo '</div>';
    echo '</div>';

    echo '<div class="tabPanel" data-panel="agendamento">';
    echo '<div style="display:grid;gap:12px">';
    
    // Pré-carregar dados da proposta (authorization_request) se existirem
    $preStartDate = $authRequest['start_date'] ?? '';
    $preStartTime = !empty($authRequest['start_time']) ? substr($authRequest['start_time'], 0, 5) : '';
    $preFrequency = $authRequest['frequency'] ?? '';
    $preSessionsPerWeek = (int)($authRequest['sessions_per_week'] ?? 1);
    $preDurationWeeks = (int)($authRequest['duration_weeks'] ?? 0);
    $preTotalSessions = (int)($authRequest['total_sessions'] ?? $sessionQty);
    $preIsIndefinite = (int)($authRequest['is_indefinite'] ?? 0);
    // Término por DATA (antes era "duração em semanas"). Se a proposta já tinha
    // semanas, sugere a data equivalente; o total de sessões é recalculado.
    $preEndDate = '';
    if ($preStartDate !== '' && $preDurationWeeks > 0 && strtotime((string)$preStartDate) !== false) {
        $preEndDate = date('Y-m-d', strtotime((string)$preStartDate . ' +' . ($preDurationWeeks * 7 - 1) . ' days'));
    }
    
    // Sem "hora fim": o término do atendimento quase nunca é conhecido.
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px">';
    echo '<label>Data de Início *<input type="date" name="start_date" id="paStartDate" form="approveForm" value="' . h($preStartDate) . '" required></label>';
    echo '<label>Hora Início *<input type="time" name="start_time" form="approveForm" value="' . h($preStartTime ?: '08:00') . '" required></label>';
    echo '</div>';
    
    // Opção de tempo indefinido (toggle switch)
    echo '<div id="paIndefiniteBox" style="margin:12px 0;padding:14px 16px;background:hsla(var(--warning)/.10);border:1px solid hsla(var(--warning)/.35);border-radius:10px;transition:background .15s,border-color .15s">';
    echo '<label for="paIndefinite" style="display:flex;align-items:flex-start;gap:12px;cursor:pointer;margin:0">';
    // Switch visual
    echo '<span style="position:relative;display:inline-block;flex:0 0 auto;width:44px;height:24px;margin-top:1px">';
    echo '<input type="checkbox" name="is_indefinite" id="paIndefinite" form="approveForm" value="1" ' . ($preIsIndefinite ? 'checked' : '') . ' style="position:absolute;opacity:0;width:100%;height:100%;margin:0;cursor:pointer;z-index:2">';
    echo '<span id="paIndefiniteTrack" style="position:absolute;inset:0;border-radius:999px;background:' . ($preIsIndefinite ? 'hsl(var(--warning))' : 'hsl(var(--muted))') . ';transition:background .15s"></span>';
    echo '<span id="paIndefiniteThumb" style="position:absolute;top:2px;left:2px;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.3);transition:transform .15s;transform:translateX(' . ($preIsIndefinite ? '20px' : '0') . ')"></span>';
    echo '</span>';
    // Texto
    echo '<span style="display:flex;flex-direction:column;gap:2px">';
    echo '<span style="font-weight:600;font-size:14px">Tempo indefinido</span>';
    echo '<span style="font-size:12px;color:hsl(var(--muted-foreground));line-height:1.4">Sem data de término e sem número fixo de sessões. O atendimento permanece ativo até ser encerrado manualmente.</span>';
    echo '<span id="paIndefiniteBadge" style="' . ($preIsIndefinite ? '' : 'display:none;') . 'align-self:flex-start;margin-top:6px;font-size:11px;font-weight:600;padding:3px 8px;border-radius:999px;background:hsl(var(--warning));color:#111">● ATIVADO — data de término desativada</span>';
    echo '</span>';
    echo '</label>';
    echo '</div>';
    
    echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">';
    echo '<label>Frequência *<select name="frequency" id="paFrequency" form="approveForm" required>';
    if (function_exists('frequency_get_options')) {
        $freqOptions = frequency_get_options();
        echo '<option value="">Selecione...</option>';
        foreach ($freqOptions as $fo) {
            $sel = ($fo['code'] === $preFrequency) ? ' selected' : '';
            echo '<option value="' . h($fo['code']) . '"' . $sel . '>' . h($fo['label']) . ' — ' . h($fo['description']) . '</option>';
        }
    } else {
        $freqOpts = ['daily' => 'Diária', 'weekly' => 'Semanal', 'biweekly' => 'Quinzenal', 'monthly' => 'Mensal'];
        echo '<option value="">Selecione...</option>';
        foreach ($freqOpts as $fk => $fl) {
            $sel = ($fk === $preFrequency) ? ' selected' : '';
            echo '<option value="' . h($fk) . '"' . $sel . '>' . h($fl) . '</option>';
        }
    }
    echo '</select></label>';
    echo '<input type="hidden" name="sessions_per_week" id="paSessionsPerWeek" form="approveForm" value="' . $preSessionsPerWeek . '">';
    echo '</div>';
    
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px" id="paDurationRow">';
    echo '<label>Data de término *<input type="date" name="end_date" id="paEndDate" form="approveForm" value="' . h($preEndDate) . '"' . ($preStartDate !== '' ? ' min="' . h((string)$preStartDate) . '"' : '') . ($preIsIndefinite ? '' : ' required') . '></label>';
    echo '<label>Total de Sessões<input type="number" name="total_sessions" id="paTotalSessions" form="approveForm" value="' . $preTotalSessions . '" readonly style="background:hsl(var(--muted));cursor:not-allowed"></label>';
    echo '</div>';
    // Semanas: calculadas a partir das datas (o servidor recalcula na aprovação).
    echo '<input type="hidden" name="duration_weeks" id="paDurationWeeks" form="approveForm" value="' . ($preDurationWeeks ?: '') . '">';
    
    echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-top:4px" id="paDurationHint">Informe a data em que o atendimento termina: o total de sessões é calculado pelos dias da frequência entre o início e o término.</div>';
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
    echo '<label>Especialidade<input value="' . h($specialty) . '" readonly></label>';
    echo '<label>Tipo de Serviço<input value="' . h($serviceType) . '" readonly></label>';
    
    // Buscar descrição específica da sub-solicitação (por especialidade)
    $demandDescription = '';
    try {
        // Primeiro tentar pela sub-solicitação da mesma especialidade
        $stmtSubReq = db()->prepare("
            SELECT description FROM demand_sub_requests 
            WHERE demand_id = ? AND specialty = ? 
            ORDER BY id DESC LIMIT 1
        ");
        $stmtSubReq->execute([$demandId, $specialty]);
        $subReqRow = $stmtSubReq->fetch(PDO::FETCH_ASSOC);
        if ($subReqRow && !empty($subReqRow['description'])) {
            $demandDescription = trim((string)$subReqRow['description']);
        }
    } catch (Exception $e) {}
    
    // Fallback: descrição geral da demanda (mas só se não é o resumo completo da IA)
    if (empty($demandDescription)) {
        try {
            $stmtDemandDesc = db()->prepare("SELECT description FROM demands WHERE id = ?");
            $stmtDemandDesc->execute([$demandId]);
            $demandDescRow = $stmtDemandDesc->fetch(PDO::FETCH_ASSOC);
            $desc = trim((string)($demandDescRow['description'] ?? ''));
            // Só usar se for curto (menos de 200 chars = provavelmente é resumo específico)
            if (!empty($desc) && strlen($desc) < 200) {
                $demandDescription = $desc;
            }
        } catch (Exception $e) {}
    }
    
    if (!empty($demandDescription)) {
        echo '<label>Descrição do Atendimento<input value="' . h($demandDescription) . '" readonly></label>';
    }
    
    echo '</div>';
    echo '</div>';

    echo '<div class="tabPanel" data-panel="profissional">';
    echo '<div style="display:grid;gap:12px">';
    echo '<label>Profissional vinculado<input value="' . h($professionalName) . '" readonly></label>';
    echo '<label>Especialidade<input value="' . h($specialty) . '" readonly></label>';
    echo '<label>Telefone<input value="' . h($professionalPhone) . '" readonly></label>';
    echo '<label>COREN/CRM<input placeholder="Registro profissional"></label>';
    // Credenciais de SISTEMAS EXTERNOS usados pelo profissional (NÃO é o login do MultiLife).
    // Preenchidas pelo operador e repassadas ao profissional por WhatsApp/e-mail na aprovação.
    echo '<div style="margin-top:6px;padding:12px 14px;background:hsla(var(--info)/.08);border:1px solid hsla(var(--info)/.25);border-radius:8px;display:grid;gap:12px">';
    echo '<div style="font-size:13px;font-weight:700;color:hsl(var(--foreground))">Credenciais de sistema externo</div>';
    echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));line-height:1.5;margin-top:-6px">Login e senha que o profissional usará em sistemas externos. Serão enviados a ele na comunicação de aprovação. Não é o acesso ao MultiLife.</div>';
    echo '<label>Login (sistema externo)<input type="text" name="external_login" id="paExternalLogin" form="approveForm" autocomplete="off" placeholder="Login de acesso do profissional"></label>';
    echo '<label>Senha (sistema externo)<input type="text" name="external_password" id="paExternalPassword" form="approveForm" autocomplete="off" placeholder="Senha de acesso do profissional"></label>';
    echo '</div>';

    // ORIENTAÇÃO AO PROFISSIONAL (variação da operadora) — escolhida aqui na pré-admissão.
    // As variações são carregadas conforme a operadora selecionada na aba Faturamento.
    $orientationsForDetected = $detectedInsurerId > 0 ? insurer_orientations_list($detectedInsurerId, true) : [];
    echo '<div style="margin-top:6px;padding:12px 14px;background:hsla(var(--primary)/.06);border:1px solid hsl(var(--border));border-radius:8px;display:grid;gap:10px">';
    echo '<div style="font-size:13px;font-weight:700">Orientação ao profissional (operadora)</div>';
    echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));line-height:1.5;margin-top:-4px">Selecione a variação de orientação que será enviada ao profissional junto com os documentos e o link. As opções mudam conforme a operadora escolhida na aba Faturamento.</div>';
    echo '<label>Variação de orientação<select name="orientation_id" id="paOrientationSelect" form="approveForm">';
    echo '<option value="">— Nenhuma / usar padrão —</option>';
    foreach ($orientationsForDetected as $ori) {
        $cred = (int)($ori['requires_credentials'] ?? 0) === 1 ? ' [pede login/senha]' : '';
        echo '<option value="' . (int)$ori['id'] . '" data-cred="' . (int)($ori['requires_credentials'] ?? 0) . '">' . h((string)$ori['label']) . $cred . '</option>';
    }
    echo '</select></label>';
    echo '<div id="paOrientationCredHint" style="display:none;font-size:12px;color:hsl(var(--warning));font-weight:600">⚠️ Esta variação exige login/senha de sistema externo. Preencha os campos acima.</div>';
    echo '</div>';

    // O que vai para o profissional ao aprovar (para conferir antes de clicar).
    $paEvt = null;
    $paEvtFiles = 0;
    $paInsurerDocs = 0;
    try {
        $paEvt = db()->query("SELECT id, send_to_professional FROM whatsapp_events WHERE system_event = 'preadmission_approved' AND status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($paEvt) {
            $stF = db()->prepare("SELECT COUNT(*) FROM whatsapp_event_files WHERE event_id = :e AND recipient_type IN ('professional','both')");
            $stF->execute(['e' => (int)$paEvt['id']]);
            $paEvtFiles = (int)$stF->fetchColumn();
        }
        if ($detectedInsurerId > 0) {
            $stD = db()->prepare('SELECT COUNT(*) FROM health_insurer_documents WHERE health_insurer_id = :h');
            $stD->execute(['h' => $detectedInsurerId]);
            $paInsurerDocs = (int)$stD->fetchColumn();
        }
    } catch (Throwable $e) {}
    $paPortalLink = notifications_should_include_portal_link();
    echo '<div style="margin-top:6px;padding:12px 14px;background:hsla(var(--secondary)/.5);border:1px solid hsl(var(--border));border-radius:8px;font-size:12px;line-height:1.7">';
    echo '<div style="font-size:13px;font-weight:700;margin-bottom:4px">O que o profissional recebe ao aprovar</div>';
    if (!$paEvt || (int)$paEvt['send_to_professional'] !== 1) {
        echo '<div style="color:hsl(var(--destructive));font-weight:600">⚠️ A mensagem "Pré-admissão aprovada" está desativada para o profissional (Configurações → Eventos WhatsApp).</div>';
    } else {
        echo '<div>✅ Mensagem no WhatsApp (e cópia por e-mail) com paciente, endereço, contatos da família e agendamento.</div>';
        echo '<div>' . ($paEvtFiles > 0 ? '✅ ' . $paEvtFiles . ' arquivo(s) anexado(s) ao evento' : '— Nenhum arquivo anexado ao evento') . '</div>';
    }
    if ($paInsurerDocs > 0) {
        echo '<div>' . ($paPortalLink
            ? '✅ Link com os ' . $paInsurerDocs . ' documento(s) da operadora'
            : '<span style="color:hsl(var(--warning));font-weight:600">⚠️ A operadora tem ' . $paInsurerDocs . ' documento(s), mas o link não será enviado: ative "Enviar link de acesso ao portal nas notificações" em Funcionalidades.</span>') . '</div>';
    } else {
        echo '<div>— A operadora selecionada não tem documentos cadastrados</div>';
    }
    echo '</div>';

    echo '</div>';
    echo '</div>';

    echo '<div style="height:14px"></div>';
    
    // Calcular datas das sessões (usado internamente para aprovação)
    $sessionDatesForApproval = [];
    
    if ($authRequest && !empty($authRequest['start_date'])) {
        $startDate = new DateTime($authRequest['start_date']);
        $startTime = $authRequest['start_time'] ?? '08:00:00';
        $endTime = $authRequest['end_time'] ?? '09:00:00';
        $totalSess = (int)($authRequest['total_sessions'] ?? $sessionQty);
        $freq = (string)($authRequest['frequency'] ?? 'weekly');
        $sessPerWeek = (int)($authRequest['sessions_per_week'] ?? 1);
        
        $usePadronizada = false;
        if (function_exists('frequency_normalize') && function_exists('frequency_generate_session_dates')) {
            $freqCode = '';
            if (isset(FREQUENCY_WEEKDAYS_MAP[$freq])) {
                $freqCode = $freq;
            } else {
                $sessionsMap = [1 => '1x_semana', 2 => '2x_semana', 3 => '3x_semana', 4 => '4x_semana', 5 => '5x_semana', 6 => '6x_semana', 7 => '7x_semana'];
                if ($freq === 'daily' || $sessPerWeek >= 7) { $freqCode = '7x_semana'; }
                elseif ($freq === 'biweekly') { $freqCode = 'quinzenal'; }
                elseif ($freq === 'monthly') { $freqCode = 'mensal'; }
                elseif ($sessPerWeek >= 1 && $sessPerWeek <= 7) { $freqCode = $sessionsMap[$sessPerWeek] ?? ''; }
            }
            if ($freqCode !== '') {
                $generatedDates = frequency_generate_session_dates($freqCode, $startDate, $totalSess);
                if (count($generatedDates) > 0) {
                    $usePadronizada = true;
                    foreach ($generatedDates as $sessDateTime) {
                        $sessionDatesForApproval[] = $sessDateTime->format('Y-m-d');
                    }
                }
            }
        }
        
        if (!$usePadronizada) {
            $intervalDays = match($freq) {
                'daily' => 1, 'weekly' => 7, 'biweekly' => 14, 'monthly' => 30, default => 7,
            };
            if ($sessPerWeek > 1 && $freq === 'weekly') { $intervalDays = (int)floor(7 / $sessPerWeek); }
            for ($i = 0; $i < $totalSess; $i++) {
                $sessDate = clone $startDate;
                $sessDate->modify('+' . ($i * $intervalDays) . ' days');
                $sessionDatesForApproval[] = $sessDate->format('Y-m-d');
            }
        }
    }
    
    // Botão de aprovar atendimento
    echo '<form id="approveForm" method="post" action="/pre_admissao_approve.php" style="margin-top:20px">';
    echo '<input type="hidden" name="assignment_id" value="' . $assignmentId . '">';
    echo '<input type="hidden" name="demand_id" value="' . $demandId . '">';
    echo '<input type="hidden" name="weekdays" id="weekdaysInput" value="">';
    echo '<input type="hidden" name="session_dates" value="' . h(implode(',', $sessionDatesForApproval)) . '">';
    echo '<button class="btn btnPrimary" type="submit" style="width:100%" id="btnApprove">✅ Aprovar Atendimento</button>';
    echo '</form>';
}

echo '</div>';

echo '</aside>';

echo '<script>';
echo '(function(){var drawer=document.getElementById("drawer");var overlay=document.getElementById("drawerOverlay");if(!drawer||!overlay)return;';
echo 'var open=' . ($drawerOpen ? 'true' : 'false') . ';';
echo 'var floatingBtns=document.querySelector("div[style*=\'position:fixed\'][style*=\'z-index:9999\']");';
echo 'var setOpen=function(v){open=v; if(v){drawer.classList.add("isOpen"); overlay.style.display="block"; if(floatingBtns)floatingBtns.style.display="none";} else {drawer.classList.remove("isOpen"); overlay.style.display="none"; if(floatingBtns)floatingBtns.style.display="flex";}};';
echo 'if(open && floatingBtns) floatingBtns.style.display="none";';
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

// JS para calcular total de sessões automaticamente na aba Agendamento
// Total de sessões = dias da frequência entre a data de início e a de término
// (mesmas regras de frequency_count_sessions_between no servidor).
echo 'var paFreq=document.getElementById("paFrequency");';
echo 'var paSPW=document.getElementById("paSessionsPerWeek");';
echo 'var paDW=document.getElementById("paDurationWeeks");';
echo 'var paTS=document.getElementById("paTotalSessions");';
echo 'var paSD=document.getElementById("paStartDate");';
echo 'var paED=document.getElementById("paEndDate");';
echo 'var paWeekdays=' . json_encode(FREQUENCY_WEEKDAYS_MAP) . ';';
echo 'var paLegacy={daily:"7x_semana",weekly:"1x_semana",biweekly:"quinzenal",monthly:"mensal"};';
echo 'function paParseDate(v){var m=/^(\d{4})-(\d{2})-(\d{2})$/.exec(v||"");return m?new Date(+m[1],+m[2]-1,+m[3]):null;}';
echo 'function paCountSessions(code,s,e){';
echo '  if(!s||!e||e<s)return 0;';
echo '  if(code==="avaliacao"||code==="pontual")return 1;';
echo '  var n=0,c=new Date(s.getTime());';
echo '  if(code==="quinzenal"){while(c<=e&&n<240){n++;if(c.getDate()<=15){c.setDate(16);}else{c=new Date(c.getFullYear(),c.getMonth()+1,1);}}return n;}';
echo '  if(code==="mensal"){while(c<=e&&n<240){n++;c.setMonth(c.getMonth()+1);}return n;}';
echo '  var days=paWeekdays[code]||[];if(!days.length)return 0;';
echo '  while(c<=e){var dow=c.getDay()===0?7:c.getDay();if(days.indexOf(dow)!==-1)n++;c.setDate(c.getDate()+1);}';
echo '  return n;';
echo '}';
echo 'function paFmt(d){return ("0"+d.getDate()).slice(-2)+"/"+("0"+(d.getMonth()+1)).slice(-2)+"/"+d.getFullYear();}';
echo 'function paCalcSessions(){';
echo '  if(!paFreq||!paED||!paTS)return;';
echo '  var freq=paLegacy[paFreq.value]||paFreq.value;';
echo '  if(paSD&&paSD.value){paED.min=paSD.value;}';
echo '  if(freq==="avaliacao"||freq==="pontual"){';
echo '    paTS.value=1;';
echo '    paED.value="";paED.removeAttribute("required");paED.disabled=true;paED.style.opacity="0.5";';
echo '    if(paDW)paDW.value="";';
echo '    if(paSPW)paSPW.value=1;';
echo '    if(paDurHint)paDurHint.textContent="Sessão única: não precisa de data de término.";';
echo '    return;';
echo '  } else if(paED.disabled && (!paIndef || !paIndef.checked)){';
echo '    paED.disabled=false;paED.style.opacity="1";paED.setAttribute("required","required");';
echo '  }';
echo '  var days=paWeekdays[freq]||[];';
echo '  if(paSPW)paSPW.value=days.length?days.length:1;';
echo '  var s=paParseDate(paSD?paSD.value:""),e=paParseDate(paED.value);';
echo '  if(!s||!e||!freq){paTS.value="";if(paDurHint)paDurHint.textContent="Informe a data de início, a frequência e a data de término: o total de sessões é calculado automaticamente.";return;}';
echo '  if(e<s){paTS.value="";if(paDurHint)paDurHint.innerHTML="<span style=\"color:hsl(var(--destructive));font-weight:600\">A data de término é anterior à data de início.</span>";return;}';
echo '  var total=paCountSessions(freq,s,e);';
echo '  paTS.value=total>0?total:"";';
echo '  if(paDW)paDW.value=Math.max(1,Math.ceil((Math.round((e-s)/86400000)+1)/7));';
echo '  if(paDurHint)paDurHint.textContent=total>0?(total+" sess\u00e3o(\u00f5es) de "+paFmt(s)+" a "+paFmt(e)+"."):"Nenhum dia da frequência cai nesse período. Confira as datas.";';
echo '}';
echo 'if(paFreq)paFreq.addEventListener("change",paCalcSessions);';
echo 'if(paSD)paSD.addEventListener("change",paCalcSessions);';
echo 'if(paED){paED.addEventListener("change",paCalcSessions);paED.addEventListener("input",paCalcSessions);}';

// Tempo indefinido: alterna visual do switch + exigência/visibilidade de duração e semanas
echo 'var paIndef=document.getElementById("paIndefinite");';
echo 'var paDurRow=document.getElementById("paDurationRow");';
echo 'var paDurHint=document.getElementById("paDurationHint");';
echo 'var paIndefTrack=document.getElementById("paIndefiniteTrack");';
echo 'var paIndefThumb=document.getElementById("paIndefiniteThumb");';
echo 'var paIndefBadge=document.getElementById("paIndefiniteBadge");';
echo 'var paIndefBox=document.getElementById("paIndefiniteBox");';
echo 'function paToggleIndefinite(){';
echo '  if(!paIndef)return;';
echo '  var on=paIndef.checked;';
echo '  if(paIndefTrack){paIndefTrack.style.background=on?"hsl(var(--warning))":"hsl(var(--muted))";}';
echo '  if(paIndefThumb){paIndefThumb.style.transform=on?"translateX(20px)":"translateX(0)";}';
echo '  if(paIndefBadge){paIndefBadge.style.display=on?"inline-block":"none";}';
echo '  if(paIndefBox){paIndefBox.style.background=on?"hsla(var(--warning)/.18)":"hsla(var(--warning)/.10)";paIndefBox.style.borderColor=on?"hsl(var(--warning))":"hsla(var(--warning)/.35)";}';
echo '  if(paDurRow){paDurRow.style.display=on?"none":"grid";}';
echo '  if(paED){';
echo '    if(on){paED.removeAttribute("required");paED.value="";if(paDW)paDW.value="";}';
echo '    else if(!paED.disabled){paED.setAttribute("required","required");}';
echo '  }';
echo '  if(paTS){';
echo '    if(on){paTS.value="";}else{paCalcSessions();}';
echo '  }';
echo '  if(paDurHint){paDurHint.style.display=on?"none":"block";}';
echo '}';
echo 'if(paIndef){paIndef.addEventListener("change",paToggleIndefinite);paToggleIndefinite();}';

// ORIENTAÇÕES por operadora: repopular o select ao trocar a operadora na aba Faturamento.
// Mapa { insurerId: [{id,label,requires_credentials}, ...] } com as variações ATIVAS.
$orientationsByInsurer = [];
try {
    $oriRows = db()->query(
        "SELECT id, health_insurer_id, label, requires_credentials
         FROM health_insurer_orientations
         WHERE is_active = 1
         ORDER BY sort_order ASC, id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($oriRows as $o) {
        $hid = (int)$o['health_insurer_id'];
        if (!isset($orientationsByInsurer[$hid])) {
            $orientationsByInsurer[$hid] = [];
        }
        $orientationsByInsurer[$hid][] = [
            'id' => (int)$o['id'],
            'label' => (string)$o['label'],
            'requires_credentials' => (int)$o['requires_credentials'],
        ];
    }
} catch (Throwable $e) {}

echo 'var paOrientationsByInsurer=' . json_encode($orientationsByInsurer, JSON_UNESCAPED_UNICODE) . ';';
echo 'var paInsurerSelect=document.querySelector("select[name=health_insurer_id][form=approveForm]");';
echo 'var paOriSelect=document.getElementById("paOrientationSelect");';
echo 'var paOriCredHint=document.getElementById("paOrientationCredHint");';
echo 'function paUpdateCredHint(){';
echo '  if(!paOriSelect||!paOriCredHint)return;';
echo '  var opt=paOriSelect.options[paOriSelect.selectedIndex];';
echo '  var needs=opt&&opt.getAttribute("data-cred")==="1";';
echo '  paOriCredHint.style.display=needs?"block":"none";';
echo '}';
echo 'function paReloadOrientations(){';
echo '  if(!paInsurerSelect||!paOriSelect)return;';
echo '  var hid=parseInt(paInsurerSelect.value)||0;';
echo '  var list=paOrientationsByInsurer[hid]||[];';
echo '  paOriSelect.innerHTML="<option value=\"\">— Nenhuma / usar padrão —</option>";';
echo '  list.forEach(function(o){';
echo '    var opt=document.createElement("option");';
echo '    opt.value=o.id;';
echo '    opt.setAttribute("data-cred", o.requires_credentials?"1":"0");';
echo '    opt.textContent=o.label+(o.requires_credentials?" [pede login/senha]":"");';
echo '    paOriSelect.appendChild(opt);';
echo '  });';
echo '  paUpdateCredHint();';
echo '}';
echo 'if(paInsurerSelect){paInsurerSelect.addEventListener("change",paReloadOrientations);}';
echo 'if(paOriSelect){paOriSelect.addEventListener("change",paUpdateCredHint);paUpdateCredHint();}';

echo '})();';
echo '</script>';

view_footer();
