// Funções do Chat Web - Arquivo JavaScript externo

// Captura de erros
window.onerror = function(msg, url, line, col, error) {
  var errorData = {
    message: msg,
    url: url,
    line: line,
    col: col,
    stack: error ? error.stack : null
  };
  fetch("/chat_log_error.php", {
    method: "POST",
    headers: {"Content-Type": "application/json"},
    body: JSON.stringify(errorData)
  }).catch(function(){});
  console.error("JS Error:", msg, "at", line + ":" + col);
  return false;
};

// Funções de modal
function openNewChatModal() {
  const modal = document.getElementById("newChatModal");
  if (modal) {
    modal.style.display = "flex";
  }
}

function closeNewChatModal() {
  document.getElementById("newChatModal").style.display = "none";
}

var groupModalInitialized = false;

function openCreateGroupModal() {
  document.getElementById("createGroupModal").style.display = "flex";
  if(!groupModalInitialized) {
    initGroupModalCities();
    groupModalInitialized = true;
  }
}

function closeCreateGroupModal() {
  document.getElementById("createGroupModal").style.display = "none";
}

function closeAssignmentModal() {
  document.getElementById("assignmentModal").style.display = "none";
  document.getElementById("assignmentForm").reset();
}

function closeInfoPanel() {
  window.location.href = "/chat_web.php";
}

// Função de atribuição de paciente
async function openAssignmentModal() {
  const demandSelect = document.getElementById("demandSelect");
  if(!demandSelect || !demandSelect.value) {
    alert("Por favor, selecione um card de captação primeiro.");
    return;
  }
  const selectedOption = demandSelect.options[demandSelect.selectedIndex];
  const demandId = demandSelect.value;
  const demandText = selectedOption.text;
  const professionalUserId = selectedOption.getAttribute("data-user-id");
  
  document.getElementById("professionalName").textContent = window.chatName || window.chatId || "";
  document.getElementById("demandInfo").textContent = demandText;
  document.getElementById("assignmentModal").style.display = "flex";
  
  if (professionalUserId) {
    try {
      const response = await fetch("/api/get_user_specialty.php?user_id=" + professionalUserId);
      const data = await response.json();
      if (data.specialty_id) {
        const specialtySelect = document.getElementById("specialty");
        specialtySelect.value = data.specialty_id;
        // Carregar serviços da especialidade automaticamente
        loadSpecialtyServices();
      }
    } catch (err) {
      console.error("Erro ao buscar especialidade:", err);
    }
  }
}

function handlePatientSelection() {
  const patientSelect = document.getElementById("patientId");
  if (patientSelect && patientSelect.value === "new") {
    if (confirm("Você será redirecionado para o formulário de cadastro de paciente. Deseja continuar?")) {
      const chatId = window.chatId || "";
      window.location.href = "/patients_create.php?from_chat=1&from_assignment_modal=1&chat_id=" + encodeURIComponent(chatId);
    } else {
      patientSelect.value = "";
    }
  }
}

// Carregar serviços da especialidade selecionada
async function loadSpecialtyServices() {
  const specialtySelect = document.getElementById("specialty");
  const serviceSelect = document.getElementById("serviceType");
  const serviceMinValue = document.getElementById("serviceMinValue");
  
  if (!specialtySelect || !serviceSelect) return;
  
  const specialtyId = specialtySelect.value;
  
  // Limpar select de serviços
  serviceSelect.innerHTML = '<option value="">Carregando...</option>';
  serviceMinValue.value = "0";
  
  if (!specialtyId) {
    serviceSelect.innerHTML = '<option value="">Selecione primeiro a especialidade...</option>';
    return;
  }
  
  try {
    const response = await fetch("/api/get_specialty_services.php?specialty_id=" + specialtyId);
    const data = await response.json();
    
    if (data.error) {
      serviceSelect.innerHTML = '<option value="">Erro ao carregar serviços</option>';
      return;
    }
    
    if (!data.services || data.services.length === 0) {
      serviceSelect.innerHTML = '<option value="">Nenhum serviço cadastrado para esta especialidade</option>';
      return;
    }
    
    serviceSelect.innerHTML = '<option value="">Selecione o tipo de serviço...</option>';
    data.services.forEach(function(service) {
      const option = document.createElement("option");
      option.value = service.id;
      option.textContent = service.service_name + (service.description ? " - " + service.description : "");
      option.setAttribute("data-min-value", service.base_value);
      serviceSelect.appendChild(option);
    });
    
  } catch (err) {
    console.error("Erro ao carregar serviços:", err);
    serviceSelect.innerHTML = '<option value="">Erro ao carregar serviços</option>';
  }
}

// Atualizar valor mínimo quando serviço é selecionado
function updateMinimumValue() {
  const serviceSelect = document.getElementById("serviceType");
  const serviceMinValue = document.getElementById("serviceMinValue");
  const agreedValueInput = document.getElementById("agreedValue");
  const authorizedValueInput = document.getElementById("authorizedValue");
  
  if (!serviceSelect || !serviceMinValue) return;
  
  const selectedOption = serviceSelect.options[serviceSelect.selectedIndex];
  const minValue = parseFloat(selectedOption.getAttribute("data-min-value") || "0");
  
  serviceMinValue.value = minValue.toString();
  
  // Atualizar atributo min dos inputs
  if (agreedValueInput) {
    agreedValueInput.setAttribute("min", minValue.toString());
    agreedValueInput.setAttribute("placeholder", "Mínimo: R$ " + minValue.toFixed(2));
  }
  if (authorizedValueInput) {
    authorizedValueInput.setAttribute("min", minValue.toString());
    authorizedValueInput.setAttribute("placeholder", "Mínimo: R$ " + minValue.toFixed(2));
  }
}

