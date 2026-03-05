<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('openai.manage');

$modelDefault = admin_setting_get('openai.model', 'gpt-4o-mini') ?? 'gpt-4o-mini';

view_header('OpenAI Console');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">OpenAI (ChatGPT) — Console</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Teste do endpoint <code>/v1/chat/completions</code>. Toda chamada gera log em Logs TI (provider=openai).</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/admin_integrations.php">Credenciais</a>';
echo '<a class="btn" href="/tech_logs_list.php?provider=openai">Logs TI</a>';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

echo '<section class="card col12">';
echo '<form method="post" action="/admin_openai_console_post.php" style="display:grid;gap:12px">';

echo '<div style="font-weight:900">Modo 1: Chat</div>';

echo '<div class="grid">';
echo '<div class="col6"><label>Model<input name="model" value="' . h($modelDefault) . '"></label></div>';
echo '<div class="col6"><label>Temperature<input type="number" step="0.1" min="0" max="2" name="temperature" value="0.2"></label></div>';
echo '</div>';

echo '<label>System prompt<textarea name="system" rows="3">Você é um assistente que responde em português do Brasil.</textarea></label>';

echo '<label>User message<textarea name="user" rows="5" placeholder="Digite sua mensagem"></textarea></label>';


echo '<div style="font-weight:900;margin-top:12px">Modo 2: Extrair dados de e-mail → JSON</div>';

echo '<label>Conteúdo do e-mail<textarea name="email_text" rows="8" placeholder="Cole aqui o e-mail bruto"></textarea></label>';

echo '<label class="pill" style="display:flex;align-items:center;gap:10px;padding:12px">';
echo '<input type="checkbox" name="force_json" value="1" checked> Forçar saída em JSON (o sistema tentará parsear)';
echo '</label>';

echo '<label>Schema JSON esperado (opcional)<textarea name="json_schema" rows="5" placeholder="{\n  \"patient_name\": \"string\",\n  \"patient_phone\": \"string\",\n  \"specialty\": \"string\",\n  \"city\": \"string\",\n  \"notes\": \"string\"\n}"></textarea></label>';


echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end">';
echo '<button class="btn btnPrimary" type="submit">Executar</button>';
echo '</div>';

echo '</form>';
echo '</section>';

echo '</div>';

view_footer();
