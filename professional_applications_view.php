<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('professional_applications.manage');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = db()->prepare('SELECT pa.*, u.name AS reviewed_by_name FROM professional_applications pa LEFT JOIN users u ON u.id = pa.reviewed_by_user_id WHERE pa.id = :id');
$stmt->execute(['id' => $id]);
$pa = $stmt->fetch();

if (!$pa) {
    flash_set('error', 'Candidatura não encontrada.');
    header('Location: /professional_applications_list.php');
    exit;
}

view_header('Candidatura #' . (string)$pa['id']);

// Dados do conselho para o botão de validação
$councilAbbr   = strtoupper(trim((string)($pa['council_abbr'] ?? '')));
$councilNumber = trim((string)($pa['council_number'] ?? ''));
$councilState  = strtoupper(trim((string)($pa['council_state'] ?? '')));
$hasCouncil    = $councilAbbr !== '' && $councilNumber !== '' && $councilState !== '';

// Resultado da última validação (se existir) — só exibe resultados conclusivos, não erros antigos
$lastValidation = null;
if (!empty($pa['council_validation_result'])) {
    $decoded = json_decode((string)$pa['council_validation_result'], true);
    // Não exibe resultados de ERRO no card persistido — o usuário deve revalidar
    if (is_array($decoded) && !empty($decoded['success'])) {
        $lastValidation = $decoded;
    }
}
$validationStatus = (string)($pa['council_validation_status'] ?? '');
$validatedAt      = (string)($pa['council_validated_at'] ?? '');

echo '<style>.pill{border:none !important;padding:6px 0 !important}</style>';

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-bottom:6px">Candidatura</div>';
echo '<div style="font-size:22px;font-weight:900">#' . (int)$pa['id'] . ' — ' . h((string)$pa['full_name']) . '</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">';
echo '<strong>Status:</strong> ' . h((string)$pa['status']) . ' &nbsp; <strong>E-mail:</strong> ' . h((string)$pa['email']) . ' &nbsp; <strong>Telefone:</strong> ' . h((string)$pa['phone']);
echo '</div>';
echo '</div>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/professional_applications_list.php">Voltar</a>';

// Botão de validação do conselho
if ($hasCouncil) {
    $btnLabel = 'Validar ' . h($councilAbbr);
    if ($validationStatus === 'VALID') {
        $btnLabel = '✓ Revalidar ' . h($councilAbbr);
    } elseif ($validationStatus === 'INVALID') {
        $btnLabel = '✗ Revalidar ' . h($councilAbbr);
    } elseif ($validationStatus === 'ERROR') {
        $btnLabel = '⚠ Revalidar ' . h($councilAbbr);
    }
    echo '<button class="btn" id="btnValidateCouncil" type="button"'
        . ' data-application-id="' . (int)$pa['id'] . '"'
        . ' data-council-abbr="' . h($councilAbbr) . '"'
        . ' data-registry-number="' . h($councilNumber) . '"'
        . ' data-council-state="' . h($councilState) . '"'
        . ' onclick="runCouncilValidation(this)">'
        . $btnLabel
        . '</button>';
}

$appStatus = (string)($pa['status'] ?? '');
if ($appStatus === 'pending' || $appStatus === 'need_more_info') {
    echo '<form method="post" action="/professional_applications_approve_post.php" style="display:inline">';
    echo '<input type="hidden" name="id" value="' . (int)$pa['id'] . '">';
    echo '<button class="btn btnPrimary" type="submit" onclick="return confirm(\'Aprovar e criar acesso?\')">Aprovar</button>';
    echo '</form>';

    echo '<a class="btn" href="/professional_applications_need_more_info.php?id=' . (int)$pa['id'] . '">Solicitar complemento</a>';

    echo '<a class="btn" href="/professional_applications_reject.php?id=' . (int)$pa['id'] . '">Reprovar</a>';
}

echo '</div>';
echo '</div>';

echo '</section>';