// Função de filtro de grupos
function loadGroupsByFilter() {
  const specialty = document.getElementById("groupSpecialty").value;
  const region = document.getElementById("groupRegion").value;
  const select = document.getElementById("selectedGroup");
  
  select.innerHTML = "<option value=''>Carregando...</option>";
  
  fetch("/chat_get_filtered_groups.php?specialty=" + encodeURIComponent(specialty) + "&region=" + encodeURIComponent(region))
    .then(r => r.json())
    .then(data => {
      if(data.success && data.groups) {
        select.innerHTML = "<option value=''>Selecione um grupo...</option>";
        data.groups.forEach(group => {
          const option = document.createElement("option");
          option.value = group.group_jid;
          option.textContent = group.group_name + (group.specialty ? " (" + group.specialty + ")" : "");
          select.appendChild(option);
        });
        if(data.groups.length === 0) {
          select.innerHTML = "<option value=''>Nenhum grupo encontrado</option>";
        }
      } else {
        select.innerHTML = "<option value=''>Erro ao carregar grupos</option>";
      }
    })
    .catch(e => {
      select.innerHTML = "<option value=''>Erro ao carregar grupos</option>";
      console.error("Erro:", e);
    });
}

// Função de envio de convite para grupo
function sendGroupInvite() {
  const chatId = window.chatId || "";
  const groupJid = document.getElementById("selectedGroup").value;
  const welcomeMessage = document.getElementById("welcomeMessage").value;
  
  if(!groupJid) {
    alert("Por favor, selecione um grupo");
    return;
  }
  
  if(confirm("Deseja enviar o convite para este grupo?")) {
    const btn = event.target;
    btn.disabled = true;
    btn.textContent = "Enviando...";
    
    fetch("/chat_send_group_invite.php", {
      method: "POST",
      headers: {"Content-Type": "application/json"},
      body: JSON.stringify({
        chat_id: chatId,
        group_jid: groupJid,
        welcome_message: welcomeMessage
      })
    })
    .then(r => r.json())
    .then(data => {
      if(data.success) {
        alert("Convite enviado com sucesso!");
        document.getElementById("selectedGroup").value = "";
      } else {
        alert("Erro ao enviar convite: " + (data.error || "Erro desconhecido"));
      }
    })
    .catch(e => {
      alert("Erro ao enviar convite: " + e.message);
    })
    .finally(() => {
      btn.disabled = false;
      btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;vertical-align:middle;margin-right:6px"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>Enviar Convite';
    });
  }
}

// Outras funções auxiliares
function syncEvolution() {
  const btn = document.getElementById("syncBtn");
  const icon = document.getElementById("syncIcon");
  btn.disabled = true;
  icon.classList.add("rotating");
  fetch("/chat_sync_evolution.php")
    .then(r => r.json())
    .then(data => {
      if(data.success) {
        alert("Sincronização concluída!");
        location.reload();
      } else {
        alert("Erro na sincronização: " + (data.error || "Erro desconhecido"));
      }
    })
    .catch(err => {
      alert("Erro ao sincronizar: " + err.message);
    })
    .finally(() => {
      btn.disabled = false;
      icon.classList.remove("rotating");
    });
}

function syncWhatsApp() {
  var msg = "Sincronizar todas as conversas e grupos do WhatsApp?";
  if(confirm(msg)) {
    window.location.href = "/chat_sync_whatsapp.php";
  }
}

function toggleActionsMenu(e) {
  if(e) {
    e.preventDefault();
    e.stopPropagation();
  }
  const menu = document.getElementById("actionsMenu");
  if(menu) {
    menu.classList.toggle("show");
  }
}

function toggleChatMenu(e) {
  e.stopPropagation();
  alert("Menu do chat em desenvolvimento");
}

function searchInChat() {
  alert("Busca no chat em desenvolvimento");
}

function dispararFluxo() {
  alert("Funcionalidade em desenvolvimento");
}

function dispararRemarketing() {
  alert("Funcionalidade em desenvolvimento");
}

function filterProfessionals() {
  const search = document.getElementById("professionalSearch").value.toLowerCase();
  const select = document.getElementById("professionalSelect");
  const options = select.options;
  for (let i = 0; i < options.length; i++) {
    const option = options[i];
    if (i === 0) continue;
    const name = option.getAttribute("data-name") || "";
    const phone = option.getAttribute("data-phone") || "";
    if (name.includes(search) || phone.includes(search)) {
      option.style.display = "";
    } else {
      option.style.display = "none";
    }
  }
}

function filterPatients() {
  const search = document.getElementById("patientSearch").value.toLowerCase();
  const select = document.getElementById("patientSelect");
  const options = select.options;
  for (let i = 0; i < options.length; i++) {
    const option = options[i];
    if (i === 0) continue;
    const name = option.getAttribute("data-name") || "";
    const phone = option.getAttribute("data-phone") || "";
    if (name.includes(search) || phone.includes(search)) {
      option.style.display = "";
    } else {
      option.style.display = "none";
    }
  }
}

