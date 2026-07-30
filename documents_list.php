<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('documents.manage');

$tab = isset($_GET['tab']) ? (string)$_GET['tab'] : 'patients';
$searchQuery = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$selectedEntityId = isset($_GET['entity_id']) ? (int)$_GET['entity_id'] : 0;

$allowedTabs = ['patients', 'professionals', 'employees', 'sent'];
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'patients';
}

// Mapear tab para entity_type
$entityTypeMap = [
    'patients' => 'patient',
    'professionals' => 'professional',
    'employees' => 'company'
];
$entityType = $entityTypeMap[$tab];

// Buscar lista de entidades (pessoas) que têm documentos
$entities = [];

if ($tab === 'patients') {
    $sql = "
        SELECT DISTINCT p.id, p.full_name as name,
               (SELECT COUNT(*) FROM documents d WHERE d.entity_type = 'patient' AND d.entity_id = p.id AND d.status = 'active') as document_count
        FROM patients p
        INNER JOIN documents d ON d.entity_type = 'patient' AND d.entity_id = p.id AND d.status = 'active'
        WHERE p.deleted_at IS NULL
    ";
    
    if ($searchQuery !== '') {
        $sql .= " AND p.full_name LIKE ?";
    }
    
    $sql .= " GROUP BY p.id, p.full_name ORDER BY p.full_name ASC";
    
    $stmt = db()->prepare($sql);
    if ($searchQuery !== '') {
        $stmt->execute(['%' . $searchQuery . '%']);
    } else {
        $stmt->execute();
    }
    $entities = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $sql = "
        SELECT DISTINCT u.id, u.name,
               (SELECT COUNT(*) FROM documents d WHERE d.entity_type = ? AND d.entity_id = u.id AND d.status = 'active') as document_count
        FROM users u
        INNER JOIN documents d ON d.entity_type = ? AND d.entity_id = u.id AND d.status = 'active'
    ";
    
    if ($searchQuery !== '') {
        $sql .= " WHERE u.name LIKE ?";
    }
    
    $sql .= " GROUP BY u.id, u.name ORDER BY u.name ASC";
    
    $stmt = db()->prepare($sql);
    if ($searchQuery !== '') {
        $stmt->execute([$entityType, $entityType, '%' . $searchQuery . '%']);
    } else {
        $stmt->execute([$entityType, $entityType]);
    }
    $entities = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Buscar documentos da entidade selecionada (mais recente ao mais antigo)
$documents = [];
$selectedEntityName = '';

if ($selectedEntityId > 0) {
    // Buscar nome da entidade selecionada
    if ($tab === 'patients') {
        $nameStmt = db()->prepare("SELECT full_name as name FROM patients WHERE id = ?");
        $nameStmt->execute([$selectedEntityId]);
        $nameResult = $nameStmt->fetch(PDO::FETCH_ASSOC);
        $selectedEntityName = $nameResult ? $nameResult['name'] : '';
    } else {
        $nameStmt = db()->prepare("SELECT name FROM users WHERE id = ?");
        $nameStmt->execute([$selectedEntityId]);
        $nameResult = $nameStmt->fetch(PDO::FETCH_ASSOC);
        $selectedEntityName = $nameResult ? $nameResult['name'] : '';
    }
    
    // Buscar documentos
    $docsStmt = db()->prepare("
        SELECT d.id, d.title, d.category, d.status, d.created_at,
               (SELECT MAX(v.version_no) FROM document_versions v WHERE v.document_id = d.id) as last_version,
               (SELECT v.stored_path FROM document_versions v WHERE v.document_id = d.id ORDER BY v.version_no DESC LIMIT 1) as file_path,
               (SELECT v.file_size FROM document_versions v WHERE v.document_id = d.id ORDER BY v.version_no DESC LIMIT 1) as file_size,
               (SELECT u.name FROM document_versions v LEFT JOIN users u ON u.id = v.uploaded_by_user_id WHERE v.document_id = d.id ORDER BY v.version_no DESC LIMIT 1) as uploaded_by_name
        FROM documents d
        WHERE d.entity_type = ? AND d.entity_id = ? AND d.status = 'active'
        ORDER BY d.created_at DESC
    ");
    $docsStmt->execute([$entityType, $selectedEntityId]);
    $documents = $docsStmt->fetchAll(PDO::FETCH_ASSOC);
}

view_header('Gestão Documental');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Gestão Documental</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Documentos consolidados por paciente, profissional ou funcionário</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

// Abas de navegação
echo '<div style="margin-top:20px;border-bottom:2px solid #e5e7eb">';
echo '<div style="display:flex;gap:4px">';

$tabs = [
    'patients' => '<svg style="width:16px;height:16px;margin-right:6px;vertical-align:middle" fill="currentColor" viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>Pacientes',
    'professionals' => '<svg style="width:16px;height:16px;margin-right:6px;vertical-align:middle" fill="currentColor" viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2V7h2v10zm4 0h-2v-4h2v4z"/></svg>Profissionais',
    'employees' => '<svg style="width:16px;height:16px;margin-right:6px;vertical-align:middle" fill="currentColor" viewBox="0 0 24 24"><path d="M20 6h-4V4c0-1.1-.9-2-2-2h-4c-1.1 0-2 .9-2 2v2H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2zM10 4h4v2h-4V4zm10 16H4V8h16v12z"/></svg>Funcionários',
    'sent' => '<svg style="width:16px;height:16px;margin-right:6px;vertical-align:middle" fill="currentColor" viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>Enviados'
];

foreach ($tabs as $tabKey => $tabLabel) {
    $isActive = $tab === $tabKey;
    $activeStyle = $isActive ? 'background:hsl(var(--primary));color:white;border-color:hsl(var(--primary))' : 'background:white;color:#667781;border-color:transparent';
    $href = $tabKey === 'sent' ? '/documents_list.php?tab=sent' : '/documents_list.php?tab=' . $tabKey;
    echo '<a href="' . $href . '" style="padding:12px 24px;text-decoration:none;font-weight:600;border:2px solid;border-bottom:none;border-radius:8px 8px 0 0;' . $activeStyle . '">' . $tabLabel . '</a>';
}

echo '</div>';
echo '</div>';

echo '</section>';

// Filtro de pesquisa
echo '<section class="card col12" style="margin-top:16px">';
echo '<form method="get" action="/documents_list.php" style="margin-bottom:0">';
echo '<input type="hidden" name="tab" value="' . h($tab) . '">';
if ($selectedEntityId > 0) {
    echo '<input type="hidden" name="entity_id" value="' . $selectedEntityId . '">';
}
echo '<input type="text" name="q" value="' . h($searchQuery) . '" placeholder="Buscar por nome..." style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:6px">';
echo '</form>';
echo '</section>';

echo '<section class="card col12" style="margin-top:16px">';

// Aba "Enviados" - Gerenciamento de documentos enviados
if ($tab === 'sent') {
    // Criar tabela se não existir
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS document_send_logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            document_id INT UNSIGNED NOT NULL DEFAULT 0,
            document_source VARCHAR(50) NOT NULL DEFAULT 'manual',
            recipient_type VARCHAR(50) NOT NULL DEFAULT 'professional',
            recipient_id INT UNSIGNED NULL,
            recipient_email VARCHAR(255) NULL,
            assignment_id INT UNSIGNED NULL,
            demand_id INT UNSIGNED NULL,
            health_insurer_id INT UNSIGNED NULL,
            send_method VARCHAR(30) NOT NULL DEFAULT 'email',
            sent_by_user_id INT UNSIGNED NULL,
            file_name VARCHAR(255) NULL,
            file_path VARCHAR(500) NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {}
    
    // Processar envio manual
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_doc') {
        $rType = trim((string)($_POST['recipient_type'] ?? ''));
        $rId = (int)($_POST['recipient_id'] ?? 0);
        $insId = (int)($_POST['health_insurer_id'] ?? 0);
        $method = trim((string)($_POST['send_method'] ?? 'email'));
        $note = trim((string)($_POST['notes'] ?? ''));
        
        if ($rId > 0 && $rType !== '' && isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
            $fn = $_FILES['document']['name'];
            $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
            if (in_array($ext, ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','webp']) && $_FILES['document']['size'] <= 10485760) {
                $dir = __DIR__ . '/uploads/manual_docs/';
                if (!is_dir($dir)) @mkdir($dir, 0755, true);
                $un = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file($_FILES['document']['tmp_name'], $dir . $un)) {
                    $rp = '/uploads/manual_docs/' . $un;
                    $re = '';
                    try {
                        $s = db()->prepare($rType === 'professional' ? "SELECT email FROM users WHERE id=?" : "SELECT email FROM patients WHERE id=?");
                        $s->execute([$rId]);
                        $re = (string)($s->fetchColumn() ?: '');
                    } catch (Throwable $e) {}
                    try {
                        db()->prepare("INSERT INTO document_send_logs (document_source,recipient_type,recipient_id,recipient_email,health_insurer_id,send_method,sent_by_user_id,file_name,file_path,notes) VALUES('manual',?,?,?,?,?,?,?,?,?)")
                            ->execute([$rType,$rId,$re,$insId>0?$insId:null,$method,auth_user_id(),$fn,$rp,$note]);
                    } catch (Throwable $e) {}
                    if ($method === 'email' && $re !== '' && filter_var($re, FILTER_VALIDATE_EMAIL)) {
                        try {
                            require_once __DIR__ . '/app/email_base_template.php';
                            $body = '<p style="font-size:15px;color:#374151">Olá!</p><p style="font-size:14px;color:#4b5563">Segue documento:</p>';
                            $body .= '<div style="background:#f9fafb;padding:18px 20px;margin:20px 0;border-radius:8px"><p style="margin:0">📄 <a href="https://multilife.onsolutionsbrasil.com.br'.$rp.'" style="color:#0284c7">'.htmlspecialchars($fn).'</a></p></div>';
                            $body .= '<p style="font-size:14px;color:#6b7280;margin-top:20px">Atenciosamente,<br><strong style="color:#00a884">Equipe MultiLife Care</strong></p>';
                            $smtp = new SmtpClient();
                            $smtp->send((string)admin_setting_get('smtp.out.from_email',''), (string)admin_setting_get('smtp.out.from_name','MultiLife Care'), $re, 'Documento - '.$fn, email_base_layout('Documento Enviado', $body));
                        } catch (Throwable $e) {}
                    }
                    echo '<div style="background:#d1fae5;padding:12px;border-radius:8px;margin-bottom:16px;color:#065f46;font-weight:600">Documento enviado com sucesso!</div>';
                }
            }
        }
    }
    
    // Buscar logs
    $sentLogs = [];
    try {
        $sq = $searchQuery !== '' ? $searchQuery : '';
        if ($sq !== '') {
            $st = db()->prepare("SELECT * FROM document_send_logs WHERE file_name LIKE ? OR recipient_email LIKE ? ORDER BY created_at DESC LIMIT 100");
            $st->execute(["%$sq%","%$sq%"]);
        } else {
            $st = db()->query("SELECT * FROM document_send_logs ORDER BY created_at DESC LIMIT 100");
        }
        $sentLogs = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    
    // Botão enviar
    echo '<div style="display:flex;justify-content:flex-end;margin-bottom:16px">';
    echo '<button onclick="document.getElementById(\'sendDocModal\').style.display=\'flex\'" class="btn btnPrimary">Enviar Documento</button>';
    echo '</div>';
    
    if (empty($sentLogs)) {
        echo '<div style="padding:40px;text-align:center;color:#667781">';
        echo '<div style="font-size:16px;font-weight:600;margin-bottom:8px">Nenhum documento enviado ainda</div>';
        echo '<div style="font-size:14px">Use o botão acima para enviar documentos para profissionais ou pacientes.</div>';
        echo '</div>';
    } else {
        echo '<div style="overflow:auto"><table><thead><tr><th>Data</th><th>Documento</th><th>Destinatário</th><th>Tipo</th><th>Método</th><th>Origem</th><th>Obs</th></tr></thead><tbody>';
        foreach ($sentLogs as $l) {
            $tp = $l['recipient_type']==='professional'?'Prof.':'Pac.';
            $mt = $l['send_method']==='email'?'E-mail':ucfirst($l['send_method']);
            $or = $l['document_source']==='insurer'?'Auto':'Manual';
            echo '<tr>';
            echo '<td style="font-size:12px;white-space:nowrap">'.date('d/m/Y H:i',strtotime($l['created_at'])).'</td>';
            echo '<td>'.(!empty($l['file_path'])?'<a href="'.h($l['file_path']).'" target="_blank" style="color:hsl(var(--primary))">📄 '.h($l['file_name']?:'Doc').'</a>':h($l['file_name']?:'-')).'</td>';
            echo '<td style="font-size:12px">'.h($l['recipient_email']?:'-').'</td>';
            echo '<td style="font-size:11px">'.$tp.'</td>';
            echo '<td style="font-size:11px">'.$mt.'</td>';
            echo '<td style="font-size:11px">'.$or.'</td>';
            echo '<td style="font-size:11px;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'.h($l['notes']?:'').'</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
    
    // Modal de envio
    $insForModal = [];
    try { $insForModal = db()->query("SELECT id,name FROM health_insurers WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC); } catch(Throwable $e){}
    $profsForModal = [];
    try { $profsForModal = db()->query("SELECT u.id,u.name FROM users u INNER JOIN user_roles ur ON ur.user_id=u.id INNER JOIN roles r ON r.id=ur.role_id WHERE u.status='active' AND r.slug='profissional' ORDER BY u.name")->fetchAll(PDO::FETCH_ASSOC); } catch(Throwable $e){}
    $patsForModal = [];
    try { $patsForModal = db()->query("SELECT id,full_name FROM patients ORDER BY full_name LIMIT 200")->fetchAll(PDO::FETCH_ASSOC); } catch(Throwable $e){}
    
    echo '<div id="sendDocModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:20px" onclick="if(event.target===this)this.style.display=\'none\'">';
    echo '<div style="background:#fff;border-radius:12px;padding:24px;max-width:520px;width:100%;max-height:90vh;overflow-y:auto">';
    echo '<h2 style="margin:0 0 16px;font-size:18px;font-weight:700">Enviar Documento</h2>';
    echo '<form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="send_doc">';
    echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px">';
    echo '<div><label style="font-size:12px;font-weight:600">Tipo *</label><select name="recipient_type" id="sdType" onchange="sdUpdate()" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;margin-top:4px"><option value="">Selecione</option><option value="professional">Profissional</option><option value="patient">Paciente</option></select></div>';
    echo '<div><label style="font-size:12px;font-weight:600">Destinatário *</label><select name="recipient_id" id="sdRecip" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;margin-top:4px"><option value="">Selecione tipo</option></select></div>';
    echo '</div>';
    echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px">';
    echo '<div><label style="font-size:12px;font-weight:600">Operadora</label><select name="health_insurer_id" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;margin-top:4px"><option value="0">Nenhuma</option>';
    foreach($insForModal as $i) echo '<option value="'.(int)$i['id'].'">'.h($i['name']).'</option>';
    echo '</select></div>';
    echo '<div><label style="font-size:12px;font-weight:600">Método *</label><select name="send_method" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;margin-top:4px"><option value="email">E-mail</option><option value="portal">Portal</option></select></div>';
    echo '</div>';
    echo '<div style="margin-bottom:12px"><label style="font-size:12px;font-weight:600">Arquivo *</label><input type="file" name="document" required accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" style="width:100%;margin-top:4px"></div>';
    echo '<div style="margin-bottom:16px"><label style="font-size:12px;font-weight:600">Observação</label><input name="notes" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;margin-top:4px" placeholder="Motivo..."></div>';
    echo '<div style="display:flex;gap:10px"><button type="button" onclick="document.getElementById(\'sendDocModal\').style.display=\'none\'" class="btn" style="flex:1">Cancelar</button><button type="submit" class="btn btnPrimary" style="flex:1">Enviar</button></div>';
    echo '</form></div></div>';
    
    echo '<script>var sdP=[';
    foreach($profsForModal as $i=>$p){if($i)echo ',';echo '['.(int)$p['id'].',"'.addslashes($p['name']).'"]';}
    echo '];var sdA=[';
    foreach($patsForModal as $i=>$p){if($i)echo ',';echo '['.(int)$p['id'].',"'.addslashes($p['full_name']).'"]';}
    echo '];function sdUpdate(){var t=document.getElementById("sdType").value,s=document.getElementById("sdRecip"),l=t==="professional"?sdP:t==="patient"?sdA:[];s.innerHTML="<option value=\\'\\'>Selecione...</option>";for(var i=0;i<l.length;i++){var o=document.createElement("option");o.value=l[i][0];o.textContent=l[i][1];s.appendChild(o);}}</script>';
    
    echo '</section>';
    echo '</div>';
    view_footer();
    exit;
}

// Se nenhum paciente selecionado, mostra lista de nomes
if ($selectedEntityId === 0) {
    if (count($entities) === 0) {
        echo '<div style="padding:40px;text-align:center;color:#667781">';
        echo $searchQuery !== '' ? 'Nenhum resultado para "' . h($searchQuery) . '"' : 'Nenhum registro com documentos';
        echo '</div>';
    } else {
        echo '<div style="overflow:auto">';
        echo '<table>';
        echo '<thead><tr>';
        echo '<th>' . ($tab === 'patients' ? 'Paciente' : ($tab === 'professionals' ? 'Profissional' : 'Funcionário')) . '</th>';
        echo '<th>Documentos</th>';
        echo '<th style="text-align:right">Ações</th>';
        echo '</tr></thead><tbody>';
        
        foreach ($entities as $entity) {
            echo '<tr>';
            echo '<td style="font-weight:600">' . h($entity['name']) . '</td>';
            echo '<td>' . (int)$entity['document_count'] . ' documento(s)</td>';
            echo '<td style="text-align:right">';
            echo '<a class="btn btnPrimary" href="/documents_list.php?tab=' . h($tab) . '&entity_id=' . (int)$entity['id'] . ($searchQuery !== '' ? '&q=' . urlencode($searchQuery) : '') . '">Ver documentos</a>';
            echo '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        echo '</div>';
    }
} else {
    // Paciente selecionado, mostra documentos
    echo '<div style="margin-bottom:16px;display:flex;align-items:center;justify-content:space-between">';
    echo '<div>';
    echo '<a href="/documents_list.php?tab=' . h($tab) . ($searchQuery !== '' ? '&q=' . urlencode($searchQuery) : '') . '" class="btn" style="margin-right:12px">← Voltar</a>';
    echo '<span style="font-size:18px;font-weight:700">' . h($selectedEntityName) . '</span>';
    echo '<span style="font-size:14px;color:#667781;margin-left:12px">' . count($documents) . ' documento(s)</span>';
    echo '</div>';
    echo '<a class="btn btnPrimary" href="/documents_upload.php?entity_type=' . h($entityType) . '&entity_id=' . $selectedEntityId . '"><svg style="width:16px;height:16px;margin-right:6px;vertical-align:middle" fill="currentColor" viewBox="0 0 24 24"><path d="M9 16h6v-6h4l-7-7-7 7h4zm-4 2h14v2H5z"/></svg>Upload</a>';
    echo '</div>';
    
    if (count($documents) === 0) {
        echo '<div style="padding:40px;text-align:center;color:#667781">Nenhum documento encontrado</div>';
    } else {
        echo '<div style="overflow:auto">';
        echo '<table>';
        echo '<thead><tr>';
        echo '<th>Título</th><th>Categoria</th><th>Versão</th><th>Tamanho</th><th>Enviado por</th><th>Data</th><th style="text-align:right">Ações</th>';
        echo '</tr></thead><tbody>';
        
        foreach ($documents as $doc) {
            $fileSize = (int)($doc['file_size'] ?? 0);
            $fileSizeFormatted = $fileSize > 1048576 ? number_format($fileSize / 1048576, 2) . ' MB' : ($fileSize > 0 ? number_format($fileSize / 1024, 2) . ' KB' : '-');
            $version = $doc['last_version'] ? 'v' . $doc['last_version'] : '-';
            
            echo '<tr>';
            echo '<td style="font-weight:600">' . h($doc['title'] ?? 'Sem título') . '</td>';
            echo '<td>' . h($doc['category'] ?? '-') . '</td>';
            echo '<td>' . h($version) . '</td>';
            echo '<td>' . $fileSizeFormatted . '</td>';
            echo '<td>' . h($doc['uploaded_by_name'] ?? '-') . '</td>';
            echo '<td>' . date('d/m/Y', strtotime($doc['created_at'])) . '</td>';
            echo '<td style="text-align:right">';
            if (!empty($doc['file_path'])) {
                echo '<a class="btn" href="' . h($doc['file_path']) . '" target="_blank"><svg style="width:14px;height:14px;margin-right:4px;vertical-align:middle" fill="currentColor" viewBox="0 0 24 24"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>Download</a> ';
            }
            echo '<a class="btn" href="/documents_view.php?id=' . (int)$doc['id'] . '"><svg style="width:14px;height:14px;margin-right:4px;vertical-align:middle" fill="currentColor" viewBox="0 0 24 24"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>Ver</a>';
            echo '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        echo '</div>';
    }
}

echo '</section>';

echo '</div>';

view_footer();
