<?php
/**
 * SCRIPT DE DIAGNÓSTICO - Fluxo de Autorização
 * Execute via navegador para verificar o estado do sistema
 */

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

header('Content-Type: text/html; charset=utf-8');
echo '<pre style="font-family:monospace;font-size:13px;background:#1e1e1e;color:#d4d4d4;padding:20px;border-radius:8px;overflow-x:auto">';

echo "<strong style='color:#00a884'>═══════════════════════════════════════════════════</strong>\n";
echo "<strong style='color:#00a884'>  DIAGNÓSTICO DO FLUXO DE AUTORIZAÇÃO</strong>\n";
echo "<strong style='color:#00a884'>═══════════════════════════════════════════════════</strong>\n\n";

// 1. Verificar autorizações pendentes
echo "<strong style='color:#f59e0b'>1. AUTORIZAÇÕES AGUARDANDO RESPOSTA</strong>\n";
echo "─────────────────────────────────────────────────\n";

$stmt = db()->query("
    SELECT ar.id, ar.demand_id, ar.patient_id, ar.operator_email, ar.status, 
           ar.sent_at, ar.sent_message_id, ar.response_received_at,
           ar.previous_request_id, ar.resend_count,
           d.title as demand_title
    FROM authorization_requests ar
    LEFT JOIN demands d ON d.id = ar.demand_id
    WHERE ar.status IN ('aguardando_autorizacao', 'cancelada')
    AND ar.sent_at IS NOT NULL
    ORDER BY ar.id DESC
    LIMIT 10
");
$pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($pending)) {
    echo "<span style='color:#ef4444'>  ✗ Nenhuma autorização pendente encontrada!</span>\n";
} else {
    foreach ($pending as $p) {
        $statusColor = $p['status'] === 'aguardando_autorizacao' ? '#fbbf24' : '#9ca3af';
        echo "  <span style='color:#60a5fa'>Auth #{$p['id']}</span> | Status: <span style='color:$statusColor'>{$p['status']}</span> | Demand #{$p['demand_id']} | Patient #{$p['patient_id']}\n";
        echo "    Operadora: {$p['operator_email']}\n";
        echo "    Enviado em: {$p['sent_at']}\n";
        echo "    sent_message_id: <span style='color:#fbbf24'>{$p['sent_message_id']}</span>\n";
        if ($p['patient_id'] <= 0) {
            echo "    <span style='color:#ef4444'>⚠ PROBLEMA: patient_id está vazio/zero! O matching vai ignorar esta autorização.</span>\n";
        }
        if (!empty($p['previous_request_id'])) {
            echo "    Reenvio de: Auth #{$p['previous_request_id']}\n";
        }
        echo "    Título: {$p['demand_title']}\n\n";
    }
}

// 2. Verificar e-mails recebidos recentes
echo "\n<strong style='color:#f59e0b'>2. E-MAILS RECEBIDOS (últimos 20)</strong>\n";
echo "─────────────────────────────────────────────────\n";

// Verificar quais colunas existem
$cols = db()->query("SHOW COLUMNS FROM inbound_emails")->fetchAll(PDO::FETCH_COLUMN);
$hasInReplyTo = in_array('in_reply_to', $cols);
$hasFromEmail = in_array('from_email', $cols);
$hasFromAddress = in_array('from_address', $cols);

$fromCol = $hasFromEmail ? 'from_email' : ($hasFromAddress ? 'from_address' : 'NULL');
$inReplyToCol = $hasInReplyTo ? 'in_reply_to' : "'N/A - coluna não existe!'";

$stmt = db()->query("
    SELECT id, message_id, $inReplyToCol as in_reply_to, $fromCol as from_email, 
           subject, status, received_at, error_message
    FROM inbound_emails 
    ORDER BY id DESC 
    LIMIT 20
");
$emails = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($emails)) {
    echo "<span style='color:#ef4444'>  ✗ Nenhum e-mail na tabela inbound_emails!</span>\n";
    echo "  <span style='color:#9ca3af'>Possíveis causas:</span>\n";
    echo "  - Cron smtp_poll.php não está rodando\n";
    echo "  - IMAP não está configurado\n";
    echo "  - E-mails estão em outra caixa\n";
} else {
    echo "  <span style='color:#4ade80'>Coluna in_reply_to existe: " . ($hasInReplyTo ? 'SIM' : 'NÃO') . "</span>\n\n";
    foreach ($emails as $em) {
        $statusColor = match($em['status']) {
            'received' => '#fbbf24',
            'processed' => '#4ade80',
            'error' => '#ef4444',
            default => '#9ca3af'
        };
        echo "  <span style='color:#60a5fa'>Email #{$em['id']}</span> | Status: <span style='color:$statusColor'>{$em['status']}</span>\n";
        echo "    De: {$em['from_email']}\n";
        echo "    Assunto: {$em['subject']}\n";
        echo "    Recebido: {$em['received_at']}\n";
        echo "    Message-ID: {$em['message_id']}\n";
        echo "    In-Reply-To: <span style='color:#fbbf24'>{$em['in_reply_to']}</span>\n";
        if (!empty($em['error_message'])) {
            echo "    <span style='color:#ef4444'>ERRO: {$em['error_message']}</span>\n";
        }
        echo "\n";
    }
}

