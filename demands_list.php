<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

// Buscar especialidades
$specialtiesStmt = db()->query("SELECT id, name FROM specialties WHERE status = 'active' ORDER BY name ASC");
$specialties = $specialtiesStmt->fetchAll(PDO::FETCH_ASSOC);

$status = isset($_GET['status']) ? (string)$_GET['status'] : '';
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$specialty = isset($_GET['specialty']) ? trim((string)$_GET['specialty']) : '';
$city = isset($_GET['city']) ? trim((string)$_GET['city']) : '';
$assumedBy = isset($_GET['assumed_by']) ? trim((string)$_GET['assumed_by']) : '';
$dateFrom = isset($_GET['date_from']) ? trim((string)$_GET['date_from']) : '';
$dateTo = isset($_GET['date_to']) ? trim((string)$_GET['date_to']) : '';

$allowedStatuses = ['','aguardando_captacao','tratamento_manual','em_captacao','autorizacao_negada','admitido','concluido','cancelado'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}

$sql = 'SELECT d.id, d.title, d.specialty, d.location_city, d.location_state, 
        CASE WHEN pa.status = "completed" THEN "concluido" ELSE d.status END AS status,
        d.assumed_by_user_id, d.created_at, d.updated_at, d.ai_summary, d.procedure_value, d.urgency, u.name AS assumed_by_name,
        pa.completed_at
        FROM demands d
        LEFT JOIN users u ON u.id = d.assumed_by_user_id
        LEFT JOIN patient_assignments pa ON pa.demand_id = d.id';

$where = [];
$params = [];

if ($status !== '') {
    if ($status === 'concluido') {
        $where[] = 'pa.status = :pa_status';
        $params['pa_status'] = 'completed';
    } else {
        $where[] = 'd.status = :status';
        $params['status'] = $status;
    }
}

if ($q !== '') {
    $where[] = '(d.title LIKE :q OR d.specialty LIKE :q OR d.location_city LIKE :q OR d.origin_email LIKE :q)';
    $params['q'] = '%' . $q . '%';
}

if ($specialty !== '') {
    $where[] = 'd.specialty LIKE :specialty';
    $params['specialty'] = '%' . $specialty . '%';
}

if ($city !== '') {
    $where[] = 'd.location_city LIKE :city';
    $params['city'] = '%' . $city . '%';
}

if ($assumedBy !== '' && ctype_digit($assumedBy)) {
    $where[] = 'd.assumed_by_user_id = :assumed_by';
    $params['assumed_by'] = (int)$assumedBy;
}

// Filtro de datas (apenas quando especificado pelo usuário)
if ($dateFrom !== '') {
    $where[] = 'DATE(d.created_at) >= :date_from';
    $params['date_from'] = $dateFrom;
}

if ($dateTo !== '') {
    $where[] = 'DATE(d.created_at) <= :date_to';
    $params['date_to'] = $dateTo;
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
    ['id' => 'autorizacao_negada', 'title' => 'Negativas', 'emoji' => '❌'],
    ['id' => 'admitido', 'title' => 'Admitido', 'emoji' => '✅'],
    ['id' => 'concluido', 'title' => 'Concluídos', 'emoji' => '🎉'],
    ['id' => 'cancelado', 'title' => 'Cancelado', 'emoji' => '⛔'],
];

