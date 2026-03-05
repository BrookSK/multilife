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
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');

        :root{
            --background:216 33% 97%;
            --foreground:210 36% 17%;
            --card:0 0% 100%;
            --card-foreground:210 36% 17%;
            --primary:180 65% 46%;
            --primary-foreground:0 0% 100%;
            --primary-dark:180 71% 36%;
            --primary-darker:180 71% 28%;
            --secondary:216 33% 97%;
            --muted:216 33% 97%;
            --muted-foreground:216 18% 61%;
            --border:216 20% 90%;
            --input:216 20% 90%;
            --ring:180 65% 46%;
            --radius:0.625rem;
            --shadow-card:0 1px 3px 0 rgba(0,0,0,.06),0 1px 2px -1px rgba(0,0,0,.06);
            --shadow-card-hover:0 4px 12px 0 rgba(0,0,0,.08),0 2px 4px -1px rgba(0,0,0,.06);
            --shadow-elevated:0 10px 25px -5px rgba(0,0,0,.08),0 8px 10px -6px rgba(0,0,0,.04);
        }

        * { box-sizing: border-box; }

        html, body {
            height: 100%;
        }

        body {
            margin: 0;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, "Apple Color Emoji", "Segoe UI Emoji";
            color: hsl(var(--foreground));
            background: hsl(var(--background));
            display: grid;
            place-items: center;
            padding: 28px 16px;
            position: relative;
            overflow: hidden;
        }

        body:before{
            content:"";
            position: fixed;
            inset: 0;
            pointer-events: none;
            background: linear-gradient(135deg,#fff 0%,#fff 55%,hsla(var(--primary)/.10) 100%);
        }

        body:after{
            content:"";
            position: fixed;
            inset: 0;
            pointer-events: none;
            background:
                radial-gradient(380px 380px at 85% 15%,hsla(var(--primary)/.08),transparent 60%),
                radial-gradient(420px 420px at 12% 90%,hsla(var(--primary)/.05),transparent 62%);
        }

        .wrap {
            width: 100%;
            max-width: 980px;
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 22px;
            align-items: stretch;
            position: relative;
            z-index: 1;
        }

        .brand {
            padding: 34px;
            border-radius: calc(var(--radius) + 10px);
            background: hsl(var(--card));
            border: 1px solid hsl(var(--border));
            box-shadow: var(--shadow-elevated);
            position: relative;
            overflow: hidden;
        }

        .brand::before {
            content: "";
            position: absolute;
            inset: -2px;
            background: radial-gradient(520px 360px at 18% 18%,hsla(var(--primary)/.14),transparent 62%);
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
            font-weight: 800;
            letter-spacing: -0.2px;
            margin-bottom: 18px;
        }

        .mark {
            width: 44px;
            height: 44px;
            border-radius: 14px;
            background: hsla(var(--primary)/.10);
            border: 1px solid hsla(var(--primary)/.20);
            display: grid;
            place-items: center;
        }

        .mark svg {
            width: 22px;
            height: 22px;
            color: hsl(var(--primary));
        }

        .brand h1 {
            margin: 10px 0 10px;
            font-size: 36px;
            line-height: 1.1;
        }

        .brand p {
            margin: 0;
            color: hsl(var(--muted-foreground));
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
            background: hsla(var(--primary)/.06);
            border: 1px solid hsla(var(--primary)/.14);
        }

        .tip strong {
            display: block;
            font-size: 13px;
            margin-bottom: 4px;
        }

        .tip span {
            display: block;
            font-size: 13px;
            color: hsl(var(--muted-foreground));
            line-height: 1.45;
        }

        .card {
            border-radius: calc(var(--radius) + 10px);
            background: hsl(var(--card));
            border: 1px solid hsl(var(--border));
            box-shadow: var(--shadow-elevated);
            padding: 26px;
        }

        .card h2 {
            margin: 0 0 6px;
            font-size: 22px;
        }

        .card .subtitle {
            margin: 0 0 18px;
            color: hsl(var(--muted-foreground));
            font-size: 14px;
            line-height: 1.5;
        }

        .alert {
            margin: 0 0 14px;
            padding: 12px 12px;
            border-radius: 14px;
            background: hsla(var(--destructive)/.10);
            border: 1px solid hsla(var(--destructive)/.20);
            color: hsl(var(--foreground));
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
            color: hsl(var(--foreground));
        }

        .input {
            position: relative;
        }

        input[type="email"],
        input[type="password"] {
            width: 100%;
            border-radius: 10px;
            border: 1px solid hsl(var(--input));
            background: hsla(var(--secondary)/.50);
            color: hsl(var(--foreground));
            padding: 12px 12px;
            outline: none;
            transition: border-color .15s ease, box-shadow .15s ease;
            font-size: 14px;
        }

        input::placeholder {
            color: hsl(var(--muted-foreground));
        }

        input:focus {
            background: hsl(var(--card));
            border-color: hsla(var(--ring)/.55);
            box-shadow: 0 0 0 4px hsla(var(--ring)/.15);
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
            color: hsl(var(--muted-foreground));
            user-select: none;
        }

        .check input {
            width: 16px;
            height: 16px;
        }

        a {
            color: hsl(var(--primary));
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
            border-radius: 10px;
            border: 1px solid transparent;
            cursor: pointer;
            font-weight: 700;
            color: hsl(var(--primary-foreground));
            background: hsl(var(--primary));
            box-shadow: var(--shadow-card);
            transition: transform .06s ease, filter .15s ease;
        }

        .btn:hover{
            box-shadow: var(--shadow-card-hover);
            background: hsl(var(--primary-dark));
        }

        .btn:active {
            transform: translateY(1px);
        }

        .foot {
            margin-top: 14px;
            font-size: 13px;
            color: hsl(var(--muted-foreground));
            text-align: center;
        }

        .foot a { color: hsl(var(--primary)); }

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
                <?php
                // Buscar logo configurada
                $logoUrl = '';
                try {
                    require_once __DIR__ . '/app/bootstrap.php';
                    $logoUrl = admin_setting_get('app.logo_url');
                } catch (Exception $e) {
                    // Ignorar erro se não conseguir carregar
                }
                
                if (!empty($logoUrl)):
                ?>
                    <img src="<?= h($logoUrl) ?>" alt="Logo" style="max-height:60px;max-width:100%;object-fit:contain">
                <?php else: ?>
                    <div class="mark" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M12 2l8.5 5v10L12 22 3.5 17V7L12 2Z" stroke="currentColor" stroke-width="2"/>
                            <path d="M7.5 9.5L12 12l4.5-2.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </div>
                    <div>
                        <div style="font-size:14px; opacity:.95;">Multilife</div>
                        <div style="font-size:12px; color: hsl(var(--muted-foreground));">Acesso ao painel</div>
                    </div>
                <?php endif; ?>
            </div>

            <h1>Faça login para continuar</h1>
            <p>
                Entre com suas credenciais para acessar o sistema.
                Se você ainda não tem acesso, fale com o administrador.
            </p>

            <div class="tips">
                <div class="tip">
                    <div style="width:12px; height:12px; margin-top:4px; border-radius:999px; background: hsl(var(--primary));"></div>
                    <div>
                        <strong>Segurança</strong>
                        <span>Use uma senha forte e não compartilhe suas credenciais.</span>
                    </div>
                </div>
                <div class="tip">
                    <div style="width:12px; height:12px; margin-top:4px; border-radius:999px; background: hsla(var(--primary)/.55);"></div>
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
                    Não tem acesso? <a href="/apply_professional.php">Cadastre-se como profissional</a>
                </div>
            </form>
        </main>
    </div>
</body>
</html>
