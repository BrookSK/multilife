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

// Filtros do Kanban. O WHERE base (busca, especialidade, cidade, datas, escopo por perfil)
// é montado aqui e reutilizado por cada coluna na paginação backend (mais abaixo).
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
    // Permitir busca por número da captação (#533 ou 533)
    $qClean = ltrim($q, '#');
    if (ctype_digit($qClean)) {
        $where[] = 'd.id = :qid';
        $params['qid'] = (int)$qClean;
    } else {
        $where[] = '(d.title LIKE :q1 OR d.specialty LIKE :q2 OR d.location_city LIKE :q3 OR d.origin_email LIKE :q4)';
        $params['q1'] = '%' . $q . '%';
        $params['q2'] = '%' . $q . '%';
        $params['q3'] = '%' . $q . '%';
        $params['q4'] = '%' . $q . '%';
    }
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

// FILTRO POR PROFISSIONAL:
// Se o usuário logado é profissional (role 'profissional') e NÃO tem acesso amplo
// (admin/ti/captador), mostrar apenas as demandas atribuídas a ele mesmo via
// patient_assignments.professional_user_id.
$currentUid = (int)(auth_user_id() ?? 0);
$currentRoles = rbac_user_roles($currentUid);
// Apenas admin e admissão têm visão total do kanban.
$hasFullAccess = !empty(array_intersect($currentRoles, ['admin', 'admissao']));
$isProfessional = in_array('profissional', $currentRoles, true);
$isCaptador = in_array('captador', $currentRoles, true);

if (!$hasFullAccess && $isProfessional) {
    // Profissional: vê SOMENTE os cards atribuídos a ele mesmo.
    $where[] = 'EXISTS (
        SELECT 1 FROM patient_assignments pa2
        WHERE pa2.demand_id = d.id AND pa2.professional_user_id = :prof_uid
    )';
    $params['prof_uid'] = $currentUid;
} elseif (!$hasFullAccess && $isCaptador) {
    // Captador: vê os cards que ele mesmo assumiu + os ainda disponíveis (não assumidos).
    $where[] = '(d.assumed_by_user_id = :capt_uid OR d.assumed_by_user_id IS NULL)';
    $params['capt_uid'] = $currentUid;
}

// ITEM 16: Paginação backend do Kanban POR COLUNA.
// Antes, uma única query trazia até 500 demandas e o front paginava. Agora cada
// coluna (status) consulta apenas seus próprios cards com LIMIT/OFFSET, e a página
// de cada coluna vem por query string page_<status> (ex.: page_em_captacao=2).
// Assim nunca carregamos todos os registros de uma vez.
$kanbanPerPage = 25; // cards por página, por coluna

// Guardar o WHERE/params base SEM o filtro de status (o status é aplicado por coluna).
// A condição de status adicionada acima em $where só é usada para decidir quais colunas
// exibir; para a consulta por coluna montamos o WHERE de novo sem ela.
$baseWhereParts = [];
$baseParams = [];
foreach ($where as $cond) {
    // Ignorar a condição de status do formulário (será substituída pela da coluna)
    if (strpos($cond, 'd.status = :status') !== false || strpos($cond, 'pa.status = :pa_status') !== false) {
        continue;
    }
    $baseWhereParts[] = $cond;
}
foreach ($params as $pk => $pv) {
    if ($pk === 'status' || $pk === 'pa_status') { continue; }
    $baseParams[$pk] = $pv;
}

$statusFilter = $status; // '' = todas as colunas; senão, só a coluna filtrada

view_header('Captação - Demandas');