// Card de resultado da validação do conselho
if ($hasCouncil) {
    echo '<section class="card col12" id="councilValidationCard">';
    echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:10px">';
    echo '<div style="font-weight:900">Validação do Registro Profissional</div>';

    if ($validationStatus !== '' && $validationStatus !== 'ERROR') {
        $badgeColor = match($validationStatus) {
            'VALID'   => 'hsl(142 71% 45%)',
            'INVALID' => 'hsl(0 72% 51%)',
            'ERROR'   => 'hsl(38 92% 50%)',
            default   => 'hsl(var(--muted-foreground))',
        };
        $badgeLabel = match($validationStatus) {
            'VALID'   => '✓ Válido',
            'INVALID' => '✗ Inválido / Não encontrado',
            'ERROR'   => '⚠ Erro na consulta',
            default   => $validationStatus,
        };
        echo '<span style="background:' . $badgeColor . ';color:#fff;padding:4px 12px;border-radius:20px;font-size:13px;font-weight:700">'
            . h($badgeLabel) . '</span>';
    }
    echo '</div>';

    if ($lastValidation !== null) {
        echo '<div style="display:grid;gap:6px;font-size:14px">';
        $fields = [
            'Conselho'       => ($lastValidation['registry_type'] ?? '') . ' ' . ($lastValidation['registry_number'] ?? '') . '/' . ($lastValidation['state'] ?? ''),
            'Nome encontrado'=> $lastValidation['name'] ?? '-',
            'Situação'       => $lastValidation['status'] ?? '-',
            'Fonte'          => $lastValidation['source'] ?? '-',
            'Consultado em'  => $lastValidation['consulted_at'] ?? $validatedAt,
        ];
        if (!empty($lastValidation['error'])) {
            $fields['Detalhe do erro'] = $lastValidation['error'];
        }
        if (!empty($lastValidation['note'])) {
            $fields['Observação'] = $lastValidation['note'];
        }
        if (!empty($lastValidation['has_captcha']) && $lastValidation['has_captcha']) {
            $fields['Proteção'] = 'reCAPTCHA obrigatório — consulta manual necessária';
        }
        if (!empty($lastValidation['has_waf']) && $lastValidation['has_waf']) {
            $fields['Proteção'] = ($fields['Proteção'] ?? '') . ' WAF bloqueou requisição automática';
        }
        if (!empty($lastValidation['has_cloudflare']) && $lastValidation['has_cloudflare']) {
            $fields['Proteção'] = 'Cloudflare detectado — consulta manual necessária';
        }
        foreach ($fields as $label => $value) {
            $v = trim((string)$value);
            echo '<div class="pill" style="display:block"><strong>' . h($label) . ':</strong> ' . h($v !== '' ? $v : '-') . '</div>';
        }
        // Link para consulta manual (quando disponível)
        if (!empty($lastValidation['manual_url'])) {
            echo '<div class="pill" style="display:block"><strong>Consulta manual:</strong> '
                . '<a href="' . h((string)$lastValidation['manual_url']) . '" target="_blank" rel="noopener">'
                . h((string)$lastValidation['manual_url']) . '</a></div>';
        }
        echo '</div>';
    } else {
        echo '<div style="color:hsl(var(--muted-foreground));font-size:14px">Nenhuma validação realizada ainda. Clique em "Validar ' . h($councilAbbr) . '" para consultar o portal oficial.</div>';
    }

    // Área de resultado em tempo real (preenchida via JS)
    echo '<div id="councilValidationResult" style="margin-top:12px"></div>';
    echo '</section>';
}