function switchTab(tab) {
  document.getElementById("tabProfessionals").style.borderBottomColor = "transparent";
  document.getElementById("tabProfessionals").style.color = "#54656f";
  document.getElementById("tabPatients").style.borderBottomColor = "transparent";
  document.getElementById("tabPatients").style.color = "#54656f";
  document.getElementById("tabManual").style.borderBottomColor = "transparent";
  document.getElementById("tabManual").style.color = "#54656f";
  document.getElementById("contentProfessionals").style.display = "none";
  document.getElementById("contentPatients").style.display = "none";
  document.getElementById("contentManual").style.display = "none";
  document.getElementById("professionalSelect").value = "";
  document.getElementById("patientSelect").value = "";
  document.getElementById("manualPhone").value = "";
  if (tab === "professionals") {
    document.getElementById("tabProfessionals").style.borderBottomColor = "#00a884";
    document.getElementById("tabProfessionals").style.color = "#00a884";
    document.getElementById("contentProfessionals").style.display = "block";
  } else if (tab === "patients") {
    document.getElementById("tabPatients").style.borderBottomColor = "#00a884";
    document.getElementById("tabPatients").style.color = "#00a884";
    document.getElementById("contentPatients").style.display = "block";
  } else if (tab === "manual") {
    document.getElementById("tabManual").style.borderBottomColor = "#00a884";
    document.getElementById("tabManual").style.color = "#00a884";
    document.getElementById("contentManual").style.display = "block";
  }
}

// Listener do formulário de atribuição de paciente
document.addEventListener("DOMContentLoaded", function() {
  const assignmentForm = document.getElementById("assignmentForm");
  if (assignmentForm) {
    assignmentForm.addEventListener("submit", function(e) {
      e.preventDefault();
      
      const demandSelect = document.getElementById("demandSelect");
      const patientId = document.getElementById("patientId").value;
      const specialtySelect = document.getElementById("specialty");
      const specialtyId = specialtySelect.value;
      const specialtyName = specialtySelect.options[specialtySelect.selectedIndex].getAttribute("data-name") || "";
      const serviceType = document.getElementById("serviceType").value;
      const sessionQuantity = document.getElementById("sessionQuantity").value;
      const sessionFrequency = document.getElementById("sessionFrequency").value;
      const agreedValue = parseFloat(document.getElementById("agreedValue").value);
      const authorizedValue = parseFloat(document.getElementById("authorizedValue").value);
      const serviceMinValue = parseFloat(document.getElementById("serviceMinValue").value || "0");
      const notes = document.getElementById("assignmentNotes").value;
      const healthInsurerId = document.getElementById("healthInsurer").value;
      
      if (!demandSelect || !demandSelect.value) {
        alert("Por favor, selecione um card de captação primeiro.");
        return;
      }
      
      const demandId = demandSelect.value;
      const professionalJid = window.chatId || "";
      
      if (!patientId || patientId === "new") {
        alert("Por favor, selecione um paciente válido.");
        return;
      }
      
      // Validar valores mínimos
      if (agreedValue < serviceMinValue) {
        alert("O Valor Acordado (R$ " + agreedValue.toFixed(2) + ") não pode ser menor que o valor mínimo do serviço (R$ " + serviceMinValue.toFixed(2) + ")");
        return;
      }
      
      if (authorizedValue < serviceMinValue) {
        alert("O Valor Autorizado (R$ " + authorizedValue.toFixed(2) + ") não pode ser menor que o valor mínimo do serviço (R$ " + serviceMinValue.toFixed(2) + ")");
        return;
      }
      
      if (agreedValue > authorizedValue) {
        if (!confirm("ATENÇÃO: O Valor Acordado (R$ " + agreedValue.toFixed(2) + ") é MAIOR que o Valor Autorizado (R$ " + authorizedValue.toFixed(2) + "). Isso resultará em PREJUÍZO de R$ " + (agreedValue - authorizedValue).toFixed(2) + ". Deseja continuar?")) {
          return;
        }
      }
      
      fetch("/chat_assign_patient.php", {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify({
          demand_id: demandId,
          patient_id: patientId,
          professional_jid: professionalJid,
          specialty_id: specialtyId,
          specialty: specialtyName,
          service_type_id: serviceType,
          session_quantity: sessionQuantity,
          session_frequency: sessionFrequency,
          agreed_value: agreedValue,
          authorized_value: authorizedValue,
          health_insurer_id: healthInsurerId,
          notes: notes
        })
      })
      .then(r => r.json())
      .then(data => {
        if(data.success) {
          alert("Paciente atribuído com sucesso! Mensagem enviada ao profissional.");
          closeAssignmentModal();
          location.reload();
        } else {
          alert("Erro: " + (data.error || "Erro ao atribuir paciente"));
        }
      })
      .catch(err => {
        alert("Erro ao processar atribuição: " + err.message);
      });
    });
  }
});

// Variável global para armazenar arquivo selecionado
let selectedMediaFile = null;
let selectedMediaType = null;

