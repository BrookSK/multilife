<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$db = db();

// Buscar atendimentos com TODAS as informações
$sql = "SELECT a.id, a.first_at, a.status, a.value_per_session, a.created_at,
        p.id as patient_id, p.full_name as patient_name, p.whatsapp as patient_phone, p.email as patient_email,
        p.birth_date, p.cpf, 
        CONCAT_WS(', ', p.address_street, p.address_number, p.address_complement, p.address_neighborhood) as patient_address,
        p.address_city as patient_city, p.address_state as patient_state,
        u.id as professional_id, u.name as professional_name, u.phone as professional_phone, u.email as professional_email,
        d.id as demand_id, d.specialty, d.location_city, d.location_state,
        pa.service_type, pa.payment_value, pa.session_quantity
        FROM appointments a
        INNER JOIN patients p ON p.id = a.patient_id
        INNER JOIN users u ON u.id = a.professional_user_id
        LEFT JOIN demands d ON d.id = a.demand_id
        LEFT JOIN patient_assignments pa ON pa.patient_id = p.id AND pa.demand_id = d.id
        WHERE a.status IN ('agendado', 'pendente_formulario', 'realizado')
        AND a.first_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";

$appointments = $db->query($sql)->fetchAll();

$events = [];
foreach ($appointments as $apt) {
    $color = ['agendado'=>'#10b981','pendente_formulario'=>'#f59e0b','realizado'=>'#6366f1'][(string)$apt['status']] ?? '#6366f1';
    $events[] = [
        'id' => (int)$apt['id'],
        'title' => $apt['patient_name'] . ' - ' . $apt['professional_name'],
        'start' => $apt['first_at'],
        'backgroundColor' => $color,
        'extendedProps' => [
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
.container{padding:0;margin:0;width:100vw;height:100vh;display:flex;flex-direction:column}
.header{padding:20px 24px;background:#fff;border-bottom:1px solid #e5e7eb;flex-shrink:0}
.title{font-size:28px;font-weight:800;margin:0}
.calendar{flex:1;background:#fff;padding:20px;overflow:auto}
#calendar{height:100%}
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
</style>

<div class="container">
    <div class="header">
        <h1 class="title">Monitoramento de Atendimentos</h1>
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
            
            document.getElementById('modal').classList.add('open');
        }
    });
    calendar.render();
});

function closeModal() {
    document.getElementById('modal').classList.remove('open');
}
</script>

<?php view_footer(); ?>
