<?php
/**
 * Configuração dos motivos de encerramento de atendimento (item 5).
 * CRUD simples: listar, criar, ativar/desativar, excluir (não-sistema).
 */

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

$db = db();

// Garantir tabela
$db->exec("CREATE TABLE IF NOT EXISTS treatment_end_reasons (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(120) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_end_reason_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Ações POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'create') {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name !== '') {
            $slug = preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($name));
            $slug = trim($slug, '_') . '_' . substr(md5($name . microtime()), 0, 4);
            $stmt = $db->prepare("INSERT INTO treatment_end_reasons (name, slug, is_active, is_system) VALUES (?, ?, 1, 0)");
            $stmt->execute([$name, $slug]);
            flash_set('success', 'Motivo adicionado.');
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE treatment_end_reasons SET is_active = 1 - is_active WHERE id = ?")->execute([$id]);
        flash_set('success', 'Motivo atualizado.');
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM treatment_end_reasons WHERE id = ? AND is_system = 0")->execute([$id]);
        flash_set('success', 'Motivo removido.');
    }
    header('Location: /treatment_end_reasons.php');
    exit;
}

$reasons = $db->query("SELECT * FROM treatment_end_reasons ORDER BY is_system DESC, name ASC")->fetchAll(PDO::FETCH_ASSOC);

view_header('Motivos de Encerramento');

echo '<div class="grid"><section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div><div style="font-size:22px;font-weight:900">Motivos de Encerramento</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Motivos usados ao finalizar um atendimento.</div></div>';
echo '<a class="btn" href="/admin_settings.php">Voltar</a>';
echo '</div>';

// Form de criação
echo '<form method="post" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<input type="hidden" name="action" value="create">';
echo '<input name="name" placeholder="Novo motivo (ex: Mudança de cidade)" required style="flex:1;min-width:240px">';
echo '<button class="btn btnPrimary" type="submit">Adicionar</button>';
echo '</form>';

echo '<div style="overflow:auto;margin-top:16px"><table>';
echo '<thead><tr><th>Motivo</th><th>Tipo</th><th>Status</th><th style="text-align:right">Ações</th></tr></thead><tbody>';
foreach ($reasons as $r) {
    echo '<tr>';
    echo '<td style="font-weight:600">' . h((string)$r['name']) . '</td>';
    echo '<td>' . ($r['is_system'] ? '<span class="badge badgeInfo">Sistema</span>' : 'Personalizado') . '</td>';
    echo '<td>' . ($r['is_active'] ? '<span class="badge badgeSuccess">Ativo</span>' : '<span class="badge badgeDanger">Inativo</span>') . '</td>';
    echo '<td style="text-align:right">';
    echo '<form method="post" style="display:inline"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><button class="btn" type="submit">' . ($r['is_active'] ? 'Desativar' : 'Ativar') . '</button></form> ';
    if (!$r['is_system']) {
        echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Excluir este motivo?\')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><button class="btn" type="submit">Excluir</button></form>';
    }
    echo '</td></tr>';
}
echo '</tbody></table></div>';
echo '</section></div>';

view_footer();