// Função para selecionar mídia (não envia automaticamente)
function handleMediaUpload(input, mediaType) {
  console.log('=== INICIO handleMediaUpload ===');
  console.log('Tipo de mídia:', mediaType);
  console.log('Input files:', input.files);
  
  const file = input.files[0];
  if (!file) {
    console.warn('Nenhum arquivo selecionado');
    return;
  }
  
  console.log('Arquivo selecionado:', {
    name: file.name,
    size: file.size,
    type: file.type
  });
  
  // Validar tamanho
  const maxSize = mediaType === 'video' ? 25 * 1024 * 1024 : 10 * 1024 * 1024;
  console.log('Tamanho máximo permitido:', maxSize, 'bytes');
  
  if (file.size > maxSize) {
    console.error('Arquivo muito grande:', file.size, '>', maxSize);
    alert('Arquivo muito grande. Máximo: ' + (maxSize / 1024 / 1024) + 'MB');
    input.value = '';
    return;
  }
  
  // Validar tipo
  const validTypes = {
    'audio': ['audio/mpeg', 'audio/mp3', 'audio/ogg', 'audio/wav', 'audio/webm'],
    'image': ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'],
    'video': ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime'],
    'document': ['application/pdf']
  };
  
  console.log('Tipos válidos para', mediaType, ':', validTypes[mediaType]);
  
  if (!validTypes[mediaType].includes(file.type)) {
    console.error('Tipo de arquivo não permitido:', file.type);
    alert('Tipo de arquivo não permitido para ' + mediaType);
    input.value = '';
    return;
  }
  
  console.log('Validações OK - Armazenando arquivo');
  
  // Armazenar arquivo e tipo
  selectedMediaFile = file;
  selectedMediaType = mediaType;
  
  console.log('Arquivo armazenado globalmente:', {
    file: selectedMediaFile,
    type: selectedMediaType
  });
  
  // Mostrar preview na área de input
  showMediaPreview(file, mediaType);
  
  // Limpar input file
  input.value = '';
  console.log('=== FIM handleMediaUpload ===');
}

// Função para mostrar preview do arquivo selecionado
function showMediaPreview(file, mediaType) {
  console.log('=== INICIO showMediaPreview ===');
  console.log('File:', file);
  console.log('MediaType:', mediaType);
  
  // Remover preview anterior se existir
  const existingPreview = document.getElementById('mediaPreview');
  if (existingPreview) {
    console.log('Removendo preview anterior');
    existingPreview.remove();
  }
  
  // Criar área de preview
  const previewDiv = document.createElement('div');
  previewDiv.id = 'mediaPreview';
  previewDiv.style.cssText = 'padding:12px;background:hsl(var(--muted));border:1px solid hsl(var(--border));border-radius:8px;margin-bottom:8px;display:flex;align-items:center;gap:12px;position:relative';
  
  console.log('Preview div criado');
  
  // Ícone baseado no tipo
  let icon = '';
  let label = '';
  if (mediaType === 'audio') {
    icon = '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 1a3 3 0 003 3v8a3 3 0 01-6 0V4a3 3 0 013-3z"/><path d="M19 10v2a7 7 0 01-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>';
    label = 'Áudio';
  } else if (mediaType === 'image') {
    icon = '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>';
    label = 'Imagem';
  } else if (mediaType === 'video') {
    icon = '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>';
    label = 'Vídeo';
  } else if (mediaType === 'document') {
    icon = '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8M16 17H8M10 9H8"/></svg>';
    label = 'Documento';
  }
  
  console.log('Ícone e label definidos:', label);
  
  previewDiv.innerHTML = `
    <div style="color:hsl(var(--primary))">${icon}</div>
    <div style="flex:1">
      <div style="font-weight:600;color:hsl(var(--foreground))">${label}: ${file.name}</div>
      <div style="font-size:12px;color:hsl(var(--muted-foreground))">${(file.size / 1024).toFixed(1)} KB</div>
    </div>
    <button type="button" onclick="clearMediaPreview()" style="background:hsl(var(--destructive));color:hsl(var(--destructive-foreground));border:none;border-radius:50%;width:28px;height:28px;cursor:pointer;font-weight:bold">×</button>
  `;
  
  console.log('HTML do preview definido');
  
  // Inserir preview antes do textarea
  const form = document.getElementById('sendMessageForm');
  console.log('Form encontrado:', !!form);
  
  if (!form) {
    console.error('ERRO: Formulário sendMessageForm não encontrado!');
    return;
  }
  
  const textarea = form.querySelector('textarea');
  console.log('Textarea encontrado:', !!textarea);
  
  if (!textarea) {
    console.error('ERRO: Textarea não encontrado no formulário!');
    return;
  }
  
  console.log('Inserindo preview antes do textarea');
  form.insertBefore(previewDiv, textarea);
  
  console.log('Preview inserido no DOM');
  
  // Tornar textarea opcional quando há mídia
  textarea.removeAttribute('required');
  textarea.placeholder = 'Digite uma legenda (opcional)...';
  
  console.log('Textarea atualizado');
  console.log('=== FIM showMediaPreview ===');
}

// Função para limpar preview e arquivo selecionado
function clearMediaPreview() {
  selectedMediaFile = null;
  selectedMediaType = null;
  
  const preview = document.getElementById('mediaPreview');
  if (preview) {
    preview.remove();
  }
  
  // Restaurar textarea como obrigatório
  const textarea = document.querySelector('#sendMessageForm textarea');
  if (textarea) {
    textarea.setAttribute('required', 'required');
    textarea.placeholder = 'Digite uma mensagem';
  }
}

