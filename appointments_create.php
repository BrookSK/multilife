<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('appointments.manage');

// Buscar especialidades
$specialtiesStmt = db()->query("SELECT id, name FROM specialties WHERE status = 'active' ORDER BY name ASC");
$specialties = $specialtiesStmt->fetchAll();

$patients = db()->query('SELECT id, full_name FROM patients WHERE deleted_at IS NULL ORDER BY full_name ASC')->fetchAll();
$professionals = db()->query(
    "SELECT u.id, u.name, u.email FROM users u INNER JOIN user_roles ur ON ur.user_id = u.id INNER JOIN roles r ON r.id = ur.role_id WHERE u.status = 'active' AND r.slug = 'profissional' ORDER BY u.name ASC"
)->fetchAll();

view_header('Novo agendamento');

echo '<div class="card">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900;margin-bottom:6px">Novo agendamento</div>';
echo '<div style="color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Criado pelo Captador após confirmação via chat.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/appointments_list.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<div style="height:14px"></div>';

echo '<form method="post" action="/appointments_create_post.php" style="display:grid;gap:12px;max-width:900px">';

echo '<div class="grid">';

echo '<div class="col6">';
echo '<label>Paciente<select name="patient_id" required>'; 
echo '<option value="">Selecione</option>';
foreach ($patients as $p) {
    echo '<option value="' . (int)$p['id'] . '">' . h((string)$p['full_name']) . ' (#' . (int)$p['id'] . ')</option>';
}
echo '</select></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label>Profissional<select name="professional_user_id" required>'; 
echo '<option value="">Selecione</option>';
foreach ($professionals as $u) {
    echo '<option value="' . (int)$u['id'] . '">' . h((string)$u['name']) . ' — ' . h((string)$u['email']) . '</option>';
}
echo '</select></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label>Especialidade<select name="specialty" required>';
echo '<option value="">Selecione...</option>';
foreach ($specialties as $spec) {
    echo '<option value="' . h((string)$spec['name']) . '">' . h((string)$spec['name']) . '</option>';
}
echo '</select></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label>Data/hora do 1º atendimento<input type="datetime-local" name="first_at" required></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label>Frequência<select name="recurrence_type">';
echo '<option value="single">single</option>';
echo '<option value="weekly">weekly</option>';
echo '<option value="monthly">monthly</option>';
echo '<option value="custom">custom</option>';
echo '</select></label>';
echo '</div>';

echo '<div class="col12">';
echo '<label>Regra de recorrência (opcional)<textarea name="recurrence_rule" rows="2" placeholder="Ex: 3x por semana por 30 dias"></textarea></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label>Valor por sessão<input type="number" step="0.01" min="0" name="value_per_session" required value="0"></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label>Vincular a card (demanda) - ID (opcional)<input type="number" name="demand_id" min="1" placeholder="Ex: 123"></label>';
echo '</div>';

echo '</div>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;margin-top:6px">';
echo '<a class="btn" href="/appointments_list.php">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '</div>';

echo '</form>';

echo '</div>';

echo '<script>';
echo 'document.addEventListener("DOMContentLoaded", function() {';
echo '  const professionalSelect = document.querySelector("select[name=\'professional_user_id\']");';
echo '  const specialtySelect = document.querySelector("select[name=\'specialty\']");';
echo '  ';
echo '  if (professionalSelect && specialtySelect) {';
echo '    professionalSelect.addEventListener("change", async function() {';
echo '      const userId = this.value;';
echo '      if (!userId) return;';
echo '      ';
echo '      try {';
echo '        const response = await fetch("/api/get_user_specialty.php?user_id=" + userId);';
echo '        const data = await response.json();';
echo '        ';
echo '        if (data.specialty) {';
echo '          // Selecionar a especialidade no dropdown';
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
echo '});';
echo '</script>';

view_footer();
