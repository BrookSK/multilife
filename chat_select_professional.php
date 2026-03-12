<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('appointments.manage');

$chatId = isset($_GET['chat_id']) ? (int)$_GET['chat_id'] : 0;
$prefDemandId = isset($_GET['demand_id']) ? (int)$_GET['demand_id'] : 0;

$stmt = db()->prepare('SELECT * FROM chat_conversations WHERE id = :id');
$stmt->execute(['id' => $chatId]);
$chat = $stmt->fetch();

if (!$chat) {
    flash_set('error', 'Conversa não encontrada.');
    header('Location: /chat_web.php');
    exit;
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

if ((string)$chat['contact_kind'] === 'patient' && $chat['contact_ref_id'] !== null) {
    $prefPatientId = (int)$chat['contact_ref_id'];
}

if ((string)$chat['contact_kind'] === 'professional' && $chat['contact_ref_id'] !== null) {
    $stmt = db()->prepare('SELECT created_user_id FROM professional_applications WHERE id = :id');
    $stmt->execute(['id' => (int)$chat['contact_ref_id']]);
    $pa = $stmt->fetch();
    if ($pa && $pa['created_user_id'] !== null) {
        $prefProfessionalUserId = (int)$pa['created_user_id'];
    }
}

view_header('Selecionar Profissional');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-bottom:6px">Chat</div>';
echo '<div style="font-size:22px;font-weight:900">Selecionar Profissional</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Selecione o profissional e defina os parâmetros da proposta. O sistema enviará automaticamente um e-mail para a operadora aguardando autorização.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/chat_web.php?id=' . (int)$chat['id'] . '">Voltar ao chat</a>';
echo '</div>';
echo '</div>';
echo '</section>';

echo '<section class="card col12">';
echo '<form method="post" action="/chat_select_professional_post.php" style="display:grid;gap:12px;max-width:980px" id="selectProfessionalForm">';
echo '<input type="hidden" name="chat_id" value="' . (int)$chat['id'] . '">';

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
echo '<label>E-mail da Operadora<input type="email" name="operator_email" id="operatorEmail" required placeholder="contato@operadora.com.br"></label>';
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
foreach ($professionals as $u) {
    $sel = ((int)$u['id'] === $prefProfessionalUserId) ? ' selected' : '';
    echo '<option value="' . (int)$u['id'] . '"' . $sel . '>' . h((string)$u['name']) . ' — ' . h((string)$u['email']) . '</option>';
}
echo '</select></label>';
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
echo '<div class="formSectionTitle">📅 Agendamento Proposto</div>';
echo '<div class="grid">';

echo '<div class="col6">';
echo '<label>Data de Início<input type="date" name="start_date" id="startDate" required></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label>Frequência<select name="frequency" id="frequencySelect" required>';
echo '<option value="single">Atendimento Único</option>';
echo '<option value="daily">Diário</option>';
echo '<option value="weekly" selected>Semanal</option>';
echo '<option value="biweekly">Quinzenal</option>';
echo '<option value="monthly">Mensal</option>';
echo '<option value="custom">Personalizado</option>';
echo '</select></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label>Hora de Início<input type="time" name="start_time" required value="08:00"></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label>Hora de Término<input type="time" name="end_time" required value="09:00"></label>';
echo '</div>';

echo '<div class="col12" id="frequencyDetailsDiv" style="display:none">';
echo '<label>Detalhes da Frequência<textarea name="frequency_details" id="frequencyDetails" rows="3" placeholder="Ex: Segunda, Quarta e Sexta por 4 semanas (12 sessões)"></textarea></label>';
echo '<div class="helpText">Descreva os dias da semana e duração do tratamento</div>';
echo '</div>';

echo '<div class="col6">';
echo '<label>Duração (semanas)<input type="number" name="duration_weeks" id="durationWeeks" min="1" value="4" required></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label>Total de Sessões<input type="number" name="total_sessions" id="totalSessions" min="1" value="12" required></label>';
echo '<div class="helpText" id="sessionCalc">Sistema calculará automaticamente</div>';
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
echo '<label>Valor de Proposta (operadora)<input type="number" step="0.01" min="0" name="proposal_value" id="proposalValue" required value="0.00" placeholder="200.00"></label>';
echo '<div class="helpText">Valor que será oferecido à operadora por sessão</div>';
echo '</div>';

echo '<div class="col12">';
echo '<div style="padding:14px;background:hsla(var(--primary)/.08);border:1px solid hsla(var(--primary)/.2);border-radius:10px">';
echo '<div style="font-weight:700;margin-bottom:8px">💵 Resumo Financeiro</div>';
echo '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;font-size:14px">';
echo '<div><strong>Valor Total da Proposta:</strong><br><span id="totalProposal" style="font-size:18px;font-weight:900;color:hsl(var(--primary))">R$ 0,00</span></div>';
echo '<div><strong>Custo Total (profissional):</strong><br><span id="totalCost" style="font-size:18px;font-weight:900">R$ 0,00</span></div>';
echo '<div><strong>Margem Estimada:</strong><br><span id="totalMargin" style="font-size:18px;font-weight:900;color:hsl(var(--success))">R$ 0,00</span></div>';
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

// Mostrar/ocultar detalhes de frequência
echo '  const frequencySelect = document.getElementById("frequencySelect");';
echo '  const frequencyDetailsDiv = document.getElementById("frequencyDetailsDiv");';
echo '  if (frequencySelect && frequencyDetailsDiv) {';
echo '    frequencySelect.addEventListener("change", function() {';
echo '      frequencyDetailsDiv.style.display = (this.value === "custom") ? "block" : "none";';
echo '    });';
echo '  }';

// Calcular totais automaticamente
echo '  const totalSessionsInput = document.getElementById("totalSessions");';
echo '  const agreedValueInput = document.getElementById("agreedValue");';
echo '  const proposalValueInput = document.getElementById("proposalValue");';
echo '  const totalProposalSpan = document.getElementById("totalProposal");';
echo '  const totalCostSpan = document.getElementById("totalCost");';
echo '  const totalMarginSpan = document.getElementById("totalMargin");';
echo '  ';
echo '  function calculateTotals() {';
echo '    const sessions = parseFloat(totalSessionsInput.value) || 0;';
echo '    const agreed = parseFloat(agreedValueInput.value) || 0;';
echo '    const proposal = parseFloat(proposalValueInput.value) || 0;';
echo '    ';
echo '    const totalProposal = sessions * proposal;';
echo '    const totalCost = sessions * agreed;';
echo '    const totalMargin = totalProposal - totalCost;';
echo '    ';
echo '    totalProposalSpan.textContent = "R$ " + totalProposal.toFixed(2).replace(".", ",");';
echo '    totalCostSpan.textContent = "R$ " + totalCost.toFixed(2).replace(".", ",");';
echo '    totalMarginSpan.textContent = "R$ " + totalMargin.toFixed(2).replace(".", ",");';
echo '    ';
echo '    if (totalMargin < 0) {';
echo '      totalMarginSpan.style.color = "hsl(var(--destructive))";';
echo '    } else {';
echo '      totalMarginSpan.style.color = "hsl(var(--success))";';
echo '    }';
echo '  }';
echo '  ';
echo '  if (totalSessionsInput) totalSessionsInput.addEventListener("input", calculateTotals);';
echo '  if (agreedValueInput) agreedValueInput.addEventListener("input", calculateTotals);';
echo '  if (proposalValueInput) proposalValueInput.addEventListener("input", calculateTotals);';

// Calcular sessões baseado em frequência
echo '  const durationWeeksInput = document.getElementById("durationWeeks");';
echo '  const sessionCalc = document.getElementById("sessionCalc");';
echo '  ';
echo '  function calculateSessions() {';
echo '    const frequency = frequencySelect.value;';
echo '    const weeks = parseInt(durationWeeksInput.value) || 0;';
echo '    let sessions = 0;';
echo '    ';
echo '    switch(frequency) {';
echo '      case "single": sessions = 1; break;';
echo '      case "daily": sessions = weeks * 7; break;';
echo '      case "weekly": sessions = weeks; break;';
echo '      case "biweekly": sessions = Math.ceil(weeks / 2); break;';
echo '      case "monthly": sessions = Math.ceil(weeks / 4); break;';
echo '      default: sessions = parseInt(totalSessionsInput.value) || 0;';
echo '    }';
echo '    ';
echo '    if (frequency !== "custom") {';
echo '      totalSessionsInput.value = sessions;';
echo '      sessionCalc.textContent = "Calculado: " + sessions + " sessões em " + weeks + " semanas";';
echo '    } else {';
echo '      sessionCalc.textContent = "Informe manualmente o total de sessões";';
echo '    }';
echo '    ';
echo '    calculateTotals();';
echo '  }';
echo '  ';
echo '  if (frequencySelect) frequencySelect.addEventListener("change", calculateSessions);';
echo '  if (durationWeeksInput) durationWeeksInput.addEventListener("input", calculateSessions);';
echo '  ';
echo '  calculateSessions();';

echo '});';
echo '</script>';

view_footer();
