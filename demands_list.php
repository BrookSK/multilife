<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

// Colunas usadas para mostrar "o que falta" nos cards de Tratamento Manual.
demand_form_ensure_schema();

// Buscar especialidades
$specialtiesStmt = db()->query("SELECT id, name FROM specialties WHERE status = 'active' ORDER BY name ASC");
$specialties = $specialtiesStmt->fetchAll(PDO::FETCH_ASSOC);

$status = isset($_GET['status']) ? (string)$_GET['status'] : '';
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$specialty = isset($_GET['specialty']) ? trim((string)$_GET['specialty']) : '';
$city = isset($_GET['city']) ? trim((string)$_GET['city']) : '';
$assumedBy = isset($_GET['assumed_by']) ? trim((string)$_GET['assumed_by']) : '';
$clientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
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

// Filtro por CLIENTE (contratante). Cobre: cliente salvo no card, cliente da
// operadora do card, ou cliente resolvido pelo domínio do e-mail de origem.
if ($clientId > 0) {
    $where[] = '(
        d.client_id = :cli_id
        OR EXISTS (SELECT 1 FROM health_insurers hix WHERE hix.id = d.health_insurer_id AND hix.client_id = :cli_id2)
        OR EXISTS (SELECT 1 FROM clients clx WHERE clx.id = :cli_id3 AND clx.email_domain IS NOT NULL AND clx.email_domain != ""
                   AND LOWER(SUBSTRING_INDEX(d.origin_email, "@", -1)) = LOWER(clx.email_domain))
        OR EXISTS (SELECT 1 FROM health_insurers hiy WHERE hiy.client_id = :cli_id4 AND hiy.email_domain IS NOT NULL AND hiy.email_domain != ""
                   AND LOWER(SUBSTRING_INDEX(d.origin_email, "@", -1)) = LOWER(hiy.email_domain))
    )';
    $params['cli_id'] = $clientId;
    $params['cli_id2'] = $clientId;
    $params['cli_id3'] = $clientId;
    $params['cli_id4'] = $clientId;
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

// Query única (uma consulta) — o agrupamento por status é feito em PHP mais abaixo.
// O scroll interno de cada coluna (CSS) cuida de exibir muitos cards sem travar a tela.
// Cliente/operadora do card: prioriza o vínculo salvo (d.client_id); se vazio
// (cards antigos), resolve pelo DOMÍNIO do e-mail de origem — via operadora
// (health_insurers.email_domain) ou direto pelo cliente (clients.email_domain),
// exatamente como o resto do sistema faz.
$sql = 'SELECT d.id, d.title, d.specialty, d.location_city, d.location_state,
        CASE WHEN pa.status = "completed" THEN "concluido" ELSE d.status END AS status,
        d.assumed_by_user_id, d.created_at, d.updated_at, d.ai_summary, d.procedure_value, d.urgency, u.name AS assumed_by_name,
        pa.completed_at,
        d.origin_email,
        d.patient_name, d.location_street, d.location_neighborhood, d.location_zone, d.frequency,
        COALESCE(cli.name, cli_hi.name, cli_dom.name) AS client_name,
        COALESCE(hi.name, hi_dom.name) AS insurer_name
        FROM demands d
        LEFT JOIN users u ON u.id = d.assumed_by_user_id
        LEFT JOIN patient_assignments pa ON pa.demand_id = d.id
        LEFT JOIN clients cli ON cli.id = d.client_id
        LEFT JOIN health_insurers hi ON hi.id = d.health_insurer_id
        LEFT JOIN clients cli_hi ON cli_hi.id = hi.client_id
        LEFT JOIN health_insurers hi_dom ON (d.client_id IS NULL AND d.health_insurer_id IS NULL
             AND hi_dom.email_domain IS NOT NULL AND hi_dom.email_domain != ""
             AND LOWER(SUBSTRING_INDEX(d.origin_email, "@", -1)) = LOWER(hi_dom.email_domain))
        LEFT JOIN clients cli_dom ON (
             cli_dom.email_domain IS NOT NULL AND cli_dom.email_domain != ""
             AND LOWER(SUBSTRING_INDEX(d.origin_email, "@", -1)) = LOWER(cli_dom.email_domain))';

if (count($where) > 0) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY d.id DESC LIMIT 500';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

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
echo '<a class="btn" href="/imported_contacts_list.php">Contatos</a>';
echo '<a class="btn" href="/captation_import.php">Importar planilha</a>';
echo '<a class="btn" href="/whatsapp_groups_list.php">Grupos WhatsApp</a>';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

// (Removido: aviso de "e-mails aguardando revisão". A caixa de captação agora
// recebe só e-mails de captação, então não há mais triagem captação/não-captação.)

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

// Filtro por cliente (contratante)
$clientsForFilter = [];
try { $clientsForFilter = clients_list(false); } catch (Throwable $e) { $clientsForFilter = []; }
if (!empty($clientsForFilter)) {
    echo '<select name="client_id">';
    echo '<option value="0">Todos os clientes</option>';
    foreach ($clientsForFilter as $cl) {
        $sel = ($clientId === (int)$cl['id']) ? ' selected' : '';
        echo '<option value="' . (int)$cl['id'] . '"' . $sel . '>' . h((string)$cl['name']) . '</option>';
    }
    echo '</select>';
}

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

