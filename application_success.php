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
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');

        :root {
            --background: 216 33% 97%;
            --foreground: 210 36% 17%;
            --card: 0 0% 100%;
            --primary: 180 65% 46%;
            --primary-foreground: 0 0% 100%;
            --muted-foreground: 216 18% 61%;
            --border: 216 20% 90%;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
            background: hsl(var(--background));
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            max-width: 600px;
            width: 100%;
            background: hsl(var(--card));
            border-radius: 24px;
            padding: 60px 40px;
            text-align: center;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
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
            background: hsl(var(--primary));
            position: relative;
            animation: scaleIn 0.5s cubic-bezier(0.34, 1.56, 0.64, 1) 0.2s both;
            box-shadow: 0 10px 30px hsla(var(--primary), 0.3);
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
            stroke: hsl(var(--primary-foreground));
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


        h1 {
            font-size: 32px;
            font-weight: 800;
            color: hsl(var(--foreground));
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
            color: hsl(var(--foreground));
            margin-bottom: 12px;
            line-height: 1.6;
            animation: fadeIn 0.6s ease 1s both;
        }

        .message {
            font-size: 15px;
            color: hsl(var(--muted-foreground));
            margin-bottom: 32px;
            line-height: 1.8;
            animation: fadeIn 0.6s ease 1.2s both;
        }

        .info-box {
            background: hsl(var(--background));
            border: 1px solid hsl(var(--border));
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
            color: hsl(var(--foreground));
        }

        .info-item:last-child {
            margin-bottom: 0;
        }

        .info-icon {
            width: 24px;
            height: 24px;
            background: hsl(var(--primary));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: hsl(var(--primary-foreground));
            font-weight: bold;
            flex-shrink: 0;
        }

        .redirect-message {
            font-size: 14px;
            color: hsl(var(--muted-foreground));
            animation: fadeIn 0.6s ease 1.6s both;
            margin-bottom: 20px;
        }

        .countdown {
            font-weight: 600;
            color: hsl(var(--primary));
        }

        .spinner {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid hsl(var(--border));
            border-top-color: hsl(var(--primary));
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
            background: hsl(var(--primary));
            color: hsl(var(--primary-foreground));
            text-decoration: none;
            border-radius: 12px;
            font-weight: 600;
            font-size: 15px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px hsla(var(--primary), 0.3);
            animation: fadeIn 0.6s ease 1.8s both;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px hsla(var(--primary), 0.4);
            opacity: 0.9;
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
            Redirecionando para a página de login em <span class="countdown" id="countdown">10</span> segundos<span class="spinner"></span>
        </p>

        <a href="/login.php" class="btn">Ir para Login Agora</a>
    </div>

    <script>
        // Countdown timer
        let seconds = 10;
        const countdownEl = document.getElementById('countdown');
        
        const countdownInterval = setInterval(() => {
            seconds--;
            if (countdownEl) {
                countdownEl.textContent = seconds;
            }
            
            if (seconds <= 0) {
                clearInterval(countdownInterval);
                window.location.href = '/login.php';
            }
        }, 1000);
    </script>
</body>
</html>
