<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$authId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($authId <= 0) {
    flash_set('error', 'Autorização inválida.');
    header('Location: /authorization_list.php');
    exit;
}

$stmt = db()->prepare(
    'SELECT ar.*, 
     d.id as demand_id, d.title as demand_title, d.specialty as demand_specialty, 
     d.location_city, d.location_state, d.origin_email,
     u.id as professional_id, u.name as professional_name, u.email as professional_email, u.phone as professional_phone,
     p.id as patient_id, p.full_name as patient_name, p.email as patient_email, p.whatsapp as patient_phone
     FROM authorization_requests ar
     INNER JOIN demands d ON d.id = ar.demand_id
     INNER JOIN users u ON u.id = ar.professional_user_id
     LEFT JOIN patients p ON p.id = (SELECT patient_id FROM patient_assignments WHERE demand_id = d.id LIMIT 1)
     WHERE ar.id = :id'
);
$stmt->execute(['id' => $authId]);
$auth = $stmt->fetch();

if (!$auth) {
    flash_set('error', 'Autorização não encontrada.');
    header('Location: /authorization_list.php');
    exit;
}

// Buscar histórico
$stmt = db()->prepare(
    'SELECT h.*, u.name as user_name
     FROM authorization_request_history h
     LEFT JOIN users u ON u.id = h.user_id
     WHERE h.authorization_request_id = :id
     ORDER BY h.created_at DESC'
);
$stmt->execute(['id' => $authId]);
$history = $stmt->fetchAll();

$status = (string)$auth['status'];
$statusBadge = match($status) {
    'aguardando_autorizacao' => '<span style="background:hsla(var(--info)/.15);color:hsl(var(--info));padding:6px 12px;border-radius:8px;font-weight:700;font-size:13px">⏳ Aguardando Resposta</span>',
    'autorizacao_aprovada' => '<span style="background:hsla(var(--success)/.15);color:hsl(var(--success));padding:6px 12px;border-radius:8px;font-weight:700;font-size:13px">✅ Aprovada</span>',
    'autorizacao_negada' => '<span style="background:hsla(var(--destructive)/.15);color:hsl(var(--destructive));padding:6px 12px;border-radius:8px;font-weight:700;font-size:13px">❌ Negada</span>',
    'cancelada' => '<span style="background:hsla(var(--muted)/.15);color:hsl(var(--muted-foreground));padding:6px 12px;border-radius:8px;font-weight:700;font-size:13px">⛔ Cancelada</span>',
    default => '<span style="background:hsla(var(--muted)/.15);color:hsl(var(--muted-foreground));padding:6px 12px;border-radius:8px;font-weight:700;font-size:13px">' . h($status) . '</span>'
};

view_header('Autorização #' . $authId);

echo '<div class="pageHeader">';
echo '<div>';
echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-bottom:6px">Autorização de Proposta</div>';
echo '<h1>Proposta #' . $authId . ' ' . $statusBadge . '</h1>';
echo '</div>';
echo '<div class="pageHeaderActions">';
echo '<a href="/authorization_list.php" class="btn">← Voltar</a>';
echo '</div>';
echo '</div>';

echo '<div class="grid">';

// Card principal com dados da proposta
echo '<section class="card col8">';
echo '<div class="formSection">';
echo '<div class="formSectionTitle">📋 Dados da Demanda</div>';
echo '<div class="grid">';
echo '<div class="col6"><strong>Demanda:</strong> #' . (int)$auth['demand_id'] . ' - ' . h((string)$auth['demand_title']) . '</div>';
echo '<div class="col6"><strong>Especialidade:</strong> ' . h((string)$auth['demand_specialty']) . '</div>';
$location = '';
if (!empty($auth['location_city'])) {
    $location = h((string)$auth['location_city']);
    if (!empty($auth['location_state'])) $location .= '/' . h((string)$auth['location_state']);
}
if ($location) {
    echo '<div class="col6"><strong>Localização:</strong> ' . $location . '</div>';
}
echo '<div class="col6"><strong>E-mail Origem:</strong> ' . h((string)$auth['origin_email']) . '</div>';
echo '</div>';
echo '</div>';