foreach ($columns as $col) {
    $colId = (string)$col['id'];
    $items = $byStatus[$colId] ?? [];

    // Coluna "Concluídos": apenas últimos 30 dias (baseado em completed_at do patient_assignment)
    if ($colId === 'concluido') {
        $thirtyDaysAgo = date('Y-m-d H:i:s', strtotime('-30 days'));
        $filtered = [];
        foreach ($items as $item) {
            $completedDate = $item['completed_at'] ?? null;
            if ($completedDate && $completedDate >= $thirtyDaysAgo) {
                $filtered[] = $item;
            }
        }
        $items = $filtered;
    }

    // Coluna "Cancelado": apenas últimos 30 dias (baseado em updated_at)
    if ($colId === 'cancelado') {
        $thirtyDaysAgo = date('Y-m-d H:i:s', strtotime('-30 days'));
        $filtered = [];
        foreach ($items as $item) {
            $updateDate = $item['updated_at'] ?? $item['created_at'];
            if ($updateDate >= $thirtyDaysAgo) {
                $filtered[] = $item;
            }
        }
        $items = $filtered;
    }

    $colTotal = count($items);

    echo '<div class="kanbanCol" data-column-id="' . h($colId) . '">';
    echo '<div class="kanbanColHead">';
    // Ação em massa OCULTA na coluna "Tratamento Manual": o emoji do cabeçalho é um
    // gatilho secreto — DUPLO CLIQUE abre a confirmação de limpeza (cancelar todos).
    // Não há botão visível; parece o cabeçalho normal.
    if ($colId === 'tratamento_manual') {
        echo '<span class="kanbanEmoji" style="cursor:default;user-select:none" title="" ondblclick="bulkCancelManual(event, ' . (int)$colTotal . ')">' . h((string)$col['emoji']) . '</span>';
    } else {
        echo '<span class="kanbanEmoji">' . h((string)$col['emoji']) . '</span>';
    }
    echo '<div class="kanbanTitle">' . h((string)$col['title']) . '</div>';
    echo '<div class="kanbanCount">' . (int)$colTotal . '</div>';
    echo '</div>';

    // Lane com SCROLL vertical interno: mostra alguns cards e rola o restante dentro da coluna,
    // sem travar/mover a página inteira.
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

            // Cliente / Operadora (detectado pelo e-mail de origem quando não salvo).
            $clientName = trim((string)($r['client_name'] ?? ''));
            $insurerName = trim((string)($r['insurer_name'] ?? ''));
            if ($clientName !== '' || $insurerName !== '') {
                $cliLabel = $clientName !== '' ? $clientName : $insurerName;
                if ($insurerName !== '' && $insurerName !== $clientName) {
                    $cliLabel .= ' · ' . $insurerName;
                }
                echo '<div style="margin-top:6px"><span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:700;background:hsla(var(--primary)/.12);color:hsl(var(--primary))">🏢 ' . h($cliLabel) . '</span></div>';
            }
            
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

            // O QUE FALTA (Tratamento Manual): mostra exatamente o campo pendente.
            if ($colId === 'tratamento_manual' || $colId === 'aguardando_captacao') {
                $missingTxt = demand_missing_summary($r);
                if ($missingTxt !== '') {
                    echo '<div style="margin-top:8px;padding:8px;border-radius:6px;background:hsla(0,84%,60%,.10);border:1px solid hsla(0,84%,60%,.4);font-size:12px;line-height:1.4">';
                    echo '⚠️ <strong>Falta:</strong> ' . h($missingTxt) . ' <span style="color:hsl(var(--muted-foreground))">— clique para completar</span>';
                    echo '</div>';
                }
            }
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

// Botão flutuante abre o formulário COMPLETO do novo card (o modal reduzido
// antigo gravava só título/cidade e o card saía sem endereço na notificação).
echo '<a class="fab" href="/demands_create.php" id="newDemandFab" aria-label="Novo card" style="display:flex;align-items:center;justify-content:center;text-decoration:none">+</a>';

echo '<style>';
echo '.kanbanColHead{display:flex;align-items:center;gap:8px;flex-wrap:wrap}';
// Lane com scroll vertical interno: mostra ~5 cards (altura fixa) e rola o resto dentro da coluna.
// A página não trava; só a lista de cards da coluna rola.
echo '.kanbanLane{max-height:calc(100vh - 320px);overflow-y:auto;overflow-x:hidden;padding-right:4px;display:flex;flex-direction:column;gap:8px}';
echo '.kanbanLane::-webkit-scrollbar{width:8px}';
echo '.kanbanLane::-webkit-scrollbar-thumb{background:hsl(var(--muted-foreground)/.35);border-radius:8px}';
echo '.kanbanLane::-webkit-scrollbar-thumb:hover{background:hsl(var(--muted-foreground)/.55)}';
echo '.kanbanLane::-webkit-scrollbar-track{background:transparent}';
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

// Ação em MASSA OCULTA: cancelar todos os cards de "Tratamento Manual".
// Acionada por DUPLO CLIQUE no emoji do cabeçalho da coluna + confirmação por digitação.
echo '<form id="bulkCancelManualForm" method="post" action="/demands_bulk_cancel_manual_post.php" style="display:none">';
echo '<input type="hidden" name="cancellation_reason" value="Limpeza em massa — Tratamento Manual">';
echo '</form>';
echo '<script>';
echo 'function bulkCancelManual(ev, total){';
echo '  if(ev){ ev.preventDefault(); ev.stopPropagation(); }';
echo '  if(!total || total < 1){ alert("Não há cards em Tratamento Manual para limpar."); return; }';
echo '  var step1 = confirm("Ação em massa: CANCELAR todos os " + total + " cards de Tratamento Manual?\\n\\nEsta ação move todos para Cancelado.");';
echo '  if(!step1) return;';
echo '  var typed = prompt("Confirmação de segurança: digite LIMPAR para cancelar os " + total + " cards.");';
echo '  if(typed === null) return;';
echo '  if(String(typed).trim().toUpperCase() !== "LIMPAR"){ alert("Confirmação incorreta. Nada foi alterado."); return; }';
echo '  document.getElementById("bulkCancelManualForm").submit();';
echo '}';
echo '</script>';

view_footer();