// JavaScript para o botão de validação
echo <<<'JS'
<script>
function runCouncilValidation(btn) {
    var applicationId  = btn.dataset.applicationId;
    var councilAbbr    = btn.dataset.councilAbbr;
    var registryNumber = btn.dataset.registryNumber;
    var councilState   = btn.dataset.councilState;

    var resultDiv = document.getElementById('councilValidationResult');
    resultDiv.innerHTML = '<div style="padding:10px;color:hsl(var(--muted-foreground));font-size:14px">⏳ Consultando portal ' + councilAbbr + '... aguarde.</div>';
    btn.disabled = true;
    btn.textContent = 'Consultando...';

    fetch('/api/council_validate_post.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            application_id:  parseInt(applicationId),
            council_abbr:    councilAbbr,
            registry_number: registryNumber,
            council_state:   councilState
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        btn.disabled = false;
        btn.textContent = 'Revalidar ' + councilAbbr;

        var html = '';
        if (data.success && data.valid) {
            html += '<div style="background:hsl(142 71% 95%);border:1px solid hsl(142 71% 45%);border-radius:8px;padding:14px;font-size:14px">';
            html += '<div style="font-weight:900;color:hsl(142 71% 35%);margin-bottom:8px">✓ Registro válido</div>';
            html += '<div><strong>Nome:</strong> ' + (data.name || '-') + '</div>';
            html += '<div><strong>Situação:</strong> ' + (data.status || '-') + '</div>';
            html += '<div><strong>Fonte:</strong> ' + (data.source || '-') + '</div>';
            html += '<div><strong>Consultado em:</strong> ' + (data.consulted_at || '-') + '</div>';
            if (data.specialty) { html += '<div><strong>Especialidade:</strong> ' + data.specialty + '</div>'; }
            html += '</div>';
        } else if (data.success && !data.valid) {
            html += '<div style="background:hsl(0 72% 97%);border:1px solid hsl(0 72% 51%);border-radius:8px;padding:14px;font-size:14px">';
            html += '<div style="font-weight:900;color:hsl(0 72% 40%);margin-bottom:8px">✗ Registro não encontrado</div>';
            html += '<div>O número ' + registryNumber + '/' + councilState + ' não foi localizado no portal ' + councilAbbr + '.</div>';
            html += '<div style="margin-top:6px"><strong>Fonte:</strong> ' + (data.source || '-') + '</div>';
            html += '</div>';
        } else {
            html += '<div style="background:hsl(38 92% 97%);border:1px solid hsl(38 92% 50%);border-radius:8px;padding:14px;font-size:14px">';
            html += '<div style="font-weight:900;color:hsl(38 92% 35%);margin-bottom:8px">⚠ Consulta automática não disponível</div>';
            if (data.error) { html += '<div style="margin-bottom:8px">' + data.error + '</div>'; }
            if (data.has_captcha)    { html += '<div>🔒 <strong>reCAPTCHA obrigatório</strong> no portal.</div>'; }
            if (data.has_waf)        { html += '<div>🔒 <strong>WAF (firewall)</strong> bloqueou a requisição automática.</div>'; }
            if (data.has_cloudflare) { html += '<div>🔒 <strong>Cloudflare</strong> detectado no portal.</div>'; }
            if (data.has_auth)       { html += '<div>🔒 <strong>Autenticação</strong> obrigatória no portal.</div>'; }
            if (data.has_ip_block)   { html += '<div>🔒 <strong>Bloqueio por IP</strong> detectado.</div>'; }
            if (data.requires_cpf)   { html += '<div style="margin-top:6px">ℹ️ Este portal exige <strong>CPF</strong> do profissional (não o número de inscrição).</div>'; }
            if (data.note)           { html += '<div style="margin-top:4px;color:#666">' + data.note + '</div>'; }
            if (data.manual_url) {
                html += '<div style="margin-top:10px"><a href="' + data.manual_url + '" target="_blank" style="color:hsl(38 92% 35%);font-weight:700;text-decoration:underline">→ Abrir portal para consulta manual</a></div>';
            }
            html += '</div>';
        }

        resultDiv.innerHTML = html;

        // Recarrega a página após 3s apenas se o resultado foi positivo (para atualizar o badge)
        // Para erros/captcha, não recarrega — o resultado inline já é suficiente
        if (data.success) {
            setTimeout(function() { window.location.reload(); }, 3000);
        }
    })
    .catch(function(err) {
        btn.disabled = false;
        btn.textContent = 'Revalidar ' + councilAbbr;
        resultDiv.innerHTML = '<div style="background:hsl(0 72% 97%);border:1px solid hsl(0 72% 51%);border-radius:8px;padding:14px;font-size:14px">Erro de comunicação: ' + err.message + '</div>';
    });
}
</script>
JS;

$sections = [
    'Identificação' => [
        'Estado civil' => $pa['marital_status'] ?? '',
        'Sexo' => $pa['sex'] ?? '',
        'Religião' => $pa['religion'] ?? '',
        'Naturalidade' => $pa['birthplace'] ?? '',
        'Nacionalidade' => $pa['nationality'] ?? '',
        'Escolaridade' => $pa['education_level'] ?? '',
        'Cidades de atuação' => $pa['cities_of_operation'] ?? '',
    ],
    'Endereço' => [
        'Logradouro' => $pa['address_street'] ?? '',
        'Número' => $pa['address_number'] ?? '',
        'Complemento' => $pa['address_complement'] ?? '',
        'Bairro' => $pa['address_neighborhood'] ?? '',
        'Cidade' => $pa['address_city'] ?? '',
        'UF' => $pa['address_state'] ?? '',
        'CEP' => $pa['address_zip'] ?? '',
    ],
    'Documentos' => [
        'RG' => $pa['rg'] ?? '',
        'Conselho' => trim((string)($pa['council_abbr'] ?? '') . ' ' . (string)($pa['council_number'] ?? '') . ((string)($pa['council_state'] ?? '') !== '' ? '/' . (string)($pa['council_state'] ?? '') : '')),
    ],
    'Dados bancários' => [
        'Banco' => $pa['bank_name'] ?? '',
        'Agência' => $pa['bank_agency'] ?? '',
        'Conta' => $pa['bank_account'] ?? '',
        'Tipo' => $pa['bank_account_type'] ?? '',
        'Titular' => $pa['bank_account_holder'] ?? '',
        'CPF titular' => $pa['bank_account_holder_cpf'] ?? '',
        'PIX' => $pa['pix_key'] ?? '',
        'Titular PIX' => $pa['pix_holder'] ?? '',
    ],
    'Informações técnicas' => [
        'Experiência home care' => $pa['home_care_experience'] ?? '',
        'Tempo de atuação' => $pa['years_of_experience'] ?? '',
        'Especializações/Pós' => $pa['specializations'] ?? '',
    ],
    'Revisão (Admin)' => [
        'Nota' => $pa['admin_note'] ?? '',
        'Revisado por' => $pa['reviewed_by_name'] ?? '',
        'Revisado em' => $pa['reviewed_at'] ?? '',
        'Usuário criado' => $pa['created_user_id'] ?? '',
    ],
];

