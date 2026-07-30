<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('appointments.manage');

// DEBUG COMPLETO
error_log("=== CHAT_SELECT_PROFESSIONAL DEBUG ===");
error_log("GET params: " . print_r($_GET, true));

$chatId = isset($_GET['chat_id']) ? trim((string)$_GET['chat_id']) : '';
$prefDemandId = isset($_GET['demand_id']) ? (int)$_GET['demand_id'] : 0;

error_log("chatId recebido: " . $chatId);
error_log("prefDemandId recebido: " . $prefDemandId);

// Buscar contato na tabela correta usando remote_jid
$stmt = db()->prepare('SELECT * FROM chat_contacts WHERE remote_jid = :jid');
$stmt->execute(['jid' => $chatId]);
$chat = $stmt->fetch();

error_log("Chat encontrado: " . ($chat ? "SIM (ID: " . $chat['id'] . ", Nome: " . $chat['contact_name'] . ")" : "NÃO"));

if (!$chat) {
    error_log("ERRO: Contato não encontrado para remote_jid: " . $chatId);
    flash_set('error', 'Contato não encontrado. Chat ID: ' . $chatId);
    header('Location: /chat_web.php');
    exit;
}

// Debug: verificar se a demanda existe e qual seu status
if ($prefDemandId > 0) {
    error_log("Verificando demanda ID: " . $prefDemandId);
    $debugStmt = db()->prepare('SELECT id, title, status FROM demands WHERE id = :id');
    $debugStmt->execute(['id' => $prefDemandId]);
    $debugDemand = $debugStmt->fetch();
    
    error_log("Demanda encontrada: " . ($debugDemand ? "SIM" : "NÃO"));
    if ($debugDemand) {
        error_log("Demanda #" . $debugDemand['id'] . " - Status: " . $debugDemand['status']);
    }
    
    if (!$debugDemand) {
        error_log("ERRO: Demanda #" . $prefDemandId . " não existe no banco");
        flash_set('error', 'Demanda #' . $prefDemandId . ' não encontrada no banco de dados.');
        header('Location: /chat_web.php?chat=' . urlencode($chatId));
        exit;
    }
    
    $validStatuses = ['aguardando_captacao','tratamento_manual','em_captacao'];
    if (!in_array((string)$debugDemand['status'], $validStatuses)) {
        error_log("ERRO: Demanda #" . $prefDemandId . " tem status inválido: " . $debugDemand['status']);
        flash_set('error', 'Demanda #' . $prefDemandId . ' não está disponível para seleção. Status atual: ' . $debugDemand['status'] . '. Status aceitos: ' . implode(', ', $validStatuses));
        header('Location: /chat_web.php?chat=' . urlencode($chatId));
        exit;
    }
    
    error_log("Demanda #" . $prefDemandId . " validada com sucesso!");
}

$specialtiesStmt = db()->query("SELECT id, name FROM specialties WHERE status = 'active' ORDER BY name ASC");
$specialties = $specialtiesStmt->fetchAll(PDO::FETCH_ASSOC);

$patients = db()->query("SELECT id, full_name FROM patients WHERE deleted_at IS NULL ORDER BY full_name ASC")->fetchAll();
$professionals = db()->query(
    "SELECT u.id, u.name, u.email FROM users u 
     INNER JOIN user_roles ur ON ur.user_id = u.id 
     INNER JOIN roles r ON r.id = ur.role_id 
     WHERE u.status = 'active' AND r.slug = 'profissional' 
     ORDER BY u.name ASC"
)->fetchAll();

$demands = db()->query(
    "SELECT id, title, status, origin_email, specialty, location_city, location_state 
     FROM demands 
     WHERE status IN ('aguardando_captacao','tratamento_manual','em_captacao') 
     ORDER BY id DESC LIMIT 200"
)->fetchAll();

$prefPatientId = 0;
$prefProfessionalUserId = 0;

// Tabela chat_contacts não tem contact_kind/contact_ref_id
// Esses campos serão preenchidos manualmente no formulário

view_header('Selecionar Profissional');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-bottom:6px">Chat</div>';
echo '<div style="font-size:22px;font-weight:900">Selecionar Profissional</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Selecione o profissional e defina os parâmetros da proposta. O sistema enviará automaticamente um e-mail para a operadora / cliente aguardando autorização.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/chat_web.php?chat=' . urlencode((string)$chat['remote_jid']) . '">Voltar ao chat</a>';
echo '</div>';
echo '</div>';
echo '</section>';

echo '<section class="card col12">';
echo '<form method="post" action="/chat_select_professional_post.php" style="display:grid;gap:12px;max-width:980px" id="selectProfessionalForm">';
echo '<input type="hidden" name="chat_jid" value="' . h((string)$chat['remote_jid']) . '">';