$byStatus = [
    'aguardando_captacao' => [],
    'tratamento_manual' => [],
    'em_captacao' => [],
    'autorizacao_negada' => [],
    'admitido' => [],
    'concluido' => [],
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
echo '<div style="font-size:22px;font-weight:900">Demandas</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.5">Cards de captação: e-mail → card → assumir → disparo em grupos.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/demands_create.php">Novo card</a>';
echo '<a class="btn" href="/inbound_emails_list.php">Inbox (E-mails)</a>';
echo '<a class="btn" href="/whatsapp_groups_list.php">Grupos WhatsApp</a>';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<form method="get" action="/demands_list.php" style="margin-top:14px;display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(200px,1fr))">';
echo '<select name="status">';
$opts = [
    '' => 'Todos os status',
    'aguardando_captacao' => 'Aguardando Captação',
    'tratamento_manual' => 'Tratamento Manual',
    'em_captacao' => 'Em Captação',
    'admitido' => 'Admitido',
    'concluido' => 'Concluídos',
    'cancelado' => 'Cancelado',
];
foreach ($opts as $k => $label) {
    $sel = ($status === $k) ? ' selected' : '';
    echo '<option value="' . h($k) . '"' . $sel . '>' . h($label) . '</option>';
}
echo '</select>';

$specialties = db()->query("SELECT DISTINCT specialty FROM demands WHERE specialty IS NOT NULL AND specialty != '' ORDER BY specialty ASC LIMIT 100")->fetchAll();
echo '<select name="specialty">';
echo '<option value="">Todas especialidades</option>';
foreach ($specialties as $sp) {
    $val = (string)$sp['specialty'];
    $sel = ($specialty === $val) ? ' selected' : '';
    echo '<option value="' . h($val) . '"' . $sel . '>' . h($val) . '</option>';
}
echo '</select>';

$cities = db()->query("SELECT DISTINCT location_city FROM demands WHERE location_city IS NOT NULL AND location_city != '' ORDER BY location_city ASC LIMIT 100")->fetchAll();
echo '<select name="city">';
echo '<option value="">Todas cidades</option>';
foreach ($cities as $c) {
    $val = (string)$c['location_city'];
    $sel = ($city === $val) ? ' selected' : '';
    echo '<option value="' . h($val) . '"' . $sel . '>' . h($val) . '</option>';
}
echo '</select>';

$captadores = db()->query("SELECT DISTINCT u.id, u.name FROM users u INNER JOIN user_roles ur ON ur.user_id = u.id INNER JOIN roles r ON r.id = ur.role_id WHERE r.slug = 'captador' AND u.status = 'active' ORDER BY u.name ASC")->fetchAll();
echo '<select name="assumed_by">';
echo '<option value="">Todos captadores</option>';
foreach ($captadores as $cap) {
    $val = (string)$cap['id'];
    $sel = ($assumedBy === $val) ? ' selected' : '';
    echo '<option value="' . h($val) . '"' . $sel . '>' . h((string)$cap['name']) . '</option>';
}
echo '</select>';

echo '<input type="date" name="date_from" placeholder="Data inicial" value="' . h($dateFrom) . '" title="Data inicial">';
echo '<input type="date" name="date_to" placeholder="Data final" value="' . h($dateTo) . '" title="Data final">';
echo '<input name="q" value="' . h($q) . '" placeholder="Buscar (título, origem)" style="grid-column:span 2">';
echo '<button class="btn btnPrimary" type="submit">Filtrar</button>';
echo '</form>';

echo '</section>';

echo '<section class="card col12">';
echo '<div class="kanbanScroll">';
echo '<div class="kanbanRow">';

foreach ($columns as $col) {
    $colId = (string)$col['id'];
    $items = $byStatus[$colId] ?? [];
    
    // Coluna "Concluídos": apenas últimos 30 dias (baseado em completed_at do patient_assignment), máximo 20 cards
    if ($colId === 'concluido') {
        $thirtyDaysAgo = date('Y-m-d H:i:s', strtotime('-30 days'));
        $filtered = [];
        foreach ($items as $item) {
            // Usar completed_at do patient_assignment
            $completedDate = $item['completed_at'] ?? null;
            if ($completedDate && $completedDate >= $thirtyDaysAgo) {
                $filtered[] = $item;
            }
        }
        $items = array_slice($filtered, 0, 20);
    }
    
    // Coluna "Cancelado": apenas últimos 30 dias (baseado em updated_at), máximo 20 cards
    if ($colId === 'cancelado') {
        $thirtyDaysAgo = date('Y-m-d H:i:s', strtotime('-30 days'));
        $filtered = [];
        foreach ($items as $item) {
            $updateDate = $item['updated_at'] ?? $item['created_at'];
            if ($updateDate >= $thirtyDaysAgo) {
                $filtered[] = $item;
            }
        }
        $items = array_slice($filtered, 0, 20);
    }

    $totalItems = count($items);
    $itemsPerPage = 10;
    $needsPagination = $totalItems > $itemsPerPage;
    
    echo '<div class="kanbanCol" data-column-id="' . h($colId) . '" data-total-items="' . $totalItems . '" data-items-per-page="' . $itemsPerPage . '">';
    echo '<div class="kanbanColHead">';
    
    // Setas de navegação (só aparecem se tiver mais de 10 cards)
    if ($needsPagination) {
        echo '<div class="kanbanPagination">';
        echo '<button class="kanbanPaginationBtn kanbanPaginationPrev" onclick="paginateKanban(\'' . h($colId) . '\', -1)" disabled>';
        echo '◀';
        echo '</button>';
        echo '<span class="kanbanPaginationInfo"><span class="kanbanCurrentPage">1</span>/<span class="kanbanTotalPages">' . ceil($totalItems / $itemsPerPage) . '</span></span>';
        echo '<button class="kanbanPaginationBtn kanbanPaginationNext" onclick="paginateKanban(\'' . h($colId) . '\', 1)">';
        echo '▶';
        echo '</button>';
        echo '</div>';
    }
    
    echo '<span class="kanbanEmoji">' . h((string)$col['emoji']) . '</span>';
    echo '<div class="kanbanTitle">' . h((string)$col['title']) . '</div>';
    echo '<div class="kanbanCount">' . (int)$totalItems . '</div>';
    echo '</div>';

    echo '<div class="kanbanLane">';

    if (count($items) === 0) {
        echo '<div class="kanbanEmpty">Vazio</div>';
    } else {
        foreach ($items as $idx => $r) {
            $pageIndex = floor($idx / $itemsPerPage);
            $isVisible = $pageIndex === 0 ? '' : ' style="display:none"';
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
            
            // Borda vermelha em cards aguardando_captacao com mais de 10 minutos sem assumir
            $cardStyle = '';
            if ($colId === 'aguardando_captacao' && !$r['assumed_by_user_id']) {
                $createdTime = strtotime((string)$r['created_at']);
                $now = time();
                $minutesWaiting = ($now - $createdTime) / 60;
                if ($minutesWaiting > 10) {
                    $cardStyle = ' style="border:2px solid hsl(0,84%,60%);box-shadow:0 0 8px hsla(0,84%,60%,.3)"';
                }
            }

            echo '<a class="kanbanCard" href="/demands_view.php?id=' . (int)$r['id'] . '" data-page-index="' . $pageIndex . '"' . $cardStyle . $isVisible . '>';
            echo '<div class="kanbanCardBody">';
            echo '<div class="kanbanCardTop">';
            echo '<div class="kanbanCardTitle">' . h((string)$r['title']) . '</div>';
            echo '</div>';
            
            // Resumo da IA (se disponível)
            $aiSummary = trim((string)($r['ai_summary'] ?? ''));
            if ($aiSummary !== '') {
                $summaryPreview = mb_strimwidth($aiSummary, 0, 120, '...');
                echo '<div style="margin-top:6px;padding:8px;background:hsla(var(--primary)/.08);border-radius:4px;font-size:12px;line-height:1.4;color:hsl(var(--foreground))">';
                echo '📋 ' . h($summaryPreview);
                echo '</div>';
            }
            
            echo '<div class="kanbanMeta">' . h($locTxt) . ' • ' . h((string)($r['specialty'] ?? '-')) . '</div>';
            
            // Valor do procedimento (se disponível)
            $procedureValue = $r['procedure_value'] !== null ? (float)$r['procedure_value'] : null;
            if ($procedureValue !== null && $procedureValue > 0) {
                echo '<div class="kanbanMeta" style="color:hsl(var(--success));font-weight:600">💰 R$ ' . number_format($procedureValue, 2, ',', '.') . '</div>';
            }
            
            // Mostrar quem assumiu a demanda
            if ($r['assumed_by_user_id'] && $r['assumed_by_name']) {
                echo '<div style="margin-top:8px;padding:8px;background:hsla(var(--primary)/.12);border-radius:6px;font-size:12px;font-weight:600;color:hsl(var(--primary))">';
                echo '👤 Captação sob os cuidados de: ' . h($assumed);
                echo '</div>';
            } else {
                echo '<div class="kanbanMeta">' . h($assumed) . ' • ' . h((string)$r['created_at']) . '</div>';
            }
            
            // Badges de status, urgência e valor
            echo '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px">';
            echo '<span class="badge ' . h($badgeCls) . '">' . h((string)$r['status']) . '</span>';
            
            // Badge de urgência
            $urgency = trim((string)($r['urgency'] ?? ''));
            if ($urgency === 'urgente') {
                echo '<span class="badge badgeDanger" style="background:hsl(0,84%,60%);color:#fff;font-weight:700">URGENTE</span>';
            } elseif ($urgency === 'normal') {
                echo '<span class="badge badgeWarn" style="font-size:11px">Normal</span>';
            } elseif ($urgency === 'baixa') {
                echo '<span class="badge badgeInfo" style="font-size:11px">Baixa</span>';
            }
            
            echo '</div>';
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

echo '<div id="newDemandOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.24);z-index:70"></div>';
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
echo '<div class="col6"><label>Tipo / Especialidade<select name="specialty">';
echo '<option value="">Selecione...</option>';
foreach ($specialties as $spec) {
    $specName = isset($spec['name']) ? (string)$spec['name'] : '';
    if ($specName !== '') {
        echo '<option value="' . h($specName) . '">' . h($specName) . '</option>';
    }
}
echo '</select></label></div>';
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

echo '<script src="/demands_list_pagination.js"></script>';

echo '<style>';
echo '.kanbanPagination{display:flex;align-items:center;gap:8px;margin-right:12px}';
echo '.kanbanPaginationBtn{background:hsl(var(--primary));color:hsl(var(--primary-foreground));border:none;border-radius:6px;width:32px;height:32px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:14px;font-weight:700;transition:all .2s}';
echo '.kanbanPaginationBtn:hover:not(:disabled){background:hsl(var(--primary)/.9);transform:scale(1.05)}';
echo '.kanbanPaginationBtn:disabled{opacity:.3;cursor:not-allowed}';
echo '.kanbanPaginationInfo{font-size:13px;font-weight:600;color:hsl(var(--foreground));min-width:40px;text-align:center}';
echo '.kanbanColHead{display:flex;align-items:center;gap:8px;flex-wrap:wrap}';
echo '</style>';

view_footer();
