<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$db = db();

// Buscar sessões de atendimentos aprovados (usa session_date + start_time da proposta)
$sql = "SELECT bdr.id, 
        CONCAT(
            COALESCE(bdr.session_date, DATE_ADD(DATE(pa.admitted_at), INTERVAL (bdr.session_number - 1) WEEK)),
            ' ',
            COALESCE(
                (SELECT ar.start_time FROM authorization_requests ar WHERE ar.demand_id = pa.demand_id AND ar.start_time IS NOT NULL ORDER BY ar.id DESC LIMIT 1),
                TIME(pa.admitted_at),
                '08:00:00'
            )
        ) as first_at,
        bdr.session_number, bdr.status as session_status,
        pa.id as assignment_id, pa.status as assignment_status,
        COALESCE(pa.agreed_value, pa.payment_value) as value_per_session, pa.created_at,
        p.id as patient_id, p.full_name as patient_name, p.whatsapp as patient_phone, p.email as patient_email,
        p.birth_date, p.cpf, 
        CONCAT_WS(', ', p.address_street, p.address_number, p.address_complement, p.address_neighborhood) as patient_address,
        p.address_city as patient_city, p.address_state as patient_state,
        u.id as professional_id, u.name as professional_name, u.phone as professional_phone, u.email as professional_email,
        d.id as demand_id, d.specialty, d.location_city, d.location_state,
        pa.service_type, pa.payment_value, pa.session_quantity
        FROM billing_document_requirements bdr
        INNER JOIN patient_assignments pa ON pa.id = bdr.assignment_id
        INNER JOIN patients p ON p.id = bdr.patient_id
        INNER JOIN users u ON u.id = bdr.professional_user_id
        LEFT JOIN demands d ON d.id = pa.demand_id
        WHERE pa.status IN ('admitted', 'awaiting_documents', 'awaiting_financial_approval', 'completed')
        AND pa.admitted_at IS NOT NULL
        ORDER BY first_at ASC";

$appointments = $db->query($sql)->fetchAll();

