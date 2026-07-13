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
    'SELECT ar.*, d.title as demand_title, d.specialty as demand_specialty
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
    flash_set('error', 'Apenas propostas negadas podem ser reenviadas.');
    header('Location: /authorization_view.php?id=' . $authId);
    exit;
}

view_header('Reenviar Proposta #' . $authId);

echo '<div class="pageHeader">';
echo '<div>';
echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-bottom:6px">Autorização de Proposta</div>';
echo '<h1>🔄 Reenviar Proposta #' . $authId . '</h1>';
echo '</div>';
echo '<div class="pageHeaderActions">';
echo '<a href="/authorization_view.php?id=' . $authId . '" class="btn">← Voltar</a>';
echo '</div>';
echo '</div>';

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="padding:16px;background:hsla(var(--warning)/.1);border:1px solid hsla(var(--warning)/.3);border-radius:12px;margin-bottom:20px">';
echo '<div style="font-weight:700;margin-bottom:8px">⚠️ Atenção</div>';
echo '<p style="margin:0;line-height:1.6">Esta proposta foi negada pela operadora / cliente. Você pode ajustar o valor e reenviar uma nova proposta.</p>';
if (!empty($auth['denial_reason'])) {
    echo '<div style="margin-top:12px;padding:10px;background:white;border-radius:8px">';
    echo '<strong>Motivo da negação:</strong><br>';
    echo nl2br(h((string)$auth['denial_reason']));
    echo '</div>';
}
echo '</div>';

echo '<form method="post" action="/authorization_resend_post.php" style="display:grid;gap:12px;max-width:800px">';
echo '<input type="hidden" name="auth_id" value="' . $authId . '">';

echo '<div class="formSection">';
echo '<div class="formSectionTitle">📋 Dados Atuais</div>';
echo '<div class="grid">';
echo '<div class="col6"><strong>Demanda:</strong> ' . h((string)$auth['demand_title']) . '</div>';
echo '<div class="col6"><strong>Especialidade:</strong> ' . h((string)$auth['demand_specialty']) . '</div>';
echo '<div class="col6"><strong>Operadora / Cliente:</strong> ' . h((string)$auth['operator_email']) . '</div>';
echo '<div class="col6"><strong>Total de Sessões:</strong> ' . (int)$auth['total_sessions'] . '</div>';
echo '</div>';
echo '</div>';

echo '<div class="formSection">';
echo '<div class="formSectionTitle">💰 Valores Anteriores</div>';
echo '<div class="grid">';
$previousProposal = (float)$auth['proposal_value'];
$previousAgreed = (float)$auth['agreed_value'];
$totalSessions = (int)$auth['total_sessions'];
$previousTotal = $previousProposal * $totalSessions;

echo '<div class="col6">';
echo '<label>Valor Anterior (Proposta)<input type="text" value="R$ ' . number_format($previousProposal, 2, ',', '.') . '" readonly></label>';
echo '</div>';
echo '<div class="col6">';
echo '<label>Valor Anterior (Profissional)<input type="text" value="R$ ' . number_format($previousAgreed, 2, ',', '.') . '" readonly></label>';
echo '</div>';
echo '<div class="col12">';
echo '<div style="padding:10px;background:hsla(var(--muted)/.3);border-radius:8px;font-size:14px">';
echo '<strong>Total Anterior:</strong> R$ ' . number_format($previousTotal, 2, ',', '.') . ' (' . $totalSessions . ' sessões)';
echo '</div>';
echo '</div>';
echo '</div>';
echo '</div>';

echo '<div class="formSection">';
echo '<div class="formSectionTitle">💵 Novos Valores</div>';
echo '<div class="grid">';

echo '<div class="col6">';
echo '<label>Novo Valor de Proposta (operadora / cliente)<input type="number" step="0.01" min="0" name="new_proposal_value" id="newProposalValue" required placeholder="' . number_format($previousProposal, 2, '.', '') . '"></label>';
echo '<div class="helpText">Valor que será oferecido à operadora por sessão</div>';
echo '</div>';

echo '<div class="col6">';
echo '<label>Valor Acordado (profissional)<input type="number" step="0.01" min="0" name="new_agreed_value" id="newAgreedValue" required value="' . number_format($previousAgreed, 2, '.', '') . '"></label>';
echo '<div class="helpText">Valor que o profissional receberá por sessão</div>';
echo '</div>';

echo '<div class="col12">';
echo '<div style="padding:14px;background:hsla(var(--primary)/.08);border:1px solid hsla(var(--primary)/.2);border-radius:10px">';
echo '<div style="font-weight:700;margin-bottom:8px">💵 Novo Resumo Financeiro</div>';
echo '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;font-size:14px">';
echo '<div><strong>Valor Total da Proposta:</strong><br><span id="newTotalProposal" style="font-size:18px;font-weight:900;color:hsl(var(--primary))">R$ 0,00</span></div>';
echo '<div><strong>Custo Total:</strong><br><span id="newTotalCost" style="font-size:18px;font-weight:900">R$ 0,00</span></div>';
echo '<div><strong>Nova Margem:</strong><br><span id="newTotalMargin" style="font-size:18px;font-weight:900;color:hsl(var(--success))">R$ 0,00</span></div>';
echo '</div>';
echo '</div>';
echo '</div>';

echo '<div class="col12">';
echo '<label>Justificativa do Reenvio<textarea name="resend_notes" rows="3" required placeholder="Explique as alterações realizadas e por que a proposta deve ser reconsiderada..."></textarea></label>';
echo '</div>';

echo '</div>';
echo '</div>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;margin-top:6px">';
echo '<a class="btn" href="/authorization_view.php?id=' . $authId . '">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">📧 Reenviar Proposta</button>';
echo '</div>';

echo '</form>';
echo '</section>';

echo '</div>';

echo '<script>';
echo 'const totalSessions = ' . $totalSessions . ';';
echo 'const newProposalInput = document.getElementById("newProposalValue");';
echo 'const newAgreedInput = document.getElementById("newAgreedValue");';
echo 'const newTotalProposalSpan = document.getElementById("newTotalProposal");';
echo 'const newTotalCostSpan = document.getElementById("newTotalCost");';
echo 'const newTotalMarginSpan = document.getElementById("newTotalMargin");';
echo '';
echo 'function calculateNewTotals() {';
echo '  const proposal = parseFloat(newProposalInput.value) || 0;';
echo '  const agreed = parseFloat(newAgreedInput.value) || 0;';
echo '  ';
echo '  const totalProposal = totalSessions * proposal;';
echo '  const totalCost = totalSessions * agreed;';
echo '  const totalMargin = totalProposal - totalCost;';
echo '  ';
echo '  newTotalProposalSpan.textContent = "R$ " + totalProposal.toFixed(2).replace(".", ",");';
echo '  newTotalCostSpan.textContent = "R$ " + totalCost.toFixed(2).replace(".", ",");';
echo '  newTotalMarginSpan.textContent = "R$ " + totalMargin.toFixed(2).replace(".", ",");';
echo '  ';
echo '  if (totalMargin < 0) {';
echo '    newTotalMarginSpan.style.color = "hsl(var(--destructive))";';
echo '  } else {';
echo '    newTotalMarginSpan.style.color = "hsl(var(--success))";';
echo '  }';
echo '}';
echo '';
echo 'newProposalInput.addEventListener("input", calculateNewTotals);';
echo 'newAgreedInput.addEventListener("input", calculateNewTotals);';
echo '</script>';

view_footer();
