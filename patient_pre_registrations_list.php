<?php

declare(strict_types=1);

/**
 * Cadastros pendentes de PACIENTES.
 *
 * Mostra:
 *  - Pendências abertas de pré-cadastro (pending_items type=patient_pre_registration),
 *    criadas automaticamente quando um card de captação chega com um paciente que
 *    ainda não tem cadastro. Botão para pré-cadastrar rápido (mesmo endpoint do chat).
 *  - Pacientes já pré-cadastrados (admin_status='Pré-cadastro'), para completar o
 *    cadastro longo.
 */

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('patients.manage');

$db = db();
// Garantir estruturas (idempotente).
try { $db->exec("ALTER TABLE patients ADD COLUMN is_pre_registration TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
try {
    $db->exec("CREATE TABLE IF NOT EXISTS pending_items (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        type VARCHAR(60) NOT NULL,
        status ENUM('open','done','dismissed') NOT NULL DEFAULT 'open',
        title VARCHAR(200) NOT NULL,
        detail TEXT NULL,
        related_table VARCHAR(60) NULL,
        related_id BIGINT UNSIGNED NULL,
        assigned_user_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        resolved_at DATETIME NULL,
        PRIMARY KEY (id), KEY idx_pending_status (status), KEY idx_pending_type (type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {}

// Pendências abertas de pré-cadastro de paciente.
$pending = [];
try {
    $stmt = $db->query("
        SELECT pi.id, pi.title, pi.detail, pi.related_id, pi.created_at,
               d.patient_name, d.specialty, d.location_city, d.location_state,
               cl.name AS client_name, hi.name AS insurer_name
        FROM pending_items pi
        LEFT JOIN demands d ON d.id = pi.related_id AND pi.related_table = 'demands'
        LEFT JOIN clients cl ON cl.id = d.client_id
        LEFT JOIN health_insurers hi ON hi.id = d.health_insurer_id
        WHERE pi.type = 'patient_pre_registration' AND pi.status = 'open'
        ORDER BY pi.created_at DESC
        LIMIT 200
    ");
    $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[PATIENT_PENDING] ' . $e->getMessage());
    $pending = [];
}

// Pacientes já em pré-cadastro (aguardando completar o cadastro longo).
$preRegistered = [];
try {
    $stmt = $db->query("
        SELECT id, full_name, phone_primary, whatsapp, address_city, address_state, created_at
        FROM patients
        WHERE deleted_at IS NULL AND (is_pre_registration = 1 OR admin_status = 'Pré-cadastro')
        ORDER BY created_at DESC
        LIMIT 200
    ");
    $preRegistered = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $preRegistered = [];
}

view_header('Pacientes Pendentes');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Pacientes Pendentes de Cadastro</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.5">Pacientes que apareceram em captações e ainda não têm cadastro. Faça o pré-cadastro rápido; o cadastro completo pode ser feito depois.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/patients_list.php">Todos os pacientes</a>';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

// ---- Pendências (a pré-cadastrar) --------------------------------
echo '<section class="card col12">';
echo '<div style="font-size:16px;font-weight:800;margin-bottom:12px">A pré-cadastrar (' . count($pending) . ')</div>';
echo '<div style="overflow:auto"><table>';
echo '<thead><tr><th>Paciente (do card)</th><th>Cliente</th><th>Operadora</th><th>Especialidade</th><th>Local</th><th>Criado</th><th style="text-align:right">Ações</th></tr></thead><tbody>';
if (empty($pending)) {
    echo '<tr><td colspan="7" style="text-align:center;padding:24px;color:hsl(var(--muted-foreground))">Nenhuma pendência de pré-cadastro. 🎉</td></tr>';
} else {
    foreach ($pending as $p) {
        $pname = (string)($p['patient_name'] ?? '');
        if ($pname === '') {
            // fallback: extrair do título "Pré-cadastrar paciente: NOME"
            $pname = trim(str_replace('Pré-cadastrar paciente:', '', (string)$p['title']));
        }
        $local = trim((string)($p['location_city'] ?? ''));
        if (!empty($p['location_state'])) {
            $local .= ($local !== '' ? '/' : '') . (string)$p['location_state'];
        }
        echo '<tr>';
        echo '<td style="font-weight:700">' . h($pname) . '</td>';
        echo '<td>' . h((string)($p['client_name'] ?? '-')) . '</td>';
        echo '<td>' . h((string)($p['insurer_name'] ?? '-')) . '</td>';
        echo '<td>' . h((string)($p['specialty'] ?? '-')) . '</td>';
        echo '<td>' . h($local !== '' ? $local : '-') . '</td>';
        echo '<td>' . h((string)($p['created_at'] ?? '-')) . '</td>';
        echo '<td style="text-align:right;white-space:nowrap">';
        $cardId = (int)($p['related_id'] ?? 0);
        echo '<button class="btn btnPrimary" type="button" onclick="openPatientPre(' . (int)$p['id'] . ',' . json_encode($pname) . ')">Pré-cadastrar</button> ';
        if ($cardId > 0) {
            echo '<a class="btn" href="/demands_view.php?id=' . $cardId . '">Ver card</a> ';
        }
        echo '<button class="btn" type="button" onclick="dismissPending(' . (int)$p['id'] . ')">Descartar</button>';
        echo '</td>';
        echo '</tr>';
    }
}
echo '</tbody></table></div>';
echo '</section>';

// ---- Pré-cadastrados (a completar) -------------------------------
echo '<section class="card col12">';
echo '<div style="font-size:16px;font-weight:800;margin-bottom:12px">Pré-cadastrados (a completar) (' . count($preRegistered) . ')</div>';
echo '<div style="overflow:auto"><table>';
echo '<thead><tr><th>Nome</th><th>Contato</th><th>Cidade</th><th>Criado</th><th style="text-align:right">Ações</th></tr></thead><tbody>';
if (empty($preRegistered)) {
    echo '<tr><td colspan="5" style="text-align:center;padding:24px;color:hsl(var(--muted-foreground))">Nenhum paciente em pré-cadastro.</td></tr>';
} else {
    foreach ($preRegistered as $pt) {
        $contact = (string)($pt['phone_primary'] ?: $pt['whatsapp'] ?: '-');
        $city = trim((string)($pt['address_city'] ?? ''));
        if (!empty($pt['address_state'])) {
            $city .= ($city !== '' ? '/' : '') . (string)$pt['address_state'];
        }
        echo '<tr>';
        echo '<td style="font-weight:700">' . h((string)$pt['full_name']) . '</td>';
        echo '<td>' . h($contact) . '</td>';
        echo '<td>' . h($city !== '' ? $city : '-') . '</td>';
        echo '<td>' . h((string)($pt['created_at'] ?? '-')) . '</td>';
        echo '<td style="text-align:right"><a class="btn btnPrimary" href="/patients_edit.php?id=' . (int)$pt['id'] . '">Completar cadastro</a></td>';
        echo '</tr>';
    }
}
echo '</tbody></table></div>';
echo '</section>';

echo '</div>';
?>

<!-- Modal de pré-cadastro rápido de paciente -->
<div id="ppModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:20px">
  <div style="background:#fff;border-radius:12px;width:100%;max-width:460px;padding:22px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
      <h3 style="margin:0;font-size:18px">Pré-cadastrar paciente</h3>
      <button type="button" onclick="closePatientPre()" style="background:none;border:none;font-size:22px;cursor:pointer;color:#54656f">&times;</button>
    </div>
    <div id="ppError" style="display:none;background:#f8d7da;color:#721c24;padding:10px 12px;border-radius:8px;margin-bottom:12px;font-size:13px"></div>
    <div id="ppDup" style="display:none;background:#fff3cd;color:#664d03;border:1px solid #ffe69c;padding:12px;border-radius:8px;margin-bottom:12px;font-size:13px"></div>
    <input type="hidden" id="ppPendingId">
    <label style="display:block;font-weight:600;font-size:14px;margin-bottom:6px">Nome *</label>
    <input type="text" id="ppName" style="width:100%;padding:10px;border:1px solid #d1d7db;border-radius:8px;margin-bottom:12px">
    <label style="display:block;font-weight:600;font-size:14px;margin-bottom:6px">Telefone / WhatsApp</label>
    <input type="text" id="ppPhone" placeholder="Ex: 5511999999999" style="width:100%;padding:10px;border:1px solid #d1d7db;border-radius:8px;margin-bottom:12px">
    <div style="display:flex;gap:10px;margin-bottom:16px">
      <div style="flex:2"><label style="display:block;font-weight:600;font-size:14px;margin-bottom:6px">Cidade</label>
        <input type="text" id="ppCity" style="width:100%;padding:10px;border:1px solid #d1d7db;border-radius:8px"></div>
      <div style="flex:1"><label style="display:block;font-weight:600;font-size:14px;margin-bottom:6px">UF</label>
        <input type="text" id="ppState" maxlength="2" style="width:100%;padding:10px;border:1px solid #d1d7db;border-radius:8px;text-transform:uppercase"></div>
    </div>
    <div style="display:flex;gap:10px">
      <button type="button" onclick="closePatientPre()" class="btn" style="flex:1">Cancelar</button>
      <button type="button" id="ppSubmit" onclick="submitPatientPre()" class="btn btnPrimary" style="flex:1">Salvar</button>
    </div>
  </div>
</div>
<script>
function openPatientPre(pendingId, name){
  document.getElementById('ppPendingId').value = pendingId || '';
  document.getElementById('ppName').value = name || '';
  document.getElementById('ppPhone').value = '';
  document.getElementById('ppCity').value = '';
  document.getElementById('ppState').value = '';
  document.getElementById('ppError').style.display = 'none';
  document.getElementById('ppDup').style.display = 'none';
  document.getElementById('ppModal').style.display = 'flex';
}
function closePatientPre(){ document.getElementById('ppModal').style.display = 'none'; }
function dismissPending(id){
  if(!confirm('Descartar esta pendência?')) return;
  fetch('/pending_items_set_status_post.php?id=' + encodeURIComponent(id) + '&status=dismissed')
    .then(function(){ location.reload(); })
    .catch(function(){ alert('Erro ao descartar.'); });
}
function submitPatientPre(force){
  var name = document.getElementById('ppName').value || '';
  var err = document.getElementById('ppError');
  var dup = document.getElementById('ppDup');
  if(!name.trim()){ err.textContent = 'Informe o nome.'; err.style.display='block'; return; }
  err.style.display='none';
  if(!force){ dup.style.display='none'; dup.innerHTML=''; }
  var btn = document.getElementById('ppSubmit');
  btn.disabled = true; btn.textContent = 'Salvando...';
  var fd = new FormData();
  fd.append('name', name);
  fd.append('phone', document.getElementById('ppPhone').value || '');
  fd.append('city', document.getElementById('ppCity').value || '');
  fd.append('state', document.getElementById('ppState').value || '');
  fd.append('pending_item_id', document.getElementById('ppPendingId').value || '');
  if(force){ fd.append('force','1'); }
  fetch('/patient_quick_create_post.php', {method:'POST', body:fd})
    .then(function(r){ return r.json(); })
    .then(function(d){
      btn.disabled = false; btn.textContent = 'Salvar';
      if(d && d.ok){ alert('Paciente pré-cadastrado: ' + (d.name||name)); location.reload(); return; }
      if(d && d.needs_confirmation && Array.isArray(d.duplicates) && d.duplicates.length){
        var esc = function(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]);}); };
        var html = '<div style="font-weight:700;margin-bottom:6px">⚠ Já existe paciente parecido</div>';
        d.duplicates.forEach(function(x){ html += '<div style="padding:6px 0;border-top:1px solid #ffe69c">' + esc(x.name) + (x.phone?(' — '+esc(x.phone)):'') + (x.city?(' · '+esc(x.city)):'') + '</div>'; });
        html += '<button type="button" onclick="submitPatientPre(true)" style="margin-top:10px;width:100%;padding:9px;background:#e67e22;color:#fff;border:none;border-radius:8px;font-weight:600;cursor:pointer">Cadastrar mesmo assim</button>';
        dup.innerHTML = html; dup.style.display='block';
        return;
      }
      err.textContent = (d && d.message) ? d.message : 'Erro ao pré-cadastrar.'; err.style.display='block';
    })
    .catch(function(){ btn.disabled=false; btn.textContent='Salvar'; err.textContent='Erro de conexão.'; err.style.display='block'; });
}
</script>
<?php
view_footer();
