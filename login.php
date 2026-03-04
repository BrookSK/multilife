<?php
// Tela apenas (sem processamento). Ajuste o action quando você criar o endpoint.
$error = isset($_GET['error']) ? (string)$_GET['error'] : '';
$email = isset($_GET['email']) ? (string)$_GET['email'] : '';

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login</title>
    <style>
        :root {
            --bg1: #0b1020;
            --bg2: #111a33;
            --card: rgba(255, 255, 255, 0.08);
            --cardBorder: rgba(255, 255, 255, 0.14);
            --text: #eaf0ff;
            --muted: rgba(234, 240, 255, 0.72);
            --primary: #6d5efc;
            --primary2: #4fd1c5;
            --danger: #ff5b7a;
            --shadow: 0 20px 60px rgba(0,0,0,0.45);
            --radius: 18px;
        }

        * { box-sizing: border-box; }

        html, body {
            height: 100%;
        }

        body {
            margin: 0;
            font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, "Apple Color Emoji", "Segoe UI Emoji";
            color: var(--text);
            background:
                radial-gradient(900px 500px at 12% 18%, rgba(109, 94, 252, 0.35), transparent 55%),
                radial-gradient(700px 500px at 88% 20%, rgba(79, 209, 197, 0.26), transparent 55%),
                radial-gradient(900px 700px at 60% 110%, rgba(255, 91, 122, 0.18), transparent 60%),
                linear-gradient(180deg, var(--bg1), var(--bg2));
            display: grid;
            place-items: center;
            padding: 28px 16px;
        }

        .wrap {
            width: 100%;
            max-width: 980px;
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 22px;
            align-items: stretch;
        }

        .brand {
            padding: 34px;
            border-radius: var(--radius);
            background: linear-gradient(135deg, rgba(109, 94, 252, 0.18), rgba(79, 209, 197, 0.08));
            border: 1px solid rgba(255,255,255,0.10);
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }

        .brand::before {
            content: "";
            position: absolute;
            inset: -2px;
            background:
                radial-gradient(500px 300px at 20% 20%, rgba(109, 94, 252, 0.35), transparent 60%),
                radial-gradient(420px 260px at 85% 35%, rgba(79, 209, 197, 0.28), transparent 60%);
            filter: blur(6px);
            opacity: 0.9;
            pointer-events: none;
        }

        .brand > * {
            position: relative;
        }

        .logo {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            font-weight: 700;
            letter-spacing: 0.2px;
            margin-bottom: 18px;
        }

        .mark {
            width: 44px;
            height: 44px;
            border-radius: 14px;
            background: linear-gradient(135deg, rgba(109, 94, 252, 0.95), rgba(79, 209, 197, 0.9));
            box-shadow: 0 18px 40px rgba(109, 94, 252, 0.25);
            display: grid;
            place-items: center;
        }

        .mark svg {
            width: 22px;
            height: 22px;
            color: white;
        }

        .brand h1 {
            margin: 10px 0 10px;
            font-size: 36px;
            line-height: 1.1;
        }

        .brand p {
            margin: 0;
            color: var(--muted);
            font-size: 15px;
            line-height: 1.6;
            max-width: 55ch;
        }

        .tips {
            margin-top: 24px;
            display: grid;
            gap: 12px;
        }

        .tip {
            display: flex;
            gap: 12px;
            padding: 12px 14px;
            border-radius: 14px;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.10);
        }

        .tip strong {
            display: block;
            font-size: 13px;
            margin-bottom: 4px;
        }

        .tip span {
            display: block;
            font-size: 13px;
            color: var(--muted);
            line-height: 1.45;
        }

        .card {
            border-radius: var(--radius);
            background: var(--card);
            border: 1px solid var(--cardBorder);
            box-shadow: var(--shadow);
            padding: 26px;
            backdrop-filter: blur(10px);
        }

        .card h2 {
            margin: 0 0 6px;
            font-size: 22px;
        }

        .card .subtitle {
            margin: 0 0 18px;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.5;
        }

        .alert {
            margin: 0 0 14px;
            padding: 12px 12px;
            border-radius: 14px;
            background: rgba(255, 91, 122, 0.14);
            border: 1px solid rgba(255, 91, 122, 0.35);
            color: rgba(255, 235, 241, 0.95);
            font-size: 13px;
            line-height: 1.4;
        }

        form {
            display: grid;
            gap: 12px;
        }

        label {
            display: grid;
            gap: 7px;
            font-size: 13px;
            color: rgba(234, 240, 255, 0.85);
        }

        .input {
            position: relative;
        }

        input[type="email"],
        input[type="password"] {
            width: 100%;
            border-radius: 14px;
            border: 1px solid rgba(255,255,255,0.18);
            background: rgba(10, 14, 28, 0.55);
            color: var(--text);
            padding: 12px 12px;
            outline: none;
            transition: border-color .15s ease, box-shadow .15s ease;
            font-size: 14px;
        }

        input::placeholder {
            color: rgba(234, 240, 255, 0.45);
        }

        input:focus {
            border-color: rgba(109, 94, 252, 0.75);
            box-shadow: 0 0 0 4px rgba(109, 94, 252, 0.18);
        }

        .row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-top: 2px;
        }

        .check {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: rgba(234, 240, 255, 0.82);
            user-select: none;
        }

        .check input {
            width: 16px;
            height: 16px;
        }

        a {
            color: rgba(234, 240, 255, 0.85);
            text-decoration: none;
            font-size: 13px;
        }

        a:hover {
            text-decoration: underline;
        }

        .btn {
            margin-top: 6px;
            display: inline-flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            width: 100%;
            padding: 12px 14px;
            border-radius: 14px;
            border: none;
            cursor: pointer;
            font-weight: 700;
            color: white;
            background: linear-gradient(135deg, rgba(109, 94, 252, 0.95), rgba(79, 209, 197, 0.9));
            box-shadow: 0 18px 42px rgba(0,0,0,0.35);
            transition: transform .06s ease, filter .15s ease;
        }

        .btn:active {
            transform: translateY(1px);
        }

        .foot {
            margin-top: 14px;
            font-size: 13px;
            color: var(--muted);
            text-align: center;
        }

        .foot a { color: rgba(234, 240, 255, 0.92); }

        @media (max-width: 880px) {
            .wrap {
                grid-template-columns: 1fr;
                max-width: 520px;
            }
            .brand {
                padding: 26px;
            }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <section class="brand" aria-label="Boas-vindas">
            <div class="logo">
                <div class="mark" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 2l8.5 5v10L12 22 3.5 17V7L12 2Z" stroke="currentColor" stroke-width="2"/>
                        <path d="M7.5 9.5L12 12l4.5-2.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </div>
                <div>
                    <div style="font-size:14px; opacity:.95;">Multilife</div>
                    <div style="font-size:12px; color: var(--muted);">Acesso ao painel</div>
                </div>
            </div>

            <h1>Faça login para continuar</h1>
            <p>
                Entre com suas credenciais para acessar o sistema.
                Se você ainda não tem acesso, fale com o administrador.
            </p>

            <div class="tips">
                <div class="tip">
                    <div style="width:12px; height:12px; margin-top:4px; border-radius:999px; background: rgba(79,209,197,.9);"></div>
                    <div>
                        <strong>Segurança</strong>
                        <span>Use uma senha forte e não compartilhe suas credenciais.</span>
                    </div>
                </div>
                <div class="tip">
                    <div style="width:12px; height:12px; margin-top:4px; border-radius:999px; background: rgba(109,94,252,.95);"></div>
                    <div>
                        <strong>Acesso rápido</strong>
                        <span>Marque “Lembrar-me” se estiver em um computador confiável.</span>
                    </div>
                </div>
            </div>
        </section>

        <main class="card" aria-label="Formulário de login">
            <h2>Login</h2>
            <p class="subtitle">Informe seu e-mail e senha.</p>

            <?php if ($error !== ''): ?>
                <div class="alert" role="alert"><?= h($error) ?></div>
            <?php endif; ?>

            <form method="post" action="login_post.php" autocomplete="on">
                <label>
                    E-mail
                    <span class="input">
                        <input
                            type="email"
                            name="email"
                            placeholder="seuemail@empresa.com"
                            value="<?= h($email) ?>"
                            required
                            autofocus
                            inputmode="email"
                            autocomplete="email"
                        >
                    </span>
                </label>

                <label>
                    Senha
                    <span class="input">
                        <input
                            type="password"
                            name="password"
                            placeholder="••••••••"
                            required
                            autocomplete="current-password"
                        >
                    </span>
                </label>

                <div class="row">
                    <label class="check">
                        <input type="checkbox" name="remember" value="1">
                        Lembrar-me
                    </label>
                    <a href="#" aria-disabled="true" onclick="return false;">Esqueci minha senha</a>
                </div>

                <button class="btn" type="submit">
                    Entrar
                </button>

                <div class="foot">
                    Não tem acesso? <a href="#" aria-disabled="true" onclick="return false;">Solicitar cadastro</a>
                </div>
            </form>
        </main>
    </div>
</body>
</html>
