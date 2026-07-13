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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cancelReason = trim((string)($_POST['cancel_reason'] ?? ''));
    
    if ($cancelReason === '') {
        flash_set('error', 'Informe o motivo do cancelamento.');
        header('Location: /authorization_cancel.php?id=' . $authId);
        exit;
    }
    
    $db = db();
    
    $stmt = $db->prepare('SELECT id, demand_id, status FROM authorization_requests WHERE id = :id');
    $stmt->execute(['id' => $authId]);
    $auth = $stmt->fetch();
    
    if (!$auth) {
        flash_set('error', 'Autorização não encontrada.');
        header('Location: /authorization_list.php');
        exit;
    }
    
    if ((string)$auth['status'] !== 'autorizacao_negada') {
        flash_set('error', 'Apenas propostas negadas podem ser canceladas.');
        header('Location: /authorization_view.php?id=' . $authId);
        exit;
    }
    
    $userId = auth_user_id();
    
    $db->beginTransaction();
    try {
        // Atualizar status para cancelada
        $stmt = $db->prepare('UPDATE authorization_requests SET status = :status WHERE id = :id');
        $stmt->execute(['status' => 'cancelada', 'id' => $authId]);
        
        // Atualizar demanda para cancelado
        $stmt = $db->prepare('UPDATE demands SET status = :status WHERE id = :id');
        $stmt->execute(['status' => 'cancelado', 'id' => $auth['demand_id']]);
        
        // Registrar histórico
        $stmt = $db->prepare(
            'INSERT INTO authorization_request_history 
            (authorization_request_id, action, notes, user_id) 
            VALUES (:auth_id, :action, :notes, :uid)'
        );
        $stmt->execute([
            'auth_id' => $authId,
            'action' => 'cancelled',
            'notes' => "Solicitação finalizada. Motivo: $cancelReason",
            'uid' => $userId
        ]);
        
        $db->commit();
        
        flash_set('success', 'Solicitação de autorização finalizada com sucesso.');
        header('Location: /authorization_list.php');
        exit;
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log('Erro ao cancelar autorização: ' . $e->getMessage());
        flash_set('error', 'Erro ao finalizar solicitação: ' . $e->getMessage());
        header('Location: /authorization_cancel.php?id=' . $authId);
        exit;
    }
}

$stmt = db()->prepare(
    'SELECT ar.*, d.title as demand_title
     FROM authorization_requests ar
     INNER JOIN demands d ON d.id = ar.demand_id
     WHERE ar.id = :id'
);
$stmt->execute(['id' => $authId]);
$auth = $stmt->fetch();

if (!$auth) {
    flash_set('error', 'Autorização não encontrada.');
    header('Location: /authorization_list.php');
    exit;
}

if ((string)$auth['status'] !== 'autorizacao_negada') {
    flash_set('error', 'Apenas propostas negadas podem ser canceladas.');
    header('Location: /authorization_view.php?id=' . $authId);
    exit;
}

view_header('Finalizar Solicitação #' . $authId);

echo '<div class="pageHeader">';
echo '<div>';
echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-bottom:6px">Autorização de Proposta</div>';
echo '<h1>⛔ Finalizar Solicitação #' . $authId . '</h1>';
echo '</div>';
echo '<div class="pageHeaderActions">';
echo '<a href="/authorization_view.php?id=' . $authId . '" class="btn">← Voltar</a>';
echo '</div>';
echo '</div>';

echo '<div class="grid">';

echo '<section class="card col12" style="max-width:800px">';

echo '<div style="padding:16px;background:hsla(var(--destructive)/.1);border:1px solid hsla(var(--destructive)/.3);border-radius:12px;margin-bottom:20px">';
echo '<div style="font-weight:700;margin-bottom:8px;color:hsl(var(--destructive))">⚠️ Atenção: Ação Irreversível</div>';
echo '<p style="margin:0;line-height:1.6">Ao finalizar esta solicitação, a demanda será marcada como <strong>cancelada</strong> e não será mais possível reenviar propostas. Esta ação não pode ser desfeita.</p>';
echo '</div>';

echo '<div class="formSection">';
echo '<div class="formSectionTitle">📋 Dados da Solicitação</div>';
echo '<div class="grid">';
echo '<div class="col6"><strong>Demanda:</strong> ' . h((string)$auth['demand_title']) . '</div>';
echo '<div class="col6"><strong>Operadora / Cliente:</strong> ' . h((string)$auth['operator_email']) . '</div>';
if (!empty($auth['denial_reason'])) {
    echo '<div class="col12">';
    echo '<strong>Motivo da Negação:</strong><br>';
    echo '<div style="padding:10px;background:hsla(var(--muted)/.3);border-radius:8px;margin-top:6px">';
    echo nl2br(h((string)$auth['denial_reason']));
    echo '</div>';
    echo '</div>';
}
echo '</div>';
echo '</div>';

echo '<form method="post" style="display:grid;gap:12px">';

echo '<div class="formSection">';
echo '<div class="formSectionTitle">📝 Motivo do Cancelamento</div>';
echo '<label>Por que esta solicitação está sendo finalizada?<textarea name="cancel_reason" rows="4" required placeholder="Ex: Operadora não aceitou nenhum valor proposto, paciente desistiu do atendimento, etc."></textarea></label>';
echo '<div class="helpText">Esta informação será registrada no histórico para referência futura</div>';
echo '</div>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;margin-top:6px">';
echo '<a class="btn" href="/authorization_view.php?id=' . $authId . '">Cancelar</a>';
echo '<button class="btn" type="submit" style="background:hsl(var(--destructive));color:white">⛔ Confirmar Finalização</button>';
echo '</div>';

echo '</form>';

echo '</section>';

echo '</div>';

view_footer();