// Modificar envio do formulário para incluir mídia
document.addEventListener('DOMContentLoaded', function() {
  const sendForm = document.getElementById('sendMessageForm');
  if (sendForm) {
    // Enter envia mensagem, Shift+Enter pula linha
    const msgTextarea = sendForm.querySelector('textarea[name=message]');
    if (msgTextarea) {
      msgTextarea.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
          e.preventDefault();
          sendForm.dispatchEvent(new Event('submit', { cancelable: true }));
        }
      });
    }
    
    sendForm.addEventListener('submit', async function(e) {
      e.preventDefault();
      
      // Se há mídia selecionada, enviar via upload
      if (selectedMediaFile && selectedMediaType) {
        await sendMediaMessage();
      } else {
        // Envio via AJAX (sem recarregar página)
        const textarea = sendForm.querySelector('textarea[name=message]');
        const message = textarea ? textarea.value.trim() : '';
        if (!message) return;
        
        const phoneInput = sendForm.querySelector('input[name=phone_number]');
        const chatId = phoneInput ? phoneInput.value : '';
        const isGroup = chatId.indexOf('@g.us') > -1;
        const typeParam = isGroup ? '&type=grupos' : '';
        const sendUrl = '/chat_web.php?chat=' + encodeURIComponent(chatId) + typeParam;
        
        const sendBtn = sendForm.querySelector('button[type=submit]');
        if (sendBtn) { sendBtn.disabled = true; sendBtn.style.opacity = '0.5'; }
        
        // Para grupos: pequeno delay entre envios (proteção extra)
        if (isGroup && window._lastGroupSendTime) {
          const elapsed = Date.now() - window._lastGroupSendTime;
          const minDelay = 2000; // 2 segundos entre mensagens para grupo
          if (elapsed < minDelay) {
            const waitMs = minDelay - elapsed;
            await new Promise(resolve => setTimeout(resolve, waitMs));
          }
        }
        
        // Mostrar mensagem otimista
        const chatArea = document.querySelector('.whatsapp-messages');
        let msgDiv = null;
        const optimisticId = 'optimistic-' + Date.now();
        if (chatArea) {
          msgDiv = document.createElement('div');
          msgDiv.className = 'whatsapp-message out';
          msgDiv.id = optimisticId;
          msgDiv.setAttribute('data-optimistic', '1');
          msgDiv.innerHTML = '<div class="whatsapp-message-bubble"><div class="whatsapp-message-text">' + message.replace(/\n/g,'<br>') + '</div><div class="whatsapp-message-time">' + new Date().toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'}) + ' ✓</div></div>';
          chatArea.appendChild(msgDiv);
          chatArea.scrollTop = chatArea.scrollHeight;
        }
        
        // Limpar campo imediatamente (UX melhor)
        textarea.value = '';
        textarea.style.height = 'auto';
        
        // Enviar via fetch
        const formData = new FormData(sendForm);
        formData.set('message', message);
        
        // Marcar timestamp ANTES de enviar (para calcular delay da próxima mensagem)
        if (isGroup) {
          window._lastGroupSendTime = Date.now();
        }
        
        // Debug: garantir que phone_number está correto
        console.log('[SEND] chatId:', chatId, '| isGroup:', isGroup, '| message:', message.substring(0, 20));
        
        try {
          const resp = await fetch(sendUrl, {
            method: 'POST',
            headers: {'X-Requested-With': 'XMLHttpRequest'},
            body: formData
          });
          
          const contentType = resp.headers.get('content-type') || '';
          if (contentType.indexOf('application/json') > -1) {
            const data = await resp.json();
            if (data.success) {
              // Marcar a mensagem otimista como confirmada
              if (msgDiv) {
                const timeEl = msgDiv.querySelector('.whatsapp-message-time');
                if (timeEl) timeEl.innerHTML = timeEl.innerHTML.replace('✓','✓✓');
              }
              window._lastOptimisticText = message;
            } else {
              // Erro no envio — remover mensagem otimista e mostrar erro
              if (msgDiv && chatArea) chatArea.removeChild(msgDiv);
              alert('Erro ao enviar: ' + (data.error || 'Erro desconhecido'));
            }
          }
          // Se não é JSON, mensagem provavelmente foi enviada (servidor redirecionou)
        } catch (err) {
          console.error('Erro no envio:', err);
          if (msgDiv && chatArea) chatArea.removeChild(msgDiv);
        } finally {
          if (sendBtn) { sendBtn.disabled = false; sendBtn.style.opacity = '1'; }
          if (textarea) textarea.focus();
        }
      }
    });
  }
});