foreach ($sections as $title => $fields) {
    echo '<section class="card col12">';
    echo '<div style="font-weight:900;margin-bottom:8px">' . h($title) . '</div>';
    echo '<div style="display:grid;gap:8px">';
    foreach ($fields as $label => $value) {
        $v = trim((string)$value);
        echo '<div class="pill" style="display:block">';
        echo '<strong>' . h($label) . ':</strong> ' . h($v !== '' ? $v : '-');
        echo '</div>';
    }
    echo '</div>';
    echo '</section>';
}

echo '</div>';

// --- Respostas do candidato (complemento) ---
$repliesStmt = db()->prepare('SELECT * FROM professional_application_replies WHERE application_id = :id ORDER BY created_at ASC');
$repliesStmt->execute(['id' => $id]);
$replies = $repliesStmt->fetchAll();

if (count($replies) > 0 || (string)($pa['status'] ?? '') === 'need_more_info') {
    echo '<div class="grid" style="margin-top:20px">';
    echo '<section class="card col12">';
    echo '<div style="font-size:18px;font-weight:900;margin-bottom:16px">📩 Respostas do Candidato (' . count($replies) . ')</div>';
    
    if (count($replies) === 0) {
        echo '<div style="padding:20px;text-align:center;color:hsl(var(--muted-foreground))">';
        echo 'Aguardando resposta do candidato...';
        if (!empty($pa['reply_token'])) {
            $publicUrl = trim((string)admin_setting_get('app.public_base_url', ''));
            if ($publicUrl === '') $publicUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $replyLink = rtrim($publicUrl, '/') . '/application_reply.php?token=' . urlencode((string)$pa['reply_token']);
            echo '<br><small style="margin-top:8px;display:inline-block">Link enviado: <a href="' . h($replyLink) . '" target="_blank" style="color:hsl(var(--primary))">' . h($replyLink) . '</a></small>';
        }
        echo '</div>';
    } else {
        echo '<div style="display:grid;gap:12px">';
        foreach ($replies as $reply) {
            $rType = (string)$reply['reply_type'];
            $rDate = date('d/m/Y H:i', strtotime((string)$reply['created_at']));
            $badgeColor = $rType === 'text' ? '#3b82f6' : ($rType === 'image' ? '#10b981' : '#f59e0b');
            $badgeLabel = $rType === 'text' ? '📝 Texto' : ($rType === 'image' ? '📷 Imagem' : '📎 Arquivo');
            
            echo '<div style="border:1px solid hsl(var(--border));border-radius:8px;padding:12px">';
            echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">';
            echo '<span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;background:' . $badgeColor . '20;color:' . $badgeColor . '">' . $badgeLabel . '</span>';
            echo '<span style="font-size:12px;color:hsl(var(--muted-foreground))">' . $rDate . '</span>';
            echo '</div>';
            
            if ($rType === 'text') {
                echo '<div style="font-size:14px;line-height:1.6">' . nl2br(h((string)$reply['content'])) . '</div>';
            } elseif ($rType === 'image') {
                echo '<a href="' . h((string)$reply['file_path']) . '" target="_blank"><img src="' . h((string)$reply['file_path']) . '" style="max-width:300px;border-radius:8px;margin-top:4px" alt="Imagem"></a>';
            } else {
                echo '<a href="' . h((string)$reply['file_path']) . '" target="_blank" style="color:hsl(var(--primary));font-weight:600">📎 ' . h((string)$reply['file_name']) . '</a>';
                echo ' <span style="font-size:12px;color:hsl(var(--muted-foreground))">(' . number_format(((int)$reply['file_size']) / 1024, 0) . ' KB)</span>';
            }
            
            echo '</div>';
        }
        echo '</div>';
    }
    
    echo '</section>';
    echo '</div>';
}

view_footer();
