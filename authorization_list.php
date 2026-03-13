<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$status = isset($_GET['status']) ? (string)$_GET['status'] : '';
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

$allowedStatuses = ['', 'aguardando_autorizacao', 'autorizacao_negada'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}

$sql = 'SELECT ar.*, 
        d.title as demand_title, d.specialty as demand_specialty, d.location_city, d.location_state,
        u.name as professional_name,
        p.full_name as patient_name,
        ar.demand_id,
        ar.inbound_email_id
        FROM authorization_requests ar
        INNER JOIN demands d ON d.id = ar.demand_id
        INNER JOIN users u ON u.id = ar.professional_user_id
        LEFT JOIN patients p ON p.id = (SELECT patient_id FROM patient_assignments WHERE demand_id = d.id LIMIT 1)
        WHERE ar.status IN ("aguardando_autorizacao", "autorizacao_negada")';

$params = [];

if ($status !== '') {
    $sql .= ' AND ar.status = :status';
    $params['status'] = $status;
}

if ($q !== '') {
    $sql .= ' AND (d.title LIKE :q OR ar.operator_email LIKE :q OR u.name LIKE :q OR p.full_name LIKE :q)';
    $params['q'] = '%' . $q . '%';
}

$sql .= ' ORDER BY ar.created_at DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

view_header('Autorização de Propostas');

$columns = [
    ['id' => 'aguardando_autorizacao', 'title' => 'Aguardando Resposta', 'emoji' => '⏳'],
    ['id' => 'autorizacao_negada', 'title' => 'Negativas', 'emoji' => '❌'],
];

$byStatus = [
    'aguardando_autorizacao' => [],
    'autorizacao_negada' => [],
];

foreach ($rows as $r) {
    $st = (string)$r['status'];
    if (isset($byStatus[$st])) {
        $byStatus[$st][] = $r;
    }
}

echo '<div class="pageHeader">';
echo '<h1>🔐 Autorização de Propostas</h1>';
echo '<div class="pageHeaderActions">';
echo '<a href="/demands_list.php" class="btn">← Voltar para Captação</a>';
echo '</div>';
echo '</div>';

echo '<div class="card" style="margin-bottom:20px">';
echo '<form method="get" style="display:flex;gap:12px;flex-wrap:wrap;align-items:end">';
echo '<div style="flex:1;min-width:200px">';
echo '<label style="display:block;margin-bottom:6px;font-size:13px;font-weight:600">Buscar</label>';
echo '<input type="text" name="q" value="' . h($q) . '" placeholder="Paciente, profissional, operadora..." class="input">';
echo '</div>';
echo '<div style="flex:1;min-width:200px">';
echo '<label style="display:block;margin-bottom:6px;font-size:13px;font-weight:600">Status</label>';
echo '<select name="status" class="input">';
echo '<option value="">Todos</option>';
foreach ($columns as $col) {
    $sel = $status === $col['id'] ? ' selected' : '';
    echo '<option value="' . h($col['id']) . '"' . $sel . '>' . $col['emoji'] . ' ' . h($col['title']) . '</option>';
}
echo '</select>';
echo '</div>';
echo '<button type="submit" class="btn btnPrimary">Filtrar</button>';
if ($q !== '' || $status !== '') {
    echo '<a href="/authorization_list.php" class="btn">Limpar</a>';
}
echo '</form>';
echo '</div>';

echo '<div class="kanbanScroll">';
echo '<div class="kanbanRow">';

