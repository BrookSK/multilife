<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
auth_require_login();
$db = db();
try { $db->exec("CREATE TABLE IF NOT EXISTS document_send_logs (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, document_id INT UNSIGNED NOT NULL DEFAULT 0, document_source VARCHAR(50) NOT NULL DEFAULT 'manual', recipient_type VARCHAR(50) NOT NULL DEFAULT 'professional', recipient_id INT UNSIGNED NULL, recipient_email VARCHAR(255) NULL, assignment_id INT UNSIGNED NULL, demand_id INT UNSIGNED NULL, health_insurer_id INT UNSIGNED NULL, send_method VARCHAR(30) NOT NULL DEFAULT 'email', sent_by_user_id INT UNSIGNED NULL, file_name VARCHAR(255) NULL, file_path VARCHAR(500) NULL, notes TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch (Throwable $e) {}
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send'])) {
    $rType = isset($_POST['recipient_type']) ? trim((string)$_POST['recipient_type']) : '';
    $rId = isset($_POST['recipient_id']) ? (int)$_POST['recipient_id'] : 0;
    $insId = isset($_POST['health_insurer_id']) ? (int)$_POST['health_insurer_id'] : 0;
    $method = isset($_POST['send_method']) ? trim((string)$_POST['send_method']) : 'email';
    $note = isset($_POST['notes']) ? trim((string)$_POST['notes']) : '';
    if ($rId > 0 && $rType !== '' && isset($_FILES['doc']) && $_FILES['doc']['error'] === UPLOAD_ERR_OK) {
        $fn = $_FILES['doc']['name'];
        $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
        if (in_array($ext, array('pdf','doc','docx','xls','xlsx','jpg','jpeg','png','webp')) && $_FILES['doc']['size'] <= 10485760) {
            $dir = __DIR__ . '/uploads/manual_docs/';
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $un = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (move_uploaded_file($_FILES['doc']['tmp_name'], $dir . $un)) {
                $rp = '/uploads/manual_docs/' . $un;
                $re = '';
                try { $s = $db->prepare($rType === 'professional' ? "SELECT email FROM users WHERE id=?" : "SELECT email FROM patients WHERE id=?"); $s->execute(array($rId)); $re = (string)$s->fetchColumn(); if ($re === false) $re = ''; } catch (Throwable $e) {}
                try { $db->prepare("INSERT INTO document_send_logs (document_source,recipient_type,recipient_id,recipient_email,health_insurer_id,send_method,sent_by_user_id,file_name,file_path,notes) VALUES('manual',?,?,?,?,?,?,?,?,?)")->execute(array($rType,$rId,$re,$insId>0?$insId:null,$method,auth_user_id(),$fn,$rp,$note)); } catch (Throwable $e) {}
                if ($method === 'email' && $re !== '' && filter_var($re, FILTER_VALIDATE_EMAIL)) {
                    try { require_once __DIR__ . '/app/email_base_template.php'; $body = '<p>Segue documento enviado:</p><div style="background:#f9fafb;padding:18px 20px;margin:20px 0;border-radius:8px"><p style="margin:0">📄 <a href="https://multilife.onsolutionsbrasil.com.br'.$rp.'">'.$fn.'</a></p></div><p>Equipe MultiLife Care</p>'; $smtp = new SmtpClient(); $smtp->send((string)admin_setting_get('smtp.out.from_email',''), (string)admin_setting_get('smtp.out.from_name','MultiLife Care'), $re, 'Documento - '.$fn, email_base_layout('Documento Enviado', $body)); } catch (Throwable $e) {}
                }
                $flash = 'ok';
            }
        } else { $flash = 'err'; }
    } else { $flash = 'err'; }
}
$filterQ = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$logs = array();
try { if ($filterQ !== '') { $st = $db->prepare("SELECT * FROM document_send_logs WHERE file_name LIKE ? OR recipient_email LIKE ? ORDER BY created_at DESC LIMIT 100"); $st->execute(array("%$filterQ%","%$filterQ%")); } else { $st = $db->query("SELECT * FROM document_send_logs ORDER BY created_at DESC LIMIT 100"); } $logs = $st->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
$insurers = array();
try { $insurers = $db->query("SELECT id, name FROM health_insurers WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
$profs = array();
try { $profs = $db->query("SELECT u.id, u.name FROM users u INNER JOIN user_roles ur ON ur.user_id = u.id INNER JOIN roles r ON r.id = ur.role_id WHERE u.status = 'active' AND r.slug = 'profissional' ORDER BY u.name")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
$pats = array();
try { $pats = $db->query("SELECT id, full_name FROM patients ORDER BY full_name LIMIT 200")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
view_header('Documentos Enviados');
if ($flash === 'ok') echo '<div style="background:#d1fae5;padding:12px;border-radius:8px;margin-bottom:16px;color:#065f46;font-weight:600">Documento enviado com sucesso!</div>';
if ($flash === 'err') echo '<div style="background:#fee2e2;padding:12px;border-radius:8px;margin-bottom:16px;color:#991b1b;font-weight:600">Erro no envio. Verifique os campos.</div>';
echo '<div class="grid">';
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div><div style="font-size:22px;font-weight:900">Gerenciamento de Documentos Enviados</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Envie documentos e acompanhe o historico de envios</div></div>';
echo '<div style="display:flex;gap:10px">';
echo '<button onclick="document.getElementById(\'modal\').style.display=\'flex\'" class="btn btnPrimary">Enviar Documento</button>';
echo '<a class="btn" href="/documents_list.php">Voltar</a></div>';
echo '</div>';
echo '<form method="get" style="display:flex;gap:12px;margin-top:16px"><input name="q" value="'.htmlspecialchars($filterQ).'" placeholder="Buscar..." style="flex:1"><button class="btn" type="submit">Filtrar</button>';
if ($filterQ !== '') echo '<a class="btn" href="/docs_enviados.php">Limpar</a>';
echo '</form></section>';
echo '<section class="card col12">';
if (empty($logs)) {
    echo '<div style="padding:40px;text-align:center;color:hsl(var(--muted-foreground))"><div style="font-size:16px;font-weight:600;margin-bottom:8px">Nenhum documento enviado ainda</div><div style="font-size:14px">Use o botao acima para enviar documentos.</div></div>';
} else {
    echo '<div style="overflow:auto"><table><thead><tr><th>Data</th><th>Documento</th><th>Destinatario</th><th>Tipo</th><th>Metodo</th><th>Origem</th><th>Obs</th></tr></thead><tbody>';
    foreach ($logs as $l) {
        $tp = isset($l['recipient_type']) && $l['recipient_type'] === 'professional' ? 'Prof.' : 'Pac.';
        $mt = isset($l['send_method']) && $l['send_method'] === 'email' ? 'E-mail' : 'Portal';
        $or = isset($l['document_source']) && $l['document_source'] === 'insurer' ? 'Auto' : 'Manual';
        echo '<tr>';
        echo '<td style="font-size:12px;white-space:nowrap">'.date('d/m/Y H:i',strtotime($l['created_at'])).'</td>';
        echo '<td>';
        if (!empty($l['file_path'])) { echo '<a href="'.htmlspecialchars($l['file_path']).'" target="_blank" style="color:hsl(var(--primary))">'.htmlspecialchars($l['file_name']).'</a>'; } else { echo htmlspecialchars(isset($l['file_name']) ? $l['file_name'] : '-'); }
        echo '</td>';
        echo '<td style="font-size:12px">'.htmlspecialchars(isset($l['recipient_email']) ? $l['recipient_email'] : '-').'</td>';
        echo '<td style="font-size:11px">'.$tp.'</td>';
        echo '<td style="font-size:11px">'.$mt.'</td>';
        echo '<td style="font-size:11px">'.$or.'</td>';
        echo '<td style="font-size:11px;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'.htmlspecialchars(isset($l['notes']) ? $l['notes'] : '').'</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}
echo '</section></div>';
// Modal
echo '<div id="modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:20px" onclick="if(event.target===this)this.style.display=\'none\'">';
echo '<div style="background:#fff;border-radius:12px;padding:24px;max-width:540px;width:100%;max-height:90vh;overflow-y:auto">';
echo '<h2 style="margin:0 0 16px;font-size:18px;font-weight:700">Enviar Documento</h2>';
echo '<form method="post" enctype="multipart/form-data"><input type="hidden" name="send" value="1">';
echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px">';
echo '<div><label style="font-size:12px;font-weight:600">Tipo *</label><select name="recipient_type" id="rt" onchange="upd()" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;margin-top:4px"><option value="">Selecione</option><option value="professional">Profissional</option><option value="patient">Paciente</option></select></div>';
echo '<div><label style="font-size:12px;font-weight:600">Destinatario *</label><select name="recipient_id" id="rs" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;margin-top:4px"><option value="">Selecione tipo</option></select></div>';
echo '</div>';
echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px">';
echo '<div><label style="font-size:12px;font-weight:600">Operadora</label><select name="health_insurer_id" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;margin-top:4px"><option value="0">Nenhuma</option>';
foreach ($insurers as $i) { echo '<option value="'.(int)$i['id'].'">'.htmlspecialchars($i['name']).'</option>'; }
echo '</select></div>';
echo '<div><label style="font-size:12px;font-weight:600">Metodo *</label><select name="send_method" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;margin-top:4px"><option value="email">E-mail</option><option value="portal">Portal</option></select></div>';
echo '</div>';
echo '<div style="margin-bottom:14px"><label style="font-size:12px;font-weight:600">Arquivo *</label><input type="file" name="doc" required accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" style="width:100%;margin-top:4px"></div>';
echo '<div style="margin-bottom:16px"><label style="font-size:12px;font-weight:600">Observacao</label><input name="notes" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;margin-top:4px" placeholder="Motivo do envio..."></div>';
echo '<div style="display:flex;gap:10px"><button type="button" onclick="document.getElementById(\'modal\').style.display=\'none\'" class="btn" style="flex:1">Cancelar</button><button type="submit" class="btn btnPrimary" style="flex:1">Enviar</button></div>';
echo '</form></div></div>';
echo '<script>var P=[';
foreach ($profs as $i => $p) { if ($i > 0) echo ','; echo '['.(int)$p['id'].',"'.addslashes($p['name']).'"]'; }
echo '];var A=[';
foreach ($pats as $i => $p) { if ($i > 0) echo ','; echo '['.(int)$p['id'].',"'.addslashes($p['full_name']).'"]'; }
echo '];function upd(){var t=document.getElementById("rt").value,s=document.getElementById("rs");s.innerHTML="<option value=\\'\\'>Selecione...</option>";var l=t==="professional"?P:t==="patient"?A:[];for(var i=0;i<l.length;i++){var o=document.createElement("option");o.value=l[i][0];o.textContent=l[i][1];s.appendChild(o);}}</script>';
view_footer();