echo '<div class="formSection">';
echo '<div class="formSectionTitle">👨‍⚕️ Profissional Selecionado</div>';
echo '<div class="grid">';
echo '<div class="col6"><strong>Nome:</strong> ' . h((string)$auth['professional_name']) . '</div>';
echo '<div class="col6"><strong>E-mail:</strong> ' . h((string)$auth['professional_email']) . '</div>';
if (!empty($auth['professional_phone'])) {
    echo '<div class="col6"><strong>Telefone:</strong> ' . h((string)$auth['professional_phone']) . '</div>';
}
echo '</div>';
echo '</div>';

if (!empty($auth['patient_name'])) {
    echo '<div class="formSection">';
    echo '<div class="formSectionTitle">👤 Paciente</div>';
    echo '<div class="grid">';
    echo '<div class="col6"><strong>Nome:</strong> ' . h((string)$auth['patient_name']) . '</div>';
    if (!empty($auth['patient_email'])) {
        echo '<div class="col6"><strong>E-mail:</strong> ' . h((string)$auth['patient_email']) . '</div>';
    }
    if (!empty($auth['patient_phone'])) {
        echo '<div class="col6"><strong>Telefone:</strong> ' . h((string)$auth['patient_phone']) . '</div>';
    }
    echo '</div>';
    echo '</div>';
}

echo '<div class="formSection">';
echo '<div class="formSectionTitle">📅 Agendamento Proposto</div>';
echo '<div class="grid">';
echo '<div class="col6"><strong>Data de Início:</strong> ' . date('d/m/Y', strtotime((string)$auth['start_date'])) . '</div>';
echo '<div class="col6"><strong>Horário:</strong> ' . h((string)$auth['start_time']) . ' às ' . h((string)$auth['end_time']) . '</div>';

$frequencyText = match((string)$auth['frequency']) {
    'single' => 'Atendimento Único',
    'daily' => 'Diário',
    'weekly' => 'Semanal',
    'biweekly' => 'Quinzenal',
    'monthly' => 'Mensal',
    'custom' => 'Personalizado',
    default => h((string)$auth['frequency'])
};

echo '<div class="col6"><strong>Frequência:</strong> ' . $frequencyText . '</div>';
echo '<div class="col6"><strong>Duração:</strong> ' . (int)$auth['duration_weeks'] . ' semanas</div>';
echo '<div class="col6"><strong>Total de Sessões:</strong> ' . (int)$auth['total_sessions'] . '</div>';

if (!empty($auth['frequency_details'])) {
    $details = json_decode((string)$auth['frequency_details'], true);
    if (is_array($details) && !empty($details['description'])) {
        echo '<div class="col12"><strong>Detalhes:</strong> ' . h((string)$details['description']) . '</div>';
    }
}
echo '</div>';
echo '</div>';

echo '<div class="formSection">';
echo '<div class="formSectionTitle">💰 Valores</div>';
$proposalValue = (float)$auth['proposal_value'];
$agreedValue = (float)$auth['agreed_value'];
$totalSessions = (int)$auth['total_sessions'];
$totalProposal = $proposalValue * $totalSessions;
$totalCost = $agreedValue * $totalSessions;
$margin = $totalProposal - $totalCost;

echo '<div class="grid">';
echo '<div class="col6"><strong>Valor por Sessão (Proposta):</strong> R$ ' . number_format($proposalValue, 2, ',', '.') . '</div>';
echo '<div class="col6"><strong>Valor por Sessão (Profissional):</strong> R$ ' . number_format($agreedValue, 2, ',', '.') . '</div>';
echo '<div class="col12" style="margin-top:12px;padding:14px;background:hsla(var(--primary)/.08);border:1px solid hsla(var(--primary)/.2);border-radius:10px">';
echo '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px">';
echo '<div><strong>Valor Total da Proposta:</strong><br><span style="font-size:20px;font-weight:900;color:hsl(var(--primary))">R$ ' . number_format($totalProposal, 2, ',', '.') . '</span></div>';
echo '<div><strong>Custo Total:</strong><br><span style="font-size:20px;font-weight:900">R$ ' . number_format($totalCost, 2, ',', '.') . '</span></div>';
$marginColor = $margin >= 0 ? 'hsl(var(--success))' : 'hsl(var(--destructive))';
echo '<div><strong>Margem:</strong><br><span style="font-size:20px;font-weight:900;color:' . $marginColor . '">R$ ' . number_format($margin, 2, ',', '.') . '</span></div>';
echo '</div>';
echo '</div>';
echo '</div>';
echo '</div>';