echo '<div class="formSection">';
echo '<div class="formSectionTitle">📋 Dados da Demanda</div>';
echo '<div class="grid">';

echo '<div class="col6">';
echo '<label>Demanda (card)<select name="demand_id" id="demandSelect">';
echo '<option value="">Selecione a demanda</option>';
foreach ($demands as $d) {
    $sel = ((int)$d['id'] === $prefDemandId) ? ' selected' : '';
    $specialty = !empty($d['specialty']) ? h((string)$d['specialty']) : 'Sem especialidade';
    $location = '';
    if (!empty($d['location_city'])) {
        $location = h((string)$d['location_city']);
        if (!empty($d['location_state'])) {
            $location .= '/' . h((string)$d['location_state']);
        }
    }
    echo '<option value="' . (int)$d['id'] . '"' . $sel . ' data-email="' . h((string)$d['origin_email']) . '" data-specialty="' . h((string)$d['specialty']) . '">';
    echo '#' . (int)$d['id'] . ' — ' . h((string)$d['title']) . ' | ' . $specialty;
    if ($location) echo ' | ' . $location;
    echo '</option>';
}
echo '</select></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label>E-mail da Operadora / Cliente<input type="email" name="operator_email" id="operatorEmail" required placeholder="contato@operadora.com.br"></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label>Paciente<select name="patient_id" required>';
echo '<option value="">Selecione</option>';
foreach ($patients as $p) {
    $sel = ((int)$p['id'] === $prefPatientId) ? ' selected' : '';
    echo '<option value="' . (int)$p['id'] . '"' . $sel . '>' . h((string)$p['full_name']) . ' (#' . (int)$p['id'] . ')</option>';
}
echo '</select></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label>Telefone do contato<input value="' . h((string)$chat['external_phone']) . '" readonly></label>';
echo '</div>';

echo '</div>';
echo '</div>';

echo '<div class="formSection">';
echo '<div class="formSectionTitle">👨‍⚕️ Profissional e Especialidade</div>';
echo '<div class="grid">';

echo '<div class="col6">';
echo '<label>Profissional<select name="professional_user_id" id="professionalSelect" required>';
echo '<option value="">Selecione</option>';
foreach ($professionals as $idx => $u) {
    $sel = ((int)$u['id'] === $prefProfessionalUserId) ? ' selected' : '';
    // Seleção anônima: exibir apenas código + especialidade (sem nome/email)
    // Dados completos só são revelados após confirmação da contratação
    $profLabel = 'Profissional #' . str_pad((string)($idx + 1), 3, '0', STR_PAD_LEFT);
    echo '<option value="' . (int)$u['id'] . '"' . $sel . ' data-name="' . h((string)$u['name']) . '">' . $profLabel . '</option>';
}
echo '</select></label>';
echo '<div class="helpText">Identidade revelada somente após confirmação da contratação</div>';
echo '</div>';

echo '<div class="col6">';
echo '<label>Especialidade<select name="specialty" id="specialtySelect" required>';
echo '<option value="">Selecione...</option>';
foreach ($specialties as $spec) {
    $specName = isset($spec['name']) ? (string)$spec['name'] : '';
    if ($specName !== '') {
        echo '<option value="' . h($specName) . '">' . h($specName) . '</option>';
    }
}
echo '</select></label>';
echo '</div>';

echo '</div>';
echo '</div>';

echo '<div class="formSection">';
echo '<div class="formSectionTitle">💰 Valores</div>';
echo '<div class="grid">';

echo '<div class="col6">';
echo '<label>Valor Acordado (profissional)<input type="number" step="0.01" min="0" name="agreed_value" id="agreedValue" required value="0.00" placeholder="150.00"></label>';
echo '<div class="helpText">Valor que o profissional receberá por sessão</div>';
echo '</div>';

echo '<div class="col6">';
echo '<label>Valor de Proposta (operadora / cliente)<input type="number" step="0.01" min="0" name="proposal_value" id="proposalValue" required value="0.00" placeholder="200.00"></label>';
echo '<div class="helpText">Valor que será oferecido à operadora / cliente por sessão</div>';
echo '</div>';

