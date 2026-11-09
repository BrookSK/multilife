<?php
/**
 * Página inicial dos testes pré-apresentação: /testes/stress/
 * Tudo pelo navegador, logado como admin. Nada aqui envia mensagens ou altera dados.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

auth_require_login();
if (!rbac_user_has_role((int)auth_user_id(), 'admin')) {
    http_response_code(403);
    exit('Somente admin.');
}

$doc = (string)($_GET['doc'] ?? '');
$docs = ['roteiro' => 'ROTEIRO_TESTES.md', 'relatorio' => 'RELATORIO.md'];

view_header('Testes do sistema');
echo '<div style="max-width:980px">';

if (isset($docs[$doc])) {
    echo '<p><a href="/testes/stress/">← Voltar aos testes</a></p>';
    echo '<div class="card mdDoc" style="padding:20px">' . markdown_lite((string)file_get_contents(__DIR__ . '/' . $docs[$doc])) . '</div>';
} else {
    echo '<h1 style="margin:0 0 6px">Testes antes da apresentação</h1>';
    echo '<p style="color:#667781;margin:0 0 18px">Faça na ordem. Nenhum destes testes envia WhatsApp ou e-mail, nem altera dados.</p>';
    $items = [
        ['1. Diagnóstico geral', '/testes/stress/diagnostico.php?wa_rounds=10',
            'Confere banco, WhatsApps (consulta cada um 10 vezes para pegar loop ou lentidão), o que cada pessoa vê no chat, profissionais por card, captações presas e o login do e-mail. Leva 1 a 2 minutos. Leia o RESUMO no final.'],
        ['2. Teste de carga', '/testes/stress/carga.php',
            'Simula vários usuários abrindo as telas ao mesmo tempo e mostra o tempo de cada tela. Comece com 5 usuários.'],
        ['3. Roteiro de testes manuais', '/testes/stress/?doc=roteiro',
            'Checklist passo a passo do fluxo inteiro: WhatsApp, captação, chat, autorização até o financeiro, e-mail e perfis.'],
        ['Relatório do que já foi corrigido', '/testes/stress/?doc=relatorio', 'Bugs encontrados e corrigidos na varredura do código.'],
    ];
    foreach ($items as [$title, $href, $desc]) {
        echo '<a href="' . h($href) . '" class="card" style="display:block;padding:16px;margin-bottom:12px;text-decoration:none;color:inherit">';
        echo '<div style="font-weight:700;font-size:16px;margin-bottom:4px">' . h($title) . ' →</div>';
        echo '<div style="color:#667781;font-size:14px">' . h($desc) . '</div>';
        echo '</a>';
    }
}

echo '</div>';
view_footer();