// Função para enviar mensagem com mídia
async function sendMediaMessage() {
  console.log('=== INICIO sendMediaMessage ===');
  console.log('Arquivo selecionado:', selectedMediaFile);
  console.log('Tipo de mídia:', selectedMediaType);
  console.log('Chat ID:', window.chatId);
  
  const textarea = document.querySelector('#sendMessageForm textarea');
  const caption = textarea ? textarea.value.trim() : '';
  console.log('Legenda:', caption);
  
  // Mostrar loading
  const messagesContainer = document.getElementById('messagesContainer');
  const loadingDiv = document.createElement('div');
  loadingDiv.id = 'uploadLoading';
  loadingDiv.style.cssText = 'text-align:center;padding:20px;color:#667781';
  loadingDiv.innerHTML = '<div style="display:inline-block;width:40px;height:40px;border:4px solid #f3f3f3;border-top:4px solid #00a884;border-radius:50%;animation:spin 1s linear infinite"></div><p style="margin-top:12px">Enviando ' + selectedMediaType + '...</p>';
  messagesContainer.appendChild(loadingDiv);
  messagesContainer.scrollTop = messagesContainer.scrollHeight;
  
  console.log('Loading exibido');
  
  // Criar FormData
  const formData = new FormData();
  formData.append('media', selectedMediaFile);
  formData.append('remote_jid', window.chatId);
  formData.append('media_type', selectedMediaType);
  if (caption) {
    formData.append('caption', caption);
  }
  
  console.log('FormData criado:', {
    media: selectedMediaFile.name,
    remote_jid: window.chatId,
    media_type: selectedMediaType,
    caption: caption
  });
  
  try {
    console.log('Enviando requisição para /chat_send_media.php...');
    const response = await fetch('/chat_send_media.php', {
      method: 'POST',
      body: formData
    });
    
    console.log('Resposta recebida - Status:', response.status);
    
    const data = await response.json();
    console.log('Dados da resposta:', data);
    
    if (data.success) {
      console.log('Upload bem-sucedido!');
      // Limpar preview e recarregar
      clearMediaPreview();
      window.location.reload();
    } else {
      console.error('Erro no upload:', data.error);
      alert('Erro ao enviar mídia: ' + (data.error || 'Erro desconhecido'));
      loadingDiv.remove();
    }
  } catch (error) {
    console.error('Exception ao enviar mídia:', error);
    alert('Erro ao enviar mídia: ' + error.message);
    loadingDiv.remove();
  }
  
  console.log('=== FIM sendMediaMessage ===');
}

// Função para gravar áudio
let mediaRecorder = null;
let audioChunks = [];

function startAudioRecording() {
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    alert('Seu navegador não suporta gravação de áudio');
    return;
  }
  
  navigator.mediaDevices.getUserMedia({ audio: true })
    .then(stream => {
      mediaRecorder = new MediaRecorder(stream);
      audioChunks = [];
      
      mediaRecorder.addEventListener('dataavailable', event => {
        audioChunks.push(event.data);
      });
      
      mediaRecorder.addEventListener('stop', () => {
        const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
        const audioFile = new File([audioBlob], 'audio.webm', { type: 'audio/webm' });
        
        // Criar input temporário e fazer upload
        const tempInput = document.createElement('input');
        tempInput.type = 'file';
        const dataTransfer = new DataTransfer();
        dataTransfer.items.add(audioFile);
        tempInput.files = dataTransfer.files;
        
        handleMediaUpload(tempInput, 'audio');
        
        // Parar stream
        stream.getTracks().forEach(track => track.stop());
      });
      
      mediaRecorder.start();
      
      // Mostrar indicador de gravação
      const recordingIndicator = document.createElement('div');
      recordingIndicator.id = 'recordingIndicator';
      recordingIndicator.style.cssText = 'position:fixed;bottom:80px;right:20px;background:#dc2626;color:white;padding:12px 20px;border-radius:24px;font-weight:600;box-shadow:0 4px 12px rgba(0,0,0,0.3);z-index:1000;display:flex;align-items:center;gap:8px';
      recordingIndicator.innerHTML = '<div style="width:12px;height:12px;background:white;border-radius:50%;animation:pulse 1s infinite"></div>Gravando...';
      document.body.appendChild(recordingIndicator);
      
      // Parar após 60 segundos
      setTimeout(() => {
        if (mediaRecorder && mediaRecorder.state === 'recording') {
          stopAudioRecording();
        }
      }, 60000);
    })
    .catch(error => {
      alert('Erro ao acessar microfone: ' + error.message);
    });
}

function stopAudioRecording() {
  if (mediaRecorder && mediaRecorder.state === 'recording') {
    mediaRecorder.stop();
    const indicator = document.getElementById('recordingIndicator');
    if (indicator) indicator.remove();
  }
}

// Atualizar conversas automaticamente (polling a cada 3 segundos, sem reload)
let lastUpdateTimestamp = Date.now();
let isPolling = false;

function checkForNewMessages() {
  if (!window.chatId || isPolling) return;
  isPolling = true;
  
  fetch('/chat_poll_messages.php?chat_id=' + encodeURIComponent(window.chatId) + '&since=' + window.lastTimestamp)
    .then(r => r.json())
    .then(data => {
      isPolling = false;
      if (data.messages && data.messages.length > 0) {
        const container = document.getElementById('messagesContainer');
        if (!container) return;
        
        // Verificar se está no fundo antes de adicionar (para auto-scroll)
        const wasAtBottom = (container.scrollHeight - container.scrollTop - container.clientHeight) < 80;
        
        data.messages.forEach(msg => {
          appendMessageToDOM(msg);
        });
        
        // Atualizar lastTimestamp
        if (data.last_timestamp > window.lastTimestamp) {
          window.lastTimestamp = data.last_timestamp;
        }
        
        // Auto-scroll apenas se já estava no final
        if (wasAtBottom) {
          container.scrollTop = container.scrollHeight;
        }
        
        // Atualizar lista de conversas (preview da última mensagem)
        updateChatListPreview(data.messages[data.messages.length - 1]);
      }
    })
    .catch(err => {
      isPolling = false;
      console.error('Erro ao verificar atualizações:', err);
    });
}

