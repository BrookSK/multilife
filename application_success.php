<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

// Página pública - não requer login
// Redireciona para login após 5 segundos

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Candidatura Enviada com Sucesso!</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            overflow: hidden;
        }

        .container {
            max-width: 600px;
            width: 100%;
            background: white;
            border-radius: 24px;
            padding: 60px 40px;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: slideUp 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
            position: relative;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .checkmark-container {
            width: 120px;
            height: 120px;
            margin: 0 auto 30px;
            position: relative;
        }

        .checkmark-circle {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            position: relative;
            animation: scaleIn 0.5s cubic-bezier(0.34, 1.56, 0.64, 1) 0.2s both;
            box-shadow: 0 10px 30px rgba(17, 153, 142, 0.4);
        }

        @keyframes scaleIn {
            from {
                transform: scale(0);
            }
            to {
                transform: scale(1);
            }
        }

        .checkmark {
            width: 60px;
            height: 60px;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }

        .checkmark-path {
            stroke: white;
            stroke-width: 6;
            stroke-linecap: round;
            stroke-linejoin: round;
            fill: none;
            stroke-dasharray: 100;
            stroke-dashoffset: 100;
            animation: drawCheck 0.6s cubic-bezier(0.65, 0, 0.45, 1) 0.6s forwards;
        }

        @keyframes drawCheck {
            to {
                stroke-dashoffset: 0;
            }
        }

        .confetti {
            position: absolute;
            width: 10px;
            height: 10px;
            background: #f0f;
            animation: confettiFall 3s linear forwards;
        }

        @keyframes confettiFall {
            to {
                transform: translateY(100vh) rotate(360deg);
                opacity: 0;
            }
        }

        h1 {
            font-size: 32px;
            font-weight: 800;
            color: #1a202c;
            margin-bottom: 16px;
            animation: fadeIn 0.6s ease 0.8s both;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .subtitle {
            font-size: 18px;
            color: #4a5568;
            margin-bottom: 12px;
            line-height: 1.6;
            animation: fadeIn 0.6s ease 1s both;
        }

        .message {
            font-size: 15px;
            color: #718096;
            margin-bottom: 32px;
            line-height: 1.8;
            animation: fadeIn 0.6s ease 1.2s both;
        }

        .info-box {
            background: linear-gradient(135deg, #f6f8fb 0%, #e9ecef 100%);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 32px;
            animation: fadeIn 0.6s ease 1.4s both;
        }

        .info-item {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-bottom: 12px;
            font-size: 15px;
            color: #2d3748;
        }

        .info-item:last-child {
            margin-bottom: 0;
        }

        .info-icon {
            width: 24px;
            height: 24px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            flex-shrink: 0;
        }

        .redirect-message {
            font-size: 14px;
            color: #a0aec0;
            animation: fadeIn 0.6s ease 1.6s both;
        }

        .spinner {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid #e2e8f0;
            border-top-color: #667eea;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin-left: 8px;
            vertical-align: middle;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .btn {
            display: inline-block;
            padding: 14px 32px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            border-radius: 12px;
            font-weight: 600;
            font-size: 15px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
            animation: fadeIn 0.6s ease 1.8s both;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.5);
        }

        @media (max-width: 640px) {
            .container {
                padding: 40px 24px;
            }

            h1 {
                font-size: 26px;
            }

            .subtitle {
                font-size: 16px;
            }

            .checkmark-container {
                width: 100px;
                height: 100px;
            }

            .checkmark-circle {
                width: 100px;
                height: 100px;
            }

            .checkmark {
                width: 50px;
                height: 50px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="checkmark-container">
            <div class="checkmark-circle">
                <svg class="checkmark" viewBox="0 0 52 52">
                    <path class="checkmark-path" d="M14 27l7.5 7.5L38 18"/>
                </svg>
            </div>
        </div>

        <h1>Candidatura Enviada com Sucesso!</h1>
        
        <p class="subtitle">Sua candidatura foi recebida e está em análise.</p>
        
        <p class="message">
            Nossa equipe irá avaliar suas informações e documentos. 
            Em breve você receberá um retorno sobre o status da sua candidatura.
        </p>

        <div class="info-box">
            <div class="info-item">
                <div class="info-icon">✓</div>
                <span>Dados pessoais e profissionais registrados</span>
            </div>
            <div class="info-item">
                <div class="info-icon">✓</div>
                <span>Documentos anexados com sucesso</span>
            </div>
            <div class="info-item">
                <div class="info-icon">✓</div>
                <span>Notificação enviada para a equipe</span>
            </div>
        </div>

        <p class="redirect-message">
            Redirecionando para a página de login<span class="spinner"></span>
        </p>

        <a href="/login.php" class="btn">Ir para Login Agora</a>
    </div>

    <script>
        // Confetti animation
        function createConfetti() {
            const colors = ['#667eea', '#764ba2', '#11998e', '#38ef7d', '#f093fb', '#f5576c'];
            for (let i = 0; i < 50; i++) {
                const confetti = document.createElement('div');
                confetti.className = 'confetti';
                confetti.style.left = Math.random() * 100 + 'vw';
                confetti.style.background = colors[Math.floor(Math.random() * colors.length)];
                confetti.style.animationDelay = Math.random() * 0.5 + 's';
                confetti.style.animationDuration = (Math.random() * 2 + 2) + 's';
                document.body.appendChild(confetti);
                
                setTimeout(() => confetti.remove(), 3000);
            }
        }

        // Trigger confetti after checkmark animation
        setTimeout(createConfetti, 800);

        // Auto redirect after 5 seconds
        setTimeout(() => {
            window.location.href = '/login.php';
        }, 5000);
    </script>
</body>
</html>
