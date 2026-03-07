<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

$assignmentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($assignmentId === 0) {
    header('Location: /faturamento_list.php');
    exit;
}

// Buscar atendimento
$stmt = db()->prepare("
    SELECT 
        pa.*,
        p.full_name as patient_name,
        p.cpf as patient_cpf,
        p.phone_primary as patient_phone,
        u.name as professional_name,
        u.email as professional_email
    FROM patient_assignments pa
    LEFT JOIN patients p ON p.id = pa.patient_id
    LEFT JOIN users u ON u.id = pa.professional_user_id
    WHERE pa.id = ?
");
$stmt->execute([$assignmentId]);
$assignment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$assignment) {
    header('Location: /faturamento_list.php');
    exit;
}

// Buscar documentos
$docsStmt = db()->prepare("
    SELECT 
        bdr.*
    FROM billing_document_requirements bdr
    WHERE bdr.assignment_id = ?
    ORDER BY bdr.session_number ASC
");
$docsStmt->execute([$assignmentId]);
$documentRequirements = $docsStmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar arquivos
foreach ($documentRequirements as &$req) {
    $filesStmt = db()->prepare("
        SELECT * FROM billing_document_files
        WHERE requirement_id = ?
        ORDER BY document_type, created_at ASC
    ");
    $filesStmt->execute([$req['id']]);
    $req['files'] = $filesStmt->fetchAll(PDO::FETCH_ASSOC);
}
unset($req);

// Buscar fatura
$invoiceStmt = db()->prepare("SELECT * FROM billing_invoices WHERE assignment_id = ?");
$invoiceStmt->execute([$assignmentId]);
$invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);

$totalValue = (float)$assignment['payment_value'] * (int)$assignment['session_quantity'];

// Gerar HTML para exportação
header('Content-Type: text/html; charset=utf-8');

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documento de Faturamento - Atendimento #<?= (int)$assignment['id'] ?></title>
    <style>
        @media print {
            .no-print { display: none; }
            @page { margin: 1cm; }
        }
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            text-align: center;
            border-bottom: 3px solid #0284c7;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .header h1 {
            margin: 0;
            color: #0284c7;
        }
        .section {
            margin-bottom: 30px;
            page-break-inside: avoid;
        }
        .section h2 {
            background: #f0f9ff;
            padding: 10px;
            border-left: 4px solid #0284c7;
            margin-bottom: 15px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        table th, table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        table th {
            background: #f9fafb;
            font-weight: 600;
        }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        .info-item {
            padding: 10px;
            background: #f9fafb;
            border-radius: 6px;
        }
        .info-label {
            font-weight: 600;
            color: #667781;
            font-size: 12px;
            margin-bottom: 4px;
        }
        .info-value {
            font-size: 16px;
            color: #111827;
        }
        .gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        .gallery-item {
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            overflow: hidden;
            page-break-inside: avoid;
        }
        .gallery-item img {
            width: 100%;
            height: 200px;
            object-fit: cover;
        }
        .gallery-caption {
            padding: 8px;
            background: #f9fafb;
            font-size: 12px;
            text-align: center;
        }
        .total-box {
            background: #f0fdf4;
            border: 2px solid #10b981;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            margin: 20px 0;
        }
        .total-label {
            font-size: 14px;
            color: #065f46;
            margin-bottom: 8px;
        }
        .total-value {
            font-size: 32px;
            font-weight: 700;
            color: #065f46;
        }
        .btn-print {
            background: #0284c7;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 16px;
            margin: 20px 0;
        }
        .btn-print:hover {
            background: #0369a1;
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button class="btn-print" onclick="window.print()">🖨️ Imprimir / Salvar PDF</button>
    </div>

    <div class="header">
        <h1>Documento de Faturamento Consolidado</h1>
        <p>Atendimento #<?= (int)$assignment['id'] ?></p>
        <p style="color: #667781;">Gerado em <?= date('d/m/Y H:i') ?></p>
    </div>

    <div class="section">
        <h2>Informações do Paciente</h2>
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Nome Completo</div>
                <div class="info-value"><?= htmlspecialchars($assignment['patient_name']) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">CPF</div>
                <div class="info-value"><?= htmlspecialchars($assignment['patient_cpf'] ?? '-') ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Telefone</div>
                <div class="info-value"><?= htmlspecialchars($assignment['patient_phone'] ?? '-') ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Status</div>
                <div class="info-value"><?= htmlspecialchars($assignment['status']) ?></div>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>Informações do Profissional</h2>
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Nome</div>
                <div class="info-value"><?= htmlspecialchars($assignment['professional_name']) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">E-mail</div>
                <div class="info-value"><?= htmlspecialchars($assignment['professional_email']) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Especialidade</div>
                <div class="info-value"><?= htmlspecialchars($assignment['specialty'] ?? '-') ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Tipo de Serviço</div>
                <div class="info-value"><?= htmlspecialchars($assignment['service_type'] ?? '-') ?></div>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>Detalhes do Atendimento</h2>
        <table>
            <tr>
                <th>Quantidade de Sessões</th>
                <td><?= (int)$assignment['session_quantity'] ?></td>
            </tr>
            <tr>
                <th>Valor por Sessão</th>
                <td>R$ <?= number_format((float)$assignment['payment_value'], 2, ',', '.') ?></td>
            </tr>
            <tr>
                <th>Frequência</th>
                <td><?= htmlspecialchars($assignment['session_frequency'] ?? '-') ?></td>
            </tr>
            <tr>
                <th>Data de Criação</th>
                <td><?= date('d/m/Y H:i', strtotime($assignment['created_at'])) ?></td>
            </tr>
            <?php if ($assignment['approved_at']): ?>
            <tr>
                <th>Data de Aprovação</th>
                <td><?= date('d/m/Y H:i', strtotime($assignment['approved_at'])) ?></td>
            </tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="total-box">
        <div class="total-label">Valor Total do Atendimento</div>
        <div class="total-value">R$ <?= number_format($totalValue, 2, ',', '.') ?></div>
        <?php if ($invoice && $invoice['adjusted_value'] !== null): ?>
            <div style="margin-top: 10px; font-size: 14px; color: #065f46;">
                Valor Ajustado: R$ <?= number_format((float)$invoice['final_value'], 2, ',', '.') ?>
            </div>
        <?php endif; ?>
    </div>

    <?php
    // Separar arquivos por tipo
    $prodFiles = [];
    $fatFiles = [];
    foreach ($documentRequirements as $req) {
        foreach ($req['files'] as $file) {
            $fileData = $file;
            $fileData['session_number'] = $req['session_number'];
            if ($file['document_type'] === 'produtividade') {
                $prodFiles[] = $fileData;
            } else {
                $fatFiles[] = $fileData;
            }
        }
    }
    ?>

    <?php if (count($prodFiles) > 0): ?>
    <div class="section">
        <h2>📋 Fichas de Produtividade (<?= count($prodFiles) ?> arquivo<?= count($prodFiles) > 1 ? 's' : '' ?>)</h2>
        <div class="gallery">
            <?php foreach ($prodFiles as $file): ?>
            <div class="gallery-item">
                <img src="<?= htmlspecialchars($file['file_path']) ?>" alt="Produtividade">
                <div class="gallery-caption">Sessão <?= (int)$file['session_number'] ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (count($fatFiles) > 0): ?>
    <div class="section">
        <h2>💰 Fichas de Faturamento (<?= count($fatFiles) ?> arquivo<?= count($fatFiles) > 1 ? 's' : '' ?>)</h2>
        <div class="gallery">
            <?php foreach ($fatFiles as $file): ?>
            <div class="gallery-item">
                <img src="<?= htmlspecialchars($file['file_path']) ?>" alt="Faturamento">
                <div class="gallery-caption">Sessão <?= (int)$file['session_number'] ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="section">
        <h2>Resumo de Sessões</h2>
        <table>
            <thead>
                <tr>
                    <th>Sessão</th>
                    <th>Data</th>
                    <th>Status</th>
                    <th>Arquivos Produtividade</th>
                    <th>Arquivos Faturamento</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($documentRequirements as $req): ?>
                <tr>
                    <td>Sessão <?= (int)$req['session_number'] ?></td>
                    <td><?= $req['session_date'] ? date('d/m/Y', strtotime($req['session_date'])) : '-' ?></td>
                    <td><?= htmlspecialchars($req['status']) ?></td>
                    <td><?= count(array_filter($req['files'], fn($f) => $f['document_type'] === 'produtividade')) ?></td>
                    <td><?= count(array_filter($req['files'], fn($f) => $f['document_type'] === 'faturamento')) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div style="margin-top: 50px; padding-top: 20px; border-top: 2px solid #e5e7eb; text-align: center; color: #667781; font-size: 12px;">
        <p>Documento gerado automaticamente pelo sistema MultiLife Care</p>
        <p>Data de geração: <?= date('d/m/Y H:i:s') ?></p>
    </div>
</body>
</html>