// 3. Tentar match manual
echo "\n<strong style='color:#f59e0b'>3. TENTATIVA DE MATCH MANUAL</strong>\n";
echo "─────────────────────────────────────────────────\n";

if (!empty($pending) && !empty($emails)) {
    $matchFound = false;
    foreach ($emails as $em) {
        foreach ($pending as $p) {
            $inReplyTo = trim((string)($em['in_reply_to'] ?? ''));
            $sentMsgId = trim((string)($p['sent_message_id'] ?? ''));
            
            // Critério 1: Exato
            if ($inReplyTo !== '' && $sentMsgId !== '' && $inReplyTo === $sentMsgId) {
                echo "  <span style='color:#4ade80'>✓ MATCH EXATO!</span> Email #{$em['id']} → Auth #{$p['id']}\n";
                $matchFound = true;
            }
            
            // Critério 2: Normalizado
            if (!$matchFound && $inReplyTo !== '' && $sentMsgId !== '') {
                $norm1 = trim(str_replace(['<', '>', ' '], '', $inReplyTo));
                $norm2 = trim(str_replace(['<', '>', ' '], '', $sentMsgId));
                if ($norm1 !== '' && $norm1 === $norm2) {
                    echo "  <span style='color:#4ade80'>✓ MATCH NORMALIZADO!</span> Email #{$em['id']} → Auth #{$p['id']}\n";
                    echo "    Normalizado In-Reply-To: $norm1\n";
                    echo "    Normalizado sent_message_id: $norm2\n";
                    $matchFound = true;
                }
            }
            
            // Critério 3: Operadora + tempo
            $authOp = strtolower(trim((string)($p['operator_email'] ?? '')));
            $emailFrom = strtolower(trim((string)($em['from_email'] ?? '')));
            if (!$matchFound && $authOp !== '' && $emailFrom !== '' && $authOp === $emailFrom) {
                echo "  <span style='color:#fbbf24'>⚡ MATCH POR REMETENTE!</span> Email #{$em['id']} (de: $emailFrom) → Auth #{$p['id']} (op: $authOp)\n";
                
                // Verificar status do email
                if ($em['status'] !== 'received') {
                    echo "    <span style='color:#ef4444'>⚠ PORÉM: e-mail já foi processado (status: {$em['status']})</span>\n";
                } else {
                    echo "    <span style='color:#4ade80'>✓ E-mail está com status 'received' - DEVERIA ser processado pelo cron!</span>\n";
                }
                $matchFound = true;
            }
        }
    }
    
    if (!$matchFound) {
        echo "  <span style='color:#ef4444'>✗ Nenhum match encontrado entre e-mails e autorizações pendentes</span>\n";
        echo "\n  Possíveis causas:\n";
        echo "  - E-mail de resposta não foi capturado pelo IMAP (não está na tabela inbound_emails)\n";
        echo "  - A operadora respondeu de outro e-mail diferente do configurado\n";
        echo "  - O cron openai_extract_email_to_demand.php não está rodando\n";
    }
} else {
    echo "  <span style='color:#9ca3af'>Não é possível testar match (sem autorizações ou e-mails)</span>\n";
}

// 4. Verificar patient_assignments (Pré-admissão)
echo "\n\n<strong style='color:#f59e0b'>4. PATIENT ASSIGNMENTS (PÉR-ADMISSÃO)</strong>\n";
echo "─────────────────────────────────────────────────\n";

$stmt = db()->query("
    SELECT pa.id, pa.demand_id, pa.patient_id, pa.status, pa.approved_at, pa.created_at,
           p.full_name as patient_name
    FROM patient_assignments pa
    LEFT JOIN patients p ON p.id = pa.patient_id
    WHERE pa.status = 'confirmed' AND pa.approved_at IS NULL
    ORDER BY pa.id DESC LIMIT 10
");
$assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($assignments)) {
    echo "  <span style='color:#ef4444'>✗ Nenhum assignment na pré-admissão (confirmed + approved_at IS NULL)</span>\n";
} else {
    foreach ($assignments as $a) {
        echo "  <span style='color:#4ade80'>✓ Assignment #{$a['id']}</span> | Demand #{$a['demand_id']} | {$a['patient_name']} | Status: {$a['status']}\n";
    }
}

// 5. Status das demandas relevantes
echo "\n\n<strong style='color:#f59e0b'>5. STATUS DAS DEMANDAS #535</strong>\n";
echo "─────────────────────────────────────────────────\n";

$stmt = db()->query("SELECT id, title, status, origin_email FROM demands WHERE id = 535");
$d = $stmt->fetch(PDO::FETCH_ASSOC);
if ($d) {
    echo "  Demand #{$d['id']}: {$d['title']}\n";
    echo "  Status: <span style='color:#fbbf24'>{$d['status']}</span>\n";
    echo "  Origin: {$d['origin_email']}\n";
}

echo "\n\n<strong style='color:#00a884'>═══════════════════════════════════════════════════</strong>\n";
echo "<strong style='color:#00a884'>  FIM DO DIAGNÓSTICO</strong>\n";
echo "<strong style='color:#00a884'>═══════════════════════════════════════════════════</strong>\n";
echo '</pre>';