// Função para formatar labels de status
function format_status_label(string $status): string {
    $labels = [
        'aguardando_captacao' => 'Aguardando Captação',
        'tratamento_manual' => 'Tratamento Manual',
        'em_captacao' => 'Em Captação',
        'autorizacao_negada' => 'Autorização Negada',
        'admitido' => 'Admitido',
        'concluido' => 'Concluído',
        'cancelado' => 'Cancelado',
    ];
    return $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

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
$colTotals = [];   // total de cards por coluna (para paginação)
$colPages = [];    // página atual por coluna

// Base SELECT/JOIN reutilizada por coluna
$baseSelect = 'SELECT d.id, d.title, d.specialty, d.location_city, d.location_state,
        CASE WHEN pa.status = "completed" THEN "concluido" ELSE d.status END AS status,
        d.assumed_by_user_id, d.created_at, d.updated_at, d.ai_summary, d.procedure_value, d.urgency, u.name AS assumed_by_name,
        pa.completed_at
        FROM demands d
        LEFT JOIN users u ON u.id = d.assumed_by_user_id
        LEFT JOIN patient_assignments pa ON pa.demand_id = d.id';
$baseCount = 'SELECT COUNT(*)
        FROM demands d
        LEFT JOIN patient_assignments pa ON pa.demand_id = d.id';

foreach (array_keys($byStatus) as $colStatus) {
    // Se há filtro de status e não é essa coluna, pula (coluna fica vazia).
    if ($statusFilter !== '' && $statusFilter !== $colStatus) {
        $colTotals[$colStatus] = 0;
        $colPages[$colStatus] = 1;
        continue;
    }

    // Monta WHERE da coluna = base + condição de status desta coluna
    $colWhere = $baseWhereParts;
    $colParams = $baseParams;
    if ($colStatus === 'concluido') {
        $colWhere[] = 'pa.status = :col_status';
        $colParams['col_status'] = 'completed';
        // Concluídos: apenas últimos 30 dias (filtro no backend)
        $colWhere[] = 'pa.completed_at >= :col_since';
        $colParams['col_since'] = date('Y-m-d H:i:s', strtotime('-30 days'));
    } elseif ($colStatus === 'cancelado') {
        $colWhere[] = 'd.status = :col_status';
        $colParams['col_status'] = $colStatus;
        // Cancelados: apenas últimos 30 dias (filtro no backend)
        $colWhere[] = 'COALESCE(d.updated_at, d.created_at) >= :col_since';
        $colParams['col_since'] = date('Y-m-d H:i:s', strtotime('-30 days'));
    } else {
        $colWhere[] = 'd.status = :col_status';
        $colParams['col_status'] = $colStatus;
    }
    $colWhereSql = count($colWhere) > 0 ? (' WHERE ' . implode(' AND ', $colWhere)) : '';

    // Total da coluna
    $cStmt = db()->prepare($baseCount . $colWhereSql);
    $cStmt->execute($colParams);
    $colTotal = (int)$cStmt->fetchColumn();
    $colTotals[$colStatus] = $colTotal;

    // Quantos cards carregar nesta coluna. Começa em $kanbanPerPage (25) e o botão
    // "Carregar mais" aumenta via query string load_<status> (ex.: load_em_captacao=50).
    // O scroll interno da coluna cuida da rolagem; o "Carregar mais" traz mais do backend.
    $loadKey = 'load_' . $colStatus;
    $colLimit = isset($_GET[$loadKey]) && ctype_digit((string)$_GET[$loadKey]) ? max($kanbanPerPage, (int)$_GET[$loadKey]) : $kanbanPerPage;
    // Teto de segurança para não carregar volume absurdo de uma vez
    $colLimit = min($colLimit, 500);
    $colPages[$colStatus] = 1;

    // Cards da coluna (do começo até o limite atual)
    $lStmt = db()->prepare($baseSelect . $colWhereSql . ' ORDER BY d.id DESC LIMIT ' . (int)$colLimit);
    $lStmt->execute($colParams);
    $byStatus[$colStatus] = $lStmt->fetchAll();
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
echo '<input name="q" value="' . h($q) . '" placeholder="Buscar (nº, título, origem)" style="grid-column:span 2">';
echo '<button class="btn btnPrimary" type="submit">Filtrar</button>';
echo '</form>';

echo '</section>';

echo '<section class="card col12">';
echo '<div class="kanbanScroll">';
echo '<div class="kanbanRow">';

// Helper: monta URL preservando os filtros e todos os "load_<status>" atuais,
// aumentando o limite de UMA coluna (para o botão "Carregar mais"). Âncora leva de volta à coluna.
$buildKanbanLoadUrl = function (string $colStatus, int $newLimit) use ($status, $q, $specialty, $city, $assumedBy, $dateFrom, $dateTo): string {
    $qs = [
        'status' => $status,
        'q' => $q,
        'specialty' => $specialty,
        'city' => $city,
        'assumed_by' => $assumedBy,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
    ];
    // Preservar os limites já expandidos de outras colunas
    foreach ($_GET as $gk => $gv) {
        if (is_string($gk) && strpos($gk, 'load_') === 0 && ctype_digit((string)$gv)) {
            $qs[$gk] = (int)$gv;
        }
    }
    $qs['load_' . $colStatus] = $newLimit;
    $qs = array_filter($qs, fn($v) => $v !== '' && $v !== null);
    return '/demands_list.php?' . http_build_query($qs) . '#col_' . $colStatus;
};

foreach ($columns as $col) {
    $colId = (string)$col['id'];
    $items = $byStatus[$colId] ?? [];   // já vem paginado do backend (página atual da coluna)

    // Total real da coluna (todos os cards que batem no filtro).
    $colTotal = (int)($colTotals[$colId] ?? count($items));
    $colLoaded = count($items);                 // quantos vieram nesta primeira carga (LIMIT do backend)
    $colHasMore = $colTotal > $colLoaded;        // ainda há cards além dos carregados

    echo '<div class="kanbanCol" id="col_' . h($colId) . '" data-column-id="' . h($colId) . '" data-total="' . $colTotal . '" data-loaded="' . $colLoaded . '">';
    echo '<div class="kanbanColHead">';
    echo '<span class="kanbanEmoji">' . h((string)$col['emoji']) . '</span>';
    echo '<div class="kanbanTitle">' . h((string)$col['title']) . '</div>';
    echo '<div class="kanbanCount">' . (int)$colTotal . '</div>';
    echo '</div>';

    // Lane com SCROLL vertical interno: mostra ~5 cards e rola o restante dentro da coluna.
    echo '<div class="kanbanLane">';

    if (count($items) === 0) {
        echo '<div class="kanbanEmpty">Vazio</div>';
    } else {
        foreach ($items as $idx => $r) {
            $pageIndex = (int)floor($idx / $itemsPerPage);
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
            
            // Todos os cards carregados ficam visíveis; o scroll interno da coluna cuida do excesso.
            // Borda vermelha em cards aguardando_captacao com mais de 10 minutos sem assumir
            $redBorder = false;
            if ($colId === 'aguardando_captacao' && !$r['assumed_by_user_id']) {
                $createdTime = strtotime((string)$r['created_at']);
                $now = time();
                $minutesWaiting = ($now - $createdTime) / 60;
                if ($minutesWaiting > 10) {
                    $redBorder = true;
                }
            }

            // Construir atributo style (só borda de alerta, sem esconder)
            $styleAttr = '';
            if ($redBorder) {
                $styleAttr = ' style="border:2px solid hsl(0,84%,60%);box-shadow:0 0 8px hsla(0,84%,60%,.3)"';
            }

            echo '<a class="kanbanCard" href="/demands_view.php?id=' . (int)$r['id'] . '"' . $styleAttr . '>';
            echo '<div class="kanbanCardBody">';
            echo '<div class="kanbanCardTop">';
            echo '<div class="kanbanCardTitle"><span style="color:hsl(var(--muted-foreground));font-weight:600;font-size:11px">#' . (int)$r['id'] . '</span> ' . h((string)$r['title']) . '</div>';
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
            
            // Faixa verde quando demanda assumida
            if ($r['assumed_by_user_id'] && $r['assumed_by_name']) {
                echo '<div style="margin-top:8px;padding:10px;background:hsl(var(--success));border-radius:6px;font-size:12px;font-weight:700;color:#fff;text-align:center">';
                echo '✓ Assumida por: ' . h($assumed);
                echo '</div>';
            } else {
                echo '<div class="kanbanMeta">' . h($assumed) . ' • ' . h((string)$r['created_at']) . '</div>';
            }
            
            // Badges de status, urgência e valor
            echo '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px">';
            echo '<span class="badge ' . h($badgeCls) . '">' . h(format_status_label((string)$r['status'])) . '</span>';
            
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

        // Se ainda há cards além dos carregados, link para carregar o próximo lote (+25)
        if ($colHasMore) {
            $nextLimit = $colLoaded + $kanbanPerPage;
            $restantes = $colTotal - $colLoaded;
            echo '<a class="kanbanLoadMore" href="' . h($buildKanbanLoadUrl($colId, $nextLimit)) . '">Carregar mais (' . $restantes . ' restante' . ($restantes > 1 ? 's' : '') . ')</a>';
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

echo '<style>';
echo '.kanbanColHead{display:flex;align-items:center;gap:8px;flex-wrap:wrap}';
// Lane com scroll vertical interno: mostra ~5 cards (altura fixa) e rola o resto dentro da coluna.
// A página não trava; só a lista de cards da coluna rola.
echo '.kanbanLane{max-height:calc(100vh - 320px);overflow-y:auto;overflow-x:hidden;padding-right:4px;display:flex;flex-direction:column;gap:8px}';
echo '.kanbanLane::-webkit-scrollbar{width:8px}';
echo '.kanbanLane::-webkit-scrollbar-thumb{background:hsl(var(--muted-foreground)/.35);border-radius:8px}';
echo '.kanbanLane::-webkit-scrollbar-thumb:hover{background:hsl(var(--muted-foreground)/.55)}';
echo '.kanbanLane::-webkit-scrollbar-track{background:transparent}';
// Botão "Carregar mais" no fim da coluna
echo '.kanbanLoadMore{margin-top:6px;width:100%;padding:8px;font-size:12px;font-weight:600;background:hsla(var(--primary)/.08);color:hsl(var(--primary));border:1px dashed hsl(var(--primary)/.4);border-radius:8px;cursor:pointer;transition:background .15s}';
echo '.kanbanLoadMore:hover{background:hsla(var(--primary)/.16)}';
echo '.kanbanLoadMore:disabled{opacity:.5;cursor:default}';
echo '</style>';

// Sincronizar e-mails em background ao carregar (apenas IMAP poll, extração é via CRON)
echo '<script>';
echo '(function(){';
echo '  fetch("/demands_sync_emails.php").then(function(r){ return r.json(); }).then(function(data){';
echo '    var newEmails = (data.imap && data.imap.new_emails) || 0;';
echo '    if(newEmails > 0){';
echo '      console.log("Novos e-mails: " + newEmails + ". CRON vai processar em breve.");';
echo '    }';
echo '  }).catch(function(e){ console.log("Sync emails:", e); });';
echo '})();';
echo '</script>';

view_footer();
