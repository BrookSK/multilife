<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

view_header('Acesso negado');

echo '<div class="card">';
echo '<div style="font-size:22px;font-weight:900;margin-bottom:6px">Acesso negado</div>';
echo '<div style="color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Você não tem permissão para acessar esta área.</div>';
echo '<div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '<a class="btn" href="/logout.php">Sair</a>';
echo '</div>';
echo '</div>';

view_footer();