echo '<div class="formSection">';
echo '<div class="formSectionTitle">📧 Informações de Envio</div>';
echo '<div class="grid">';
echo '<div class="col6"><strong>E-mail da Operadora:</strong> ' . h((string)$auth['operator_email']) . '</div>';
if (!empty($auth['operator_name'])) {
    echo '<div class="col6"><strong>Nome da Operadora:</strong> ' . h((string)$auth['operator_name']) . '</div>';
}
if (!empty($auth['sent_at'])) {
    echo '<div class="col6"><strong>Enviado em:</strong> ' . date('d/m/Y H:i:s', strtotime((string)$auth['sent_at'])) . '</div>';
}
if (!empty($auth['response_received_at'])) {
    echo '<div class="col6"><strong>Resposta em:</strong> ' . date('d/m/Y H:i:s', strtotime((string)$auth['response_received_at'])) . '</div>';
}
if (!empty($auth['ai_analysis'])) {
    echo '<div class="col12"><strong>Análise da IA:</strong><br><div style="padding:10px;background:hsla(var(--muted)/.3);border-radius:8px;margin-top:6px">' . nl2br(h((string)$auth['ai_analysis'])) . '</div></div>';
}
if (!empty($auth['denial_reason'])) {
    echo '<div class="col12"><strong>Motivo da Negação:</strong><br><div style="padding:10px;background:hsla(var(--destructive)/.1);border:1px solid hsla(var(--destructive)/.2);border-radius:8px;margin-top:6px;color:hsl(var(--destructive))">' . nl2br(h((string)$auth['denial_reason'])) . '</div></div>';
}
echo '</div>';
echo '</div>';

echo '</section>';

// Sidebar com ações e histórico
echo '<section class="col4">';

// Ações disponíveis
if ($status === 'autorizacao_negada') {
    echo '<div class="card" style="margin-bottom:16px">';
    echo '<div style="font-weight:700;margin-bottom:12px">⚡ Ações Disponíveis</div>';
    echo '<div style="display:flex;flex-direction:column;gap:8px">';
    echo '<a href="/authorization_resend.php?id=' . $authId . '" class="btn btnPrimary" style="text-align:center">🔄 Reenviar com Novo Valor</a>';
    echo '<a href="/authorization_cancel.php?id=' . $authId . '" class="btn" style="text-align:center;background:hsl(var(--destructive));color:white">⛔ Finalizar Solicitação</a>';
    echo '</div>';
    echo '</div>';
}

// Histórico
echo '<div class="card">';
echo '<div style="font-weight:700;margin-bottom:12px">📜 Histórico</div>';

if (count($history) === 0) {
    echo '<div style="color:hsl(var(--muted-foreground));font-size:13px;text-align:center;padding:20px">Nenhum registro</div>';
} else {
    echo '<div style="display:flex;flex-direction:column;gap:12px">';
    foreach ($history as $h) {
        $actionText = match((string)$h['action']) {
            'created' => '📝 Criada',
            'sent' => '📧 Enviada',
            'response_received' => '📨 Resposta Recebida',
            'approved' => '✅ Aprovada',
            'denied' => '❌ Negada',
            'resent' => '🔄 Reenviada',
            'cancelled' => '⛔ Cancelada',
            default => h((string)$h['action'])
        };
        
        echo '<div style="padding:10px;background:hsla(var(--muted)/.3);border-radius:8px;font-size:13px">';
        echo '<div style="font-weight:700;margin-bottom:4px">' . $actionText . '</div>';
        echo '<div style="color:hsl(var(--muted-foreground));font-size:12px">' . date('d/m/Y H:i', strtotime((string)$h['created_at'])) . '</div>';
        if (!empty($h['user_name'])) {
            echo '<div style="color:hsl(var(--muted-foreground));font-size:12px">Por: ' . h((string)$h['user_name']) . '</div>';
        }
        if (!empty($h['notes'])) {
            echo '<div style="margin-top:6px;font-size:12px">' . h((string)$h['notes']) . '</div>';
        }
        if (!empty($h['proposal_value'])) {
            echo '<div style="margin-top:4px;font-weight:700;color:hsl(var(--primary))">R$ ' . number_format((float)$h['proposal_value'], 2, ',', '.') . '/sessão</div>';
        }
        echo '</div>';
    }
    echo '</div>';
}

echo '</div>';

echo '</section>';

echo '</div>';

view_footer();