// Renderizar uma mensagem nova no DOM sem recarregar a página
function appendMessageToDOM(msg) {
  const container = document.getElementById('messagesContainer');
  if (!container) return;
  
  // Verificar se mensagem já existe no DOM (evitar duplicatas por external_message_id)
  if (msg.external_message_id) {
    const existing = container.querySelector('[data-msg-id="' + msg.external_message_id + '"]');
    if (existing) return;
  }
  
  const isFromMe = msg.from_me == 1 || msg.from_me === true;
  
  // Se é uma mensagem from_me, verificar se já existe uma mensagem otimista equivalente
  if (isFromMe) {
    const optimisticMsgs = container.querySelectorAll('[data-optimistic="1"]');
    for (let i = 0; i < optimisticMsgs.length; i++) {
      const optText = optimisticMsgs[i].querySelector('.whatsapp-message-text');
      if (optText && optText.textContent.trim() === (msg.message_text || '').trim()) {
        // Remover a mensagem otimista — a versão real do servidor vai substituí-la
        optimisticMsgs[i].remove();
        break;
      }
    }
  }
  const messageClass = isFromMe ? 'out' : 'in';
  const timestamp = msg.message_timestamp ? formatTimestamp(msg.message_timestamp) : '';
  const messageType = msg.message_type || 'text';
  const text = msg.message_text || '';
  const mediaUrl = msg.media_url || '';
  const quotedText = msg.quoted_message_text || '';
  const quotedSender = msg.quoted_message_sender || '';
  const senderName = msg.sender_name || '';
  const reactions = msg.reactions || [];
  
  let html = '<div class="whatsapp-message ' + messageClass + '"' + (msg.external_message_id ? ' data-msg-id="' + escapeHtml(msg.external_message_id) + '"' : '') + '>';
  html += '<div class="whatsapp-message-bubble">';
  
  // Nome do remetente em grupos
  if (window.chatId && window.chatId.includes('@g.us') && !isFromMe && senderName) {
    html += '<div style="font-size:12px;font-weight:600;color:#06cf9c;margin-bottom:4px">' + escapeHtml(senderName) + '</div>';
  }
  
  // Mensagem citada (reply)
  if (quotedText) {
    const quotedSenderDisplay = quotedSender ? quotedSender.replace(/@s\.whatsapp\.net|@g\.us/g, '') : '';
    html += '<div style="background:rgba(0,0,0,.05);border-left:4px solid #06cf9c;border-radius:4px;padding:6px 10px;margin-bottom:6px;font-size:12px;cursor:pointer">';
    if (quotedSenderDisplay) {
      html += '<div style="font-weight:600;color:#06cf9c;margin-bottom:2px">' + escapeHtml(quotedSenderDisplay) + '</div>';
    }
    html += '<div style="color:#667781;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:300px">' + escapeHtml(quotedText) + '</div>';
    html += '</div>';
  }
  
  // Conteúdo baseado no tipo
  if (messageType === 'audio' && mediaUrl) {
    const mimeType = msg.media_mime_type || 'audio/ogg; codecs=opus';
    html += '<div style="margin-bottom:8px"><audio controls preload="metadata" style="max-width:100%;height:40px"><source src="' + escapeHtml(mediaUrl) + '" type="' + escapeHtml(mimeType) + '"></audio></div>';
  } else if (messageType === 'image' && mediaUrl) {
    html += '<div style="margin-bottom:8px"><img src="' + escapeHtml(mediaUrl) + '" alt="Imagem" style="max-width:100%;max-height:300px;border-radius:8px;cursor:pointer;object-fit:contain" onclick="window.open(this.src)"></div>';
    if (text && text !== '[Imagem]') {
      html += '<div class="whatsapp-message-text">' + escapeHtml(text) + '</div>';
    }
  } else if (messageType === 'video' && mediaUrl) {
    html += '<div style="margin-bottom:8px"><video controls style="max-width:100%;border-radius:8px"><source src="' + escapeHtml(mediaUrl) + '" type="' + escapeHtml(msg.media_mime_type || 'video/mp4') + '"></video></div>';
    if (text && text !== '[Vídeo]') {
      html += '<div class="whatsapp-message-text">' + escapeHtml(text) + '</div>';
    }
  } else if (messageType === 'document' && mediaUrl) {
    const docFilename = msg.media_filename || 'Documento';
    html += '<a href="' + escapeHtml(mediaUrl) + '" download style="display:flex;align-items:center;gap:12px;padding:12px 16px;background:rgba(0,0,0,.04);border:1px solid rgba(0,0,0,.08);border-radius:10px;text-decoration:none;color:inherit;min-width:240px;max-width:320px">';
    html += '<div style="width:40px;height:40px;border-radius:8px;background:#dc3545;display:flex;align-items:center;justify-content:center;flex-shrink:0"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6"/></svg></div>';
    html += '<div style="flex:1;min-width:0;overflow:hidden"><div style="font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + escapeHtml(docFilename) + '</div></div>';
    html += '</a>';
  } else if (messageType === 'sticker' && mediaUrl) {
    html += '<div style="margin-bottom:4px"><img src="' + escapeHtml(mediaUrl) + '" alt="Sticker" style="max-width:150px;max-height:150px"></div>';
  } else {
    html += '<div class="whatsapp-message-text">' + escapeHtml(text) + '</div>';
  }
  
  // Reações
  if (reactions.length > 0) {
    html += '<div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:4px">';
    const emojiCounts = {};
    reactions.forEach(r => { emojiCounts[r.emoji] = (emojiCounts[r.emoji] || 0) + 1; });
    Object.keys(emojiCounts).forEach(emoji => {
      const count = emojiCounts[emoji] > 1 ? ' ' + emojiCounts[emoji] : '';
      html += '<span style="background:rgba(0,0,0,.05);border-radius:12px;padding:2px 6px;font-size:12px">' + emoji + count + '</span>';
    });
    html += '</div>';
  }
  
  html += '<div class="whatsapp-message-time">' + escapeHtml(timestamp) + '</div>';
  html += '</div></div>';
  
  // Remover mensagem de "chat vazio" se existir
  const emptyChat = container.querySelector('.whatsapp-empty-chat');
  if (emptyChat) emptyChat.remove();
  
  container.insertAdjacentHTML('beforeend', html);
}