echo '<div class="col12">';
echo '<div style="padding:14px;background:hsla(var(--primary)/.08);border:1px solid hsla(var(--primary)/.2);border-radius:10px">';
echo '<div style="font-weight:700;margin-bottom:8px">💵 Resumo Financeiro (por sessão)</div>';
echo '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;font-size:14px">';
echo '<div><strong>Valor Proposta:</strong><br><span id="totalProposal" style="font-size:18px;font-weight:900;color:hsl(var(--primary))">R$ 0,00</span></div>';
echo '<div><strong>Custo (profissional):</strong><br><span id="totalCost" style="font-size:18px;font-weight:900">R$ 0,00</span></div>';
echo '<div><strong>Margem por Sessão:</strong><br><span id="totalMargin" style="font-size:18px;font-weight:900;color:hsl(var(--success))">R$ 0,00</span></div>';
echo '</div>';
echo '</div>';
echo '</div>';

echo '<div class="col12">';
echo '<label>Observações (opcional)<textarea name="notes" rows="2" placeholder="Informações adicionais sobre a proposta"></textarea></label>';
echo '</div>';

echo '</div>';
echo '</div>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;margin-top:6px">';
echo '<a class="btn" href="/chat_web.php?id=' . (int)$chat['id'] . '">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">📧 Enviar Proposta e Aguardar Autorização</button>';
echo '</div>';

echo '</form>';
echo '</section>';

echo '</div>';

echo '<script>';
echo 'document.addEventListener("DOMContentLoaded", function() {';

// Auto-preencher e-mail da operadora ao selecionar demanda
echo '  const demandSelect = document.getElementById("demandSelect");';
echo '  const operatorEmail = document.getElementById("operatorEmail");';
echo '  const specialtySelect = document.getElementById("specialtySelect");';
echo '  ';
echo '  if (demandSelect && operatorEmail) {';
echo '    demandSelect.addEventListener("change", function() {';
echo '      const selectedOption = this.options[this.selectedIndex];';
echo '      const email = selectedOption.getAttribute("data-email");';
echo '      const specialty = selectedOption.getAttribute("data-specialty");';
echo '      if (email) operatorEmail.value = email;';
echo '      if (specialty && specialtySelect) {';
echo '        for (let i = 0; i < specialtySelect.options.length; i++) {';
echo '          if (specialtySelect.options[i].value === specialty) {';
echo '            specialtySelect.selectedIndex = i;';
echo '            break;';
echo '          }';
echo '        }';
echo '      }';
echo '    });';
echo '  }';

// Auto-preencher especialidade ao selecionar profissional
echo '  const professionalSelect = document.getElementById("professionalSelect");';
echo '  if (professionalSelect && specialtySelect) {';
echo '    professionalSelect.addEventListener("change", async function() {';
echo '      const userId = this.value;';
echo '      if (!userId) return;';
echo '      try {';
echo '        const response = await fetch("/api/get_user_specialty.php?user_id=" + userId);';
echo '        const data = await response.json();';
echo '        if (data.specialty) {';
echo '          for (let i = 0; i < specialtySelect.options.length; i++) {';
echo '            if (specialtySelect.options[i].value === data.specialty) {';
echo '              specialtySelect.selectedIndex = i;';
echo '              break;';
echo '            }';
echo '          }';
echo '        }';
echo '      } catch (err) {';
echo '        console.error("Erro ao buscar especialidade:", err);';
echo '      }';
echo '    });';
echo '  }';

// Calcular totais automaticamente
echo '  const agreedValueInput = document.getElementById("agreedValue");';
echo '  const proposalValueInput = document.getElementById("proposalValue");';
echo '  const totalProposalSpan = document.getElementById("totalProposal");';
echo '  const totalCostSpan = document.getElementById("totalCost");';
echo '  const totalMarginSpan = document.getElementById("totalMargin");';
echo '  ';
echo '  function calculateTotals() {';
echo '    const agreed = parseFloat(agreedValueInput.value) || 0;';
echo '    const proposal = parseFloat(proposalValueInput.value) || 0;';
echo '    ';
echo '    totalProposalSpan.textContent = "R$ " + proposal.toFixed(2).replace(".", ",");';
echo '    totalCostSpan.textContent = "R$ " + agreed.toFixed(2).replace(".", ",");';
echo '    const margin = proposal - agreed;';
echo '    totalMarginSpan.textContent = "R$ " + margin.toFixed(2).replace(".", ",");';
echo '    ';
echo '    if (margin < 0) {';
echo '      totalMarginSpan.style.color = "hsl(var(--destructive))";';
echo '    } else {';
echo '      totalMarginSpan.style.color = "hsl(var(--success))";';
echo '    }';
echo '  }';
echo '  ';
echo '  if (agreedValueInput) agreedValueInput.addEventListener("input", calculateTotals);';
echo '  if (proposalValueInput) proposalValueInput.addEventListener("input", calculateTotals);';
echo '  calculateTotals();';

echo '});';
echo '</script>';

view_footer();