foreach ($columns as $col) {
    $colId = $col['id'];
    $items = $byStatus[$colId];
    $count = count($items);
    
    echo '<div class="kanbanCol">';
    echo '<div class="kanbanColHead">';
    echo '<span class="kanbanEmoji">' . $col['emoji'] . '</span>';
    echo '<span class="kanbanTitle">' . h($col['title']) . '</span>';
    echo '<span class="kanbanCount">' . $count . '</span>';
    echo '</div>';
    
    echo '<div class="kanbanLane">';
    
    if ($count === 0) {
        echo '<div class="kanbanEmpty">Nenhuma proposta</div>';
    } else {
        foreach ($items as $item) {
            $authId = (int)$item['id'];
            $demandId = (int)$item['demand_id'];
            $emailId = !empty($item['inbound_email_id']) ? (int)$item['inbound_email_id'] : null;
            $demandTitle = h((string)$item['demand_title']);
            $professionalName = h((string)$item['professional_name']);
            $patientName = !empty($item['patient_name']) ? h((string)$item['patient_name']) : 'Paciente não vinculado';
            $operatorEmail = h((string)$item['operator_email']);
            $proposalValue = (float)$item['proposal_value'];
            $totalSessions = (int)$item['total_sessions'];
            $totalProposal = $proposalValue * $totalSessions;
            $sentAt = !empty($item['sent_at']) ? date('d/m/Y H:i', strtotime((string)$item['sent_at'])) : 'Não enviado';
            $specialty = !empty($item['demand_specialty']) ? h((string)$item['demand_specialty']) : 'N/A';
            $location = '';
            if (!empty($item['location_city'])) {
                $location = h((string)$item['location_city']);
                if (!empty($item['location_state'])) {
                    $location .= '/' . h((string)$item['location_state']);
                }
            }
            
            echo '<a href="/authorization_view.php?id=' . $authId . '" class="kanbanCard">';
            echo '<div class="kanbanCardBody">';
            
            echo '<div class="kanbanCardTop">';
            echo '<div class="kanbanCardTitle">' . $demandTitle . '</div>';
            echo '<div style="display:flex;gap:6px;align-items:center">';
            echo '<div style="font-size:11px;font-weight:800;color:hsl(var(--primary));background:hsla(var(--primary)/.1);padding:4px 8px;border-radius:6px" title="ID da Autorização">#' . $authId . '</div>';
            echo '<div style="font-size:11px;font-weight:700;color:hsl(var(--muted-foreground));background:hsla(var(--muted)/.3);padding:4px 8px;border-radius:6px" title="ID do Card de Captação">Card #' . $demandId . '</div>';
            if ($emailId !== null) {
                echo '<div style="font-size:11px;font-weight:700;color:hsl(var(--warning));background:hsla(var(--warning)/.1);padding:4px 8px;border-radius:6px" title="ID do E-mail de Resposta">Email #' . $emailId . '</div>';
            }
            echo '</div>';
            echo '</div>';
            
            echo '<div class="kanbanMeta">';
            echo '👤 ' . $patientName . '<br>';
            echo '👨‍⚕️ ' . $professionalName . '<br>';
            echo '🏥 ' . $specialty;
            if ($location) echo ' | 📍 ' . $location;
            echo '</div>';
            
            echo '<div style="margin-top:8px;padding-top:8px;border-top:1px solid hsl(var(--border));font-size:12px">';
            echo '<div style="display:flex;justify-content:space-between;margin-bottom:4px">';
            echo '<span style="color:hsl(var(--muted-foreground))">📧 ' . $operatorEmail . '</span>';
            echo '</div>';
            echo '<div style="display:flex;justify-content:space-between;margin-bottom:4px">';
            echo '<span style="color:hsl(var(--muted-foreground))">Enviado:</span>';
            echo '<span style="font-weight:700">' . $sentAt . '</span>';
            echo '</div>';
            echo '<div style="display:flex;justify-content:space-between">';
            echo '<span style="color:hsl(var(--muted-foreground))">Valor Total:</span>';
            echo '<span style="font-weight:900;color:hsl(var(--primary))">R$ ' . number_format($totalProposal, 2, ',', '.') . '</span>';
            echo '</div>';
            echo '<div style="font-size:11px;color:hsl(var(--muted-foreground));margin-top:4px">';
            echo $totalSessions . ' sessões × R$ ' . number_format($proposalValue, 2, ',', '.') . '/sessão';
            echo '</div>';
            echo '</div>';
            
            // Indicador de tempo aguardando
            if ($colId === 'aguardando_autorizacao' && !empty($item['sent_at'])) {
                $sentTimestamp = strtotime((string)$item['sent_at']);
                $now = time();
                $diff = $now - $sentTimestamp;
                $minutes = floor($diff / 60);
                $hours = floor($diff / 3600);
                $days = floor($diff / 86400);
                
                $timeText = '';
                $timeColor = 'hsl(var(--muted-foreground))';
                
                if ($days > 0) {
                    $timeText = $days . ' dia' . ($days > 1 ? 's' : '');
                    $timeColor = 'hsl(var(--warning))';
                } elseif ($hours > 0) {
                    $timeText = $hours . ' hora' . ($hours > 1 ? 's' : '');
                    if ($hours >= 24) $timeColor = 'hsl(var(--warning))';
                } else {
                    $timeText = $minutes . ' min';
                }
                
                echo '<div style="margin-top:8px;padding:6px;background:hsla(var(--muted)/.3);border-radius:6px;text-align:center;font-size:11px;font-weight:700;color:' . $timeColor . '">';
                echo '⏱️ Aguardando há ' . $timeText;
                echo '</div>';
            }
            
            echo '</div>';
            echo '</a>';
            
            // Botão de reenviar e-mail
            echo '<div style="padding:8px;border-top:1px solid hsl(var(--border))">';
            echo '<form method="post" action="/authorization_resend_post.php" style="margin:0" onsubmit="return confirm(\'Deseja realmente reenviar o e-mail de proposta?\')">';
            echo '<input type="hidden" name="auth_id" value="' . $authId . '">';
            echo '<input type="hidden" name="resend_notes" value="Reenvio manual da proposta">';
            echo '<button type="submit" class="btn" style="width:100%;padding:8px;font-size:12px;background:hsl(var(--primary));color:white;border:none;border-radius:6px;cursor:pointer;font-weight:600">';
            echo '📧 Reenviar E-mail';
            echo '</button>';
            echo '</form>';
            echo '</div>';
        }
    }
    
    echo '</div>';
    echo '</div>';
}

echo '</div>';
echo '</div>';

echo '<div style="margin-top:20px;padding:16px;background:hsla(var(--muted)/.3);border-radius:12px;border:1px solid hsl(var(--border))">';
echo '<div style="font-weight:700;margin-bottom:8px">ℹ️ Como funciona o fluxo de autorização:</div>';
echo '<ol style="margin:0;padding-left:20px;line-height:1.8;font-size:14px">';
echo '<li>Ao selecionar um profissional no chat, o sistema envia automaticamente um e-mail de proposta para a operadora</li>';
echo '<li>A proposta fica na coluna <strong>"Aguardando Resposta"</strong> por até 5 minutos</li>';
echo '<li>O sistema analisa automaticamente a resposta do e-mail usando IA</li>';
echo '<li><strong>Se aprovado:</strong> Cria paciente, atendimento e lançamentos financeiros automaticamente → Move para Pré-admissão</li>';
echo '<li><strong>Se negado:</strong> Move para coluna <strong>"Negativas"</strong> para revisão manual</li>';
echo '<li>Em "Negativas" você pode reenviar a proposta com novo valor ou finalizar a solicitação</li>';
echo '</ol>';
echo '</div>';

view_footer();