function formatTimestamp(unixTs) {
  const d = new Date(unixTs * 1000);
  return d.getHours().toString().padStart(2, '0') + ':' + d.getMinutes().toString().padStart(2, '0');
}

function escapeHtml(str) {
  if (!str) return '';
  const div = document.createElement('div');
  div.textContent = str;
  return div.innerHTML;
}

function updateChatListPreview(lastMsg) {
  // Atualizar preview na lista lateral
  const chatItems = document.querySelectorAll('.whatsapp-chat-item');
  chatItems.forEach(item => {
    const link = item.querySelector('a');
    if (link && link.href && link.href.includes(encodeURIComponent(window.chatId))) {
      const preview = item.querySelector('.whatsapp-chat-last-msg, [style*="color:#667781"]');
      if (preview && lastMsg.message_text) {
        let previewText = lastMsg.message_text;
        if (lastMsg.message_type && lastMsg.message_type !== 'text') {
          const typeLabels = { audio: '🎵 Áudio', image: '📷 Imagem', video: '🎬 Vídeo', document: '📄 Documento', sticker: '🏷️ Sticker' };
          previewText = typeLabels[lastMsg.message_type] || previewText;
        }
        preview.textContent = previewText.substring(0, 40) + (previewText.length > 40 ? '...' : '');
      }
    }
  });
}

// Iniciar polling se houver chat selecionado
// (Será iniciado pelo inline script que define window.chatId)
function startChatPolling() {
  if (window.chatId && !window._pollingStarted) {
    window._pollingStarted = true;
    setInterval(checkForNewMessages, 3000);
    console.log("✅ Polling iniciado para chat:", window.chatId, "| lastTimestamp:", window.lastTimestamp);
  }
}

// Tentar iniciar imediatamente (caso chatId já esteja definido)
startChatPolling();

// Também tentar após DOMContentLoaded (fallback)
document.addEventListener('DOMContentLoaded', function() {
  startChatPolling();
});

// Auto-scroll para última mensagem
document.addEventListener('DOMContentLoaded', function() {
  const messagesContainer = document.getElementById('messagesContainer');
  if (messagesContainer) {
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
  }
  
  // Carregar grupos filtrados ao abrir painel
  const groupSpecialty = document.getElementById('groupSpecialty');
  const groupRegion = document.getElementById('groupRegion');
  if (groupSpecialty && groupRegion) {
    loadGroupsByFilter();
  }
  
  // Adicionar event listeners para inputs de mídia
  const audioInput = document.getElementById('audioInput');
  const imageInput = document.getElementById('imageInput');
  const videoInput = document.getElementById('videoInput');
  const documentInput = document.getElementById('documentInput');
  
  console.log('Procurando inputs de mídia:', {
    audio: !!audioInput,
    image: !!imageInput,
    video: !!videoInput,
    document: !!documentInput
  });
  
  if (audioInput) {
    console.log('Adicionando listener para audioInput');
    audioInput.addEventListener('change', function(e) {
      console.log('CHANGE EVENT DISPARADO - audioInput', e);
      const mediaType = this.getAttribute('data-media-type');
      console.log('Media type:', mediaType);
      handleMediaUpload(this, mediaType);
    });
  }
  
  if (imageInput) {
    console.log('Adicionando listener para imageInput');
    imageInput.addEventListener('change', function(e) {
      console.log('CHANGE EVENT DISPARADO - imageInput', e);
      const mediaType = this.getAttribute('data-media-type');
      console.log('Media type:', mediaType);
      handleMediaUpload(this, mediaType);
    });
  }
  
  if (videoInput) {
    console.log('Adicionando listener para videoInput');
    videoInput.addEventListener('change', function(e) {
      console.log('CHANGE EVENT DISPARADO - videoInput', e);
      const mediaType = this.getAttribute('data-media-type');
      console.log('Media type:', mediaType);
      handleMediaUpload(this, mediaType);
    });
  }
  
  if (documentInput) {
    console.log('Adicionando listener para documentInput');
    documentInput.addEventListener('change', function(e) {
      console.log('CHANGE EVENT DISPARADO - documentInput', e);
      const mediaType = this.getAttribute('data-media-type');
      console.log('Media type:', mediaType);
      handleMediaUpload(this, mediaType);
    });
  }
  
  console.log('✅ Event listeners de mídia adicionados com sucesso');
});

// Adicionar CSS para animações
const style = document.createElement('style');
style.textContent = `
  @keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
  }
  @keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
  }
`;
document.head.appendChild(style);

console.log("✅ Chat Web Functions carregadas com sucesso");