// Carregar motivos de encerramento (item 5/11)
$endReasons = [];
try {
    $endReasons = $db->query("SELECT id, name FROM treatment_end_reasons WHERE is_active = 1 ORDER BY is_system DESC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $endReasons = [];
}

$events = [];
foreach ($appointments as $apt) {
    // Cor baseada no status da sessão
    $color = match((string)$apt['session_status']) {
        'pending' => '#f59e0b',      // Laranja - aguardando
        'uploaded' => '#3b82f6',     // Azul - documento enviado
        'approved' => '#10b981',     // Verde - aprovado
        'rejected' => '#dc2626',     // Vermelho - rejeitado
        default => '#6366f1'         // Roxo - padrão
    };
    
    $events[] = [
        'id' => (int)$apt['id'],
        'title' => '#' . ($apt['demand_id'] ?? '') . ' ' . $apt['patient_name'] . ' - ' . $apt['professional_name'] . ' (Sessão ' . $apt['session_number'] . ')',
        'start' => $apt['first_at'],
        'backgroundColor' => $color,
        'extendedProps' => [
            'assignment_id' => (int)$apt['assignment_id'],
            'patient_id' => (int)$apt['patient_id'],
            'patient_name' => $apt['patient_name'],
            'patient_phone' => $apt['patient_phone'] ?? '',
            'patient_email' => $apt['patient_email'] ?? '',
            'patient_birth_date' => $apt['birth_date'] ?? '',
            'patient_cpf' => $apt['cpf'] ?? '',
            'patient_address' => $apt['patient_address'] ?? '',
            'patient_city' => $apt['patient_city'] ?? '',
            'patient_state' => $apt['patient_state'] ?? '',
            'professional_id' => (int)$apt['professional_id'],
            'professional_name' => $apt['professional_name'],
            'professional_phone' => $apt['professional_phone'] ?? '',
            'professional_email' => $apt['professional_email'] ?? '',
            'status' => $apt['status'],
            'value_per_session' => (float)($apt['value_per_session'] ?? 0),
            'session_quantity' => (int)($apt['session_quantity'] ?? 0),
            'payment_value' => (float)($apt['payment_value'] ?? 0),
            'specialty' => $apt['specialty'] ?? '',
            'service_type' => $apt['service_type'] ?? '',
            'location_city' => $apt['location_city'] ?? '',
            'location_state' => $apt['location_state'] ?? '',
            'created_at' => $apt['created_at'] ?? ''
        ]
    ];
}

view_header('Monitoramento de Atendimentos');
?>

<link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.css" rel="stylesheet">
<style>
body{margin:0;padding:0;overflow:hidden}
.grid{display:none !important}
.container{position:fixed;top:70px;left:270px;right:20px;bottom:20px;display:flex;flex-direction:column;background:#fff;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1)}
.header{padding:16px 20px;background:#fff;border-bottom:1px solid #e5e7eb;flex-shrink:0;display:flex;align-items:flex-start;justify-content:space-between;gap:16px;border-radius:8px 8px 0 0}
.title{font-size:20px;font-weight:800;margin:0}
.legend{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
.legendItem{display:flex;align-items:center;gap:5px;font-size:12px;font-weight:600}
.legendColor{width:14px;height:14px;border-radius:3px}
.calendar{flex:1;background:#fff;padding:16px;overflow:auto;min-height:0}
#calendar{min-height:800px;width:100%}
.fc .fc-daygrid-day{min-height:120px !important}
.fc .fc-daygrid-day-frame{min-height:120px !important}
.fc .fc-scrollgrid-sync-table{height:100% !important}
.fc-event-title{white-space:normal !important;overflow:visible !important;text-overflow:clip !important;line-height:1.3 !important;padding:4px 6px !important;display:block !important}
.fc-event{white-space:normal !important;overflow:visible !important;margin-bottom:3px !important;padding:6px 8px !important;border-radius:6px !important;box-shadow:0 1px 3px rgba(0,0,0,0.15) !important;border:none !important;font-size:11px !important;font-weight:600 !important}
.fc-daygrid-more-link{background:hsl(var(--success)) !important;color:white !important;padding:6px 8px !important;border-radius:4px !important;font-size:10px !important;font-weight:700 !important;text-decoration:none !important;display:block !important;text-align:center !important;margin-top:2px !important;box-shadow:0 1px 2px rgba(0,0,0,0.1) !important;width:100% !important}
.fc-daygrid-more-link:hover{background:hsl(var(--success)/.9) !important;transform:scale(1.01) !important}
.fc-popover{z-index:10000 !important;box-shadow:0 4px 20px rgba(0,0,0,0.3) !important;border-radius:12px !important;background:white !important;border:3px solid hsl(var(--success)) !important;overflow:hidden !important}
.fc-popover::before{content:'';position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.4);backdrop-filter:blur(4px);z-index:-1}
.fc-popover-header{background:hsl(var(--success)) !important;color:white !important;padding:12px 16px !important;font-weight:700 !important}
.fc-popover-body{background:white !important;padding:12px !important}
.modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center}
.modal.open{display:flex}
.modalContent{background:#fff;border-radius:16px;padding:32px;max-width:900px;width:90%;max-height:90vh;overflow-y:auto}
.modalHeader{display:flex;justify-content:space-between;margin-bottom:24px}
.modalTitle{font-size:24px;font-weight:800;margin:0}
.close{background:none;border:none;font-size:24px;cursor:pointer}
.section{margin-bottom:20px}
.sectionTitle{font-size:14px;font-weight:700;color:#6b7280;margin:0 0 12px 0}
.info{display:flex;flex-direction:column;gap:8px}
.row{display:flex;gap:12px}
.label{font-weight:600;min-width:120px}
.value{color:#6b7280}
.actions{display:flex;gap:12px;margin-top:24px;padding-top:24px;border-top:1px solid #e5e7eb}
.btn{padding:10px 20px;border-radius:8px;font-weight:600;border:none;cursor:pointer;text-decoration:none;background:#14b8a6;color:#fff}
.btn:hover{background:#0d9488;text-decoration:none}
.fc .fc-button-primary{background:hsl(var(--primary)) !important;border-color:hsl(var(--primary)) !important}
.fc .fc-button-primary:hover{background:hsl(var(--primary-dark)) !important;border-color:hsl(var(--primary-dark)) !important}
.fc .fc-button-primary:not(:disabled):active,.fc .fc-button-primary:not(:disabled).fc-button-active{background:hsl(var(--primary-darker)) !important;border-color:hsl(var(--primary-darker)) !important}
</style>

<div class="container">
    <div class="header">
        <div style="flex:1">
            <h1 class="title" style="margin:0">Monitoramento de Atendimentos</h1>
            <div style="margin-top:6px;color:#6b7280;font-size:14px;line-height:1.6">Visualize e acompanhe todos os atendimentos em calendário</div>
        </div>
        <div class="legend">
            <div class="legendItem">
                <div class="legendColor" style="background:#f59e0b"></div>
                <span>Pendente</span>
            </div>
            <div class="legendItem">
                <div class="legendColor" style="background:#3b82f6"></div>
                <span>Documento Enviado</span>
            </div>
            <div class="legendItem">
                <div class="legendColor" style="background:#10b981"></div>
                <span>Aprovado</span>
            </div>
            <div class="legendItem">
                <div class="legendColor" style="background:#dc2626"></div>
                <span>Rejeitado</span>
            </div>
        </div>
    </div>
    <div class="calendar"><div id="calendar"></div></div>
</div>

<div id="modal" class="modal" onclick="if(event.target===this) closeModal()">
    <div class="modalContent">
        <div class="modalHeader">
            <h2 class="modalTitle" id="modalTitle">📋 Detalhes do Atendimento</h2>
            <button class="close" onclick="closeModal()">×</button>
        </div>
        
        <div class="section">
            <h3 class="sectionTitle">📊 INFORMAÇÕES GERAIS</h3>
            <div class="info">
                <div class="row"><span class="label">ID do Atendimento:</span><span class="value" id="aptId">-</span></div>
                <div class="row"><span class="label">Status:</span><span class="value" id="aptStatus">-</span></div>
                <div class="row"><span class="label">Data/Hora:</span><span class="value" id="aptDate">-</span></div>
                <div class="row"><span class="label">Especialidade:</span><span class="value" id="aptSpecialty">-</span></div>
                <div class="row"><span class="label">Tipo de Serviço:</span><span class="value" id="aptServiceType">-</span></div>
                <div class="row"><span class="label">Localização:</span><span class="value" id="aptLocation">-</span></div>
            </div>
        </div>
        
        <div class="section">
            <h3 class="sectionTitle">👤 PACIENTE</h3>
            <div class="info">
                <div class="row"><span class="label">Nome Completo:</span><span class="value" id="patientName">-</span></div>
                <div class="row"><span class="label">CPF:</span><span class="value" id="patientCpf">-</span></div>
                <div class="row"><span class="label">Data de Nascimento:</span><span class="value" id="patientBirth">-</span></div>
                <div class="row"><span class="label">WhatsApp:</span><span class="value" id="patientPhone">-</span></div>
                <div class="row"><span class="label">E-mail:</span><span class="value" id="patientEmail">-</span></div>
                <div class="row"><span class="label">Endereço:</span><span class="value" id="patientAddress">-</span></div>
                <div class="row"><span class="label">Cidade/Estado:</span><span class="value" id="patientCity">-</span></div>
            </div>
        </div>
        
        <div class="section">
            <h3 class="sectionTitle">👨‍⚕️ PROFISSIONAL</h3>
            <div class="info">
                <div class="row"><span class="label">Nome:</span><span class="value" id="profName">-</span></div>
                <div class="row"><span class="label">WhatsApp:</span><span class="value" id="profPhone">-</span></div>
                <div class="row"><span class="label">E-mail:</span><span class="value" id="profEmail">-</span></div>
            </div>
        </div>
        
        <div class="section">
            <h3 class="sectionTitle">💰 VALORES E SESSÕES</h3>
            <div class="info">
                <div class="row"><span class="label">Valor por Sessão:</span><span class="value" id="aptValueSession">-</span></div>
                <div class="row"><span class="label">Quantidade de Sessões:</span><span class="value" id="aptSessionQty">-</span></div>
                <div class="row"><span class="label">Valor Total:</span><span class="value" id="aptValueTotal">-</span></div>
                <div class="row"><span class="label">Data de Início:</span><span class="value" id="aptStartDate">-</span></div>
            </div>
        </div>
        
        <div class="actions" id="actions"></div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/locales/pt-br.global.min.js"></script>
<script>
const events = <?php echo json_encode($events); ?>;
document.addEventListener('DOMContentLoaded', function() {
    const calendar = new FullCalendar.Calendar(document.getElementById('calendar'), {
        initialView: 'dayGridMonth',
        locale: 'pt-br',
        headerToolbar: {left:'prev,next today',center:'title',right:'dayGridMonth,timeGridWeek'},
        height: '100%',
        contentHeight: 'auto',
        expandRows: true,
        dayMaxEvents: 5,
        moreLinkText: function(num) { return '+' + num + ' - Ver Todos os Eventos'; },
        events: events,
        eventClick: function(info) {
            const p = info.event.extendedProps;
            
            // Informações Gerais
            document.getElementById('aptId').textContent = '#' + info.event.id;
            const statusMap = {
                'agendado': '🟢 Agendado',
                'pendente_formulario': '🟡 Pendente Formulário',
                'realizado': '🔵 Realizado'
            };
            document.getElementById('aptStatus').textContent = statusMap[p.status] || p.status;
            document.getElementById('aptDate').textContent = new Date(info.event.start).toLocaleString('pt-BR');
            document.getElementById('aptSpecialty').textContent = p.specialty || 'Não informado';
            document.getElementById('aptServiceType').textContent = p.service_type || 'Não informado';
            document.getElementById('aptLocation').textContent = (p.location_city && p.location_state) 
                ? p.location_city + '/' + p.location_state 
                : 'Não informado';
            
            // Paciente
            document.getElementById('patientName').textContent = p.patient_name;
            document.getElementById('patientCpf').textContent = p.patient_cpf || 'Não informado';
            document.getElementById('patientBirth').textContent = p.patient_birth_date 
                ? new Date(p.patient_birth_date).toLocaleDateString('pt-BR') 
                : 'Não informado';
            document.getElementById('patientPhone').textContent = p.patient_phone || 'Não informado';
            document.getElementById('patientEmail').textContent = p.patient_email || 'Não informado';
            document.getElementById('patientAddress').textContent = p.patient_address || 'Não informado';
            document.getElementById('patientCity').textContent = (p.patient_city && p.patient_state)
                ? p.patient_city + '/' + p.patient_state
                : 'Não informado';
            
            // Profissional
            document.getElementById('profName').textContent = p.professional_name;
            document.getElementById('profPhone').textContent = p.professional_phone || 'Não informado';
            document.getElementById('profEmail').textContent = p.professional_email || 'Não informado';
            
            // Valores
            document.getElementById('aptValueSession').textContent = p.value_per_session 
                ? 'R$ ' + p.value_per_session.toFixed(2).replace('.', ',')
                : 'Não informado';
            document.getElementById('aptSessionQty').textContent = p.session_quantity || 'Não informado';
            const totalValue = p.value_per_session && p.session_quantity 
                ? p.value_per_session * p.session_quantity 
                : (p.payment_value || 0);
            document.getElementById('aptValueTotal').textContent = totalValue 
                ? 'R$ ' + totalValue.toFixed(2).replace('.', ',')
                : 'Não informado';
            document.getElementById('aptStartDate').textContent = new Date(info.event.start).toLocaleDateString('pt-BR');
            
            // Ações
            const actions = document.getElementById('actions');
            actions.innerHTML = '';
            
            if(p.professional_phone) {
                const btn = document.createElement('a');
                btn.href = '/chat_web.php?phone=' + encodeURIComponent(p.professional_phone);
                btn.className = 'btn';
                btn.style.background = '#25d366';
                btn.style.fontSize = '16px';
                btn.style.padding = '14px 28px';
                btn.innerHTML = '💬 Contatar Profissional (WhatsApp)';
                actions.appendChild(btn);
            }
            
            if(p.patient_phone) {
                const btn = document.createElement('a');
                btn.href = '/chat_web.php?phone=' + encodeURIComponent(p.patient_phone);
                btn.className = 'btn';
                btn.innerHTML = '💬 Chat Paciente';
                actions.appendChild(btn);
            }
            
            // Botão Desmame (alteração de frequência)
            const btnDesmame = document.createElement('a');
            btnDesmame.href = '/monitoramento_desmame.php?assignment_id=' + (p.assignment_id || info.event.id);
            btnDesmame.className = 'btn';
            btnDesmame.style.background = '#f59e0b';
            btnDesmame.style.color = '#fff';
            btnDesmame.innerHTML = '📉 Desmame (Alterar Frequência)';
            actions.appendChild(btnDesmame);
            
            // Botão Substituição de Profissional
            const btnSubst = document.createElement('a');
            btnSubst.href = '/monitoramento_substituicao.php?assignment_id=' + (p.assignment_id || info.event.id);
            btnSubst.className = 'btn';
            btnSubst.style.background = '#8b5cf6';
            btnSubst.style.color = '#fff';
            btnSubst.innerHTML = '🔄 Substituir Profissional';
            actions.appendChild(btnSubst);
            
            // Botão Finalizar Atendimento (itens 5 e 11)
            const btnFinalize = document.createElement('button');
            btnFinalize.className = 'btn';
            btnFinalize.style.background = '#dc2626';
            btnFinalize.style.color = '#fff';
            btnFinalize.innerHTML = '🏁 Finalizar Atendimento';
            btnFinalize.onclick = function() {
                openFinalizeModal(p.assignment_id || info.event.id, p.patient_name, p.professional_name);
            };
            actions.appendChild(btnFinalize);
            
            // Botão Confirmar Profissional (lembrete do atendimento do dia)
            const btnConfirm = document.createElement('button');
            btnConfirm.className = 'btn';
            btnConfirm.style.background = '#0284c7';
            btnConfirm.style.color = '#fff';
            btnConfirm.innerHTML = '✅ Confirmar Profissional';
            btnConfirm.onclick = function() {
                if (!confirm('Enviar lembrete de confirmação para o profissional ' + p.professional_name + '?')) return;
                btnConfirm.disabled = true;
                btnConfirm.innerHTML = '⏳ Enviando...';
                fetch('/monitoramento_confirm_professional_post.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        session_id: info.event.id,
                        assignment_id: p.assignment_id || info.event.id,
                        professional_id: p.professional_id,
                        patient_name: p.patient_name,
                        specialty: p.specialty,
                        session_date: info.event.start ? info.event.start.toISOString().split('T')[0] : ''
                    })
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        btnConfirm.innerHTML = '✅ Confirmação Enviada!';
                        btnConfirm.style.background = '#059669';
                    } else {
                        alert('Erro: ' + (data.error || 'Falha ao enviar'));
                        btnConfirm.disabled = false;
                        btnConfirm.innerHTML = '✅ Confirmar Profissional';
                    }
                })
                .catch(err => {
                    alert('Erro de conexão');
                    btnConfirm.disabled = false;
                    btnConfirm.innerHTML = '✅ Confirmar Profissional';
                });
            };
            actions.appendChild(btnConfirm);
            
            document.getElementById('modal').classList.add('open');
        }
    });
    calendar.render();
});

function closeModal() {
    document.getElementById('modal').classList.remove('open');
}

// ============================================
// FINALIZAR ATENDIMENTO (itens 5 e 11)
// ============================================
var _finalizeAssignmentId = null;
function openFinalizeModal(assignmentId, patientName, professionalName) {
    _finalizeAssignmentId = assignmentId;
    document.getElementById('finalizePatient').textContent = patientName || '-';
    document.getElementById('finalizeProfessional').textContent = professionalName || '-';
    // Preencher data/hora atuais
    var now = new Date();
    var pad = function(n){return (n<10?'0':'')+n;};
    document.getElementById('finalizeDate').value = now.getFullYear()+'-'+pad(now.getMonth()+1)+'-'+pad(now.getDate());
    document.getElementById('finalizeTime').value = pad(now.getHours())+':'+pad(now.getMinutes());
    document.getElementById('finalizeNotes').value = '';
    document.getElementById('finalizeModal').classList.add('open');
}
function closeFinalizeModal() {
    document.getElementById('finalizeModal').classList.remove('open');
    _finalizeAssignmentId = null;
}
function submitFinalize() {
    if (!_finalizeAssignmentId) return;
    var reasonId = document.getElementById('finalizeReason').value;
    if (!reasonId) { alert('Selecione o motivo do encerramento.'); return; }
    var fd = new FormData();
    fd.append('assignment_id', _finalizeAssignmentId);
    fd.append('end_reason_id', reasonId);
    fd.append('ended_date', document.getElementById('finalizeDate').value);
    fd.append('ended_time', document.getElementById('finalizeTime').value);
    fd.append('end_notes', document.getElementById('finalizeNotes').value);
    var btn = document.getElementById('finalizeSubmitBtn');
    btn.disabled = true; btn.innerHTML = '⏳ Finalizando...';
    fetch('/assignment_finalize.php', { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(data){
            if (data.success) {
                alert('✅ Atendimento finalizado com sucesso!');
                window.location.reload();
            } else {
                alert('❌ Erro: ' + (data.error || 'desconhecido'));
                btn.disabled = false; btn.innerHTML = 'Finalizar';
            }
        })
        .catch(function(){
            alert('❌ Erro ao finalizar atendimento.');
            btn.disabled = false; btn.innerHTML = 'Finalizar';
        });
}
</script>

<!-- Modal de Finalização de Atendimento -->
<div id="finalizeModal" class="modal" onclick="if(event.target===this) closeFinalizeModal()">
    <div class="modalContent" style="max-width:520px">
        <div class="modalHeader">
            <h2 class="modalTitle">🏁 Finalizar Atendimento</h2>
            <button class="close" onclick="closeFinalizeModal()">×</button>
        </div>
        <div class="section">
            <div class="info">
                <div class="row"><span class="label">Paciente:</span><span class="value" id="finalizePatient">-</span></div>
                <div class="row"><span class="label">Profissional:</span><span class="value" id="finalizeProfessional">-</span></div>
            </div>
        </div>
        <div class="section">
            <label style="display:block;font-weight:600;margin-bottom:6px">Motivo do encerramento *</label>
            <select id="finalizeReason" style="width:100%;padding:10px;border:1px solid #d1d7db;border-radius:8px">
                <option value="">Selecione...</option>
                <?php foreach ($endReasons as $er): ?>
                <option value="<?php echo (int)$er['id']; ?>"><?php echo h($er['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="section" style="display:flex;gap:12px">
            <div style="flex:1">
                <label style="display:block;font-weight:600;margin-bottom:6px">Data</label>
                <input type="date" id="finalizeDate" style="width:100%;padding:10px;border:1px solid #d1d7db;border-radius:8px">
            </div>
            <div style="flex:1">
                <label style="display:block;font-weight:600;margin-bottom:6px">Hora</label>
                <input type="time" id="finalizeTime" style="width:100%;padding:10px;border:1px solid #d1d7db;border-radius:8px">
            </div>
        </div>
        <div class="section">
            <label style="display:block;font-weight:600;margin-bottom:6px">Observações</label>
            <textarea id="finalizeNotes" rows="3" style="width:100%;padding:10px;border:1px solid #d1d7db;border-radius:8px;resize:vertical" placeholder="Observações do encerramento (opcional)"></textarea>
        </div>
        <div class="actions">
            <button class="btn" style="background:#e5e7eb;color:#374151" onclick="closeFinalizeModal()">Cancelar</button>
            <button class="btn" style="background:#dc2626" id="finalizeSubmitBtn" onclick="submitFinalize()">Finalizar</button>
        </div>
    </div>
</div>

<?php view_footer(); ?>
