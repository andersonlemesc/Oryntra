<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="color-scheme" content="dark">

        <title>{{ $title ?? config('app.name', 'Oryntra') }}</title>

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">

        <style>
            :root {
                color-scheme: dark;
                --bg-0: #07080b;
                --bg-1: #0c0e13;
                --bg-2: #11141a;
                --panel: rgba(18, 22, 30, 0.72);
                --panel-border: rgba(120, 130, 150, 0.14);
                --panel-glow: rgba(110, 200, 255, 0.10);
                --ink: #e7ecf3;
                --ink-soft: #aab1bd;
                --muted: #6c7484;
                --line: rgba(180, 190, 210, 0.10);
                --line-strong: rgba(180, 190, 210, 0.22);
                --accent: #6cc7ff;
                --accent-2: #9b7dff;
                --accent-grad: linear-gradient(135deg, #6cc7ff 0%, #9b7dff 100%);
                --danger: #ff7373;
                --success: #5dd6a7;
                --radius: 14px;
                --radius-sm: 10px;
            }

            * {
                box-sizing: border-box;
            }

            html,
            body {
                margin: 0;
                padding: 0;
                min-height: 100vh;
            }

            body {
                color: var(--ink);
                background: var(--bg-0);
                font-family: 'Inter', ui-sans-serif, system-ui, -apple-system, sans-serif;
                font-feature-settings: 'ss01', 'cv11';
                -webkit-font-smoothing: antialiased;
                -moz-osx-font-smoothing: grayscale;
                letter-spacing: -0.01em;
                overflow-x: hidden;
            }

            .bg-grid {
                position: fixed;
                inset: 0;
                pointer-events: none;
                background-image:
                    radial-gradient(circle at 18% 18%, rgba(108, 199, 255, 0.16), transparent 38%),
                    radial-gradient(circle at 86% 84%, rgba(155, 125, 255, 0.14), transparent 42%),
                    radial-gradient(circle at 50% 50%, rgba(40, 60, 100, 0.10), transparent 70%);
                z-index: 0;
            }

            .bg-grid::after {
                content: '';
                position: absolute;
                inset: 0;
                background-image:
                    linear-gradient(rgba(255, 255, 255, 0.02) 1px, transparent 1px),
                    linear-gradient(90deg, rgba(255, 255, 255, 0.02) 1px, transparent 1px);
                background-size: 56px 56px;
                mask-image: radial-gradient(ellipse at center, black 30%, transparent 75%);
            }

            main {
                position: relative;
                z-index: 1;
                display: grid;
                min-height: 100vh;
                place-items: center;
                padding: 40px 20px;
            }

            .auth-panel {
                width: min(100%, 460px);
                background: var(--panel);
                backdrop-filter: blur(18px) saturate(140%);
                -webkit-backdrop-filter: blur(18px) saturate(140%);
                border: 1px solid var(--panel-border);
                border-radius: var(--radius);
                padding: 36px 36px 32px;
                position: relative;
                box-shadow:
                    0 0 0 1px rgba(255, 255, 255, 0.02) inset,
                    0 30px 90px -20px rgba(0, 0, 0, 0.6),
                    0 0 60px -10px var(--panel-glow);
            }

            .auth-panel::before {
                content: '';
                position: absolute;
                inset: -1px;
                border-radius: inherit;
                padding: 1px;
                background: linear-gradient(135deg, rgba(108, 199, 255, 0.4), rgba(155, 125, 255, 0.25) 40%, transparent 70%);
                -webkit-mask:
                    linear-gradient(#000 0 0) content-box,
                    linear-gradient(#000 0 0);
                -webkit-mask-composite: xor;
                mask-composite: exclude;
                pointer-events: none;
                opacity: 0.6;
            }

            .brand-row {
                display: flex;
                align-items: center;
                gap: 12px;
                margin: 0 0 6px;
            }

            .brand-mark {
                width: 34px;
                height: 34px;
                border-radius: 9px;
                background: var(--accent-grad);
                display: grid;
                place-items: center;
                font-family: 'JetBrains Mono', ui-monospace, monospace;
                font-weight: 700;
                font-size: 16px;
                color: #0a0c12;
                box-shadow: 0 0 24px -2px rgba(108, 199, 255, 0.45);
            }

            .brand {
                margin: 0;
                font-size: 22px;
                font-weight: 600;
                letter-spacing: -0.02em;
                color: var(--ink);
            }

            .brand-tag {
                margin: 0 0 4px;
                font-family: 'JetBrains Mono', ui-monospace, monospace;
                font-size: 10.5px;
                text-transform: uppercase;
                letter-spacing: 0.18em;
                color: var(--accent);
            }

            .lede {
                margin: 0 0 28px;
                color: var(--ink-soft);
                font-size: 14px;
                line-height: 1.55;
                max-width: 36ch;
            }

            label {
                display: block;
                margin: 18px 0 8px;
                font-size: 12px;
                font-weight: 600;
                letter-spacing: 0.04em;
                text-transform: uppercase;
                color: var(--muted);
            }

            input[type="text"],
            input[type="email"],
            input[type="password"],
            input[type="url"] {
                width: 100%;
                min-height: 46px;
                padding: 12px 14px;
                background: rgba(8, 10, 14, 0.6);
                border: 1px solid var(--line);
                border-radius: var(--radius-sm);
                color: var(--ink);
                font: 15px 'Inter', ui-sans-serif, system-ui, sans-serif;
                letter-spacing: -0.01em;
                transition: border-color 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
            }

            input[type="text"]::placeholder,
            input[type="email"]::placeholder,
            input[type="password"]::placeholder,
            input[type="url"]::placeholder {
                color: rgba(170, 177, 189, 0.35);
            }

            input[type="text"]:hover,
            input[type="email"]:hover,
            input[type="password"]:hover,
            input[type="url"]:hover {
                border-color: var(--line-strong);
            }

            input[type="text"]:focus,
            input[type="email"]:focus,
            input[type="password"]:focus,
            input[type="url"]:focus {
                outline: none;
                border-color: rgba(108, 199, 255, 0.55);
                background: rgba(12, 16, 22, 0.85);
                box-shadow: 0 0 0 4px rgba(108, 199, 255, 0.10);
            }

            .check {
                display: flex;
                align-items: center;
                gap: 10px;
                margin: 20px 0 0;
                font-size: 13.5px;
                color: var(--ink-soft);
                text-transform: none;
                letter-spacing: 0;
                font-weight: 500;
                cursor: pointer;
            }

            .check input[type="checkbox"] {
                appearance: none;
                width: 16px;
                height: 16px;
                border: 1px solid var(--line-strong);
                border-radius: 4px;
                background: rgba(8, 10, 14, 0.6);
                cursor: pointer;
                position: relative;
                transition: all 0.18s ease;
            }

            .check input[type="checkbox"]:checked {
                background: var(--accent-grad);
                border-color: transparent;
            }

            .check input[type="checkbox"]:checked::after {
                content: '';
                position: absolute;
                inset: 2px;
                background: #0a0c12;
                clip-path: polygon(14% 44%, 0 65%, 50% 100%, 100% 16%, 80% 0%, 43% 62%);
            }

            .actions {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 16px;
                margin-top: 28px;
            }

            button[type="submit"],
            .button {
                appearance: none;
                min-height: 46px;
                padding: 0 22px;
                border: 0;
                border-radius: var(--radius-sm);
                background: var(--accent-grad);
                color: #0a0c12;
                cursor: pointer;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                font: 600 14px 'Inter', sans-serif;
                letter-spacing: 0.01em;
                text-decoration: none;
                position: relative;
                box-shadow:
                    0 0 0 1px rgba(255, 255, 255, 0.06) inset,
                    0 8px 24px -8px rgba(108, 199, 255, 0.45);
                transition: transform 0.12s ease, box-shadow 0.18s ease, filter 0.18s ease;
            }

            button[type="submit"]:hover,
            .button:hover {
                filter: brightness(1.08);
                box-shadow:
                    0 0 0 1px rgba(255, 255, 255, 0.10) inset,
                    0 10px 30px -8px rgba(108, 199, 255, 0.55);
            }

            button[type="submit"]:active,
            .button:active {
                transform: translateY(1px);
            }

            a {
                color: var(--ink-soft);
                font-size: 13.5px;
                text-decoration: none;
                transition: color 0.15s ease;
            }

            a:hover {
                color: var(--accent);
            }

            .errors,
            .status {
                border-radius: var(--radius-sm);
                font-size: 13.5px;
                line-height: 1.55;
                margin: 0 0 20px;
                padding: 12px 14px;
                border: 1px solid;
            }

            .errors {
                background: rgba(255, 115, 115, 0.07);
                border-color: rgba(255, 115, 115, 0.25);
                color: #ffb6b6;
            }

            .errors strong {
                color: var(--danger);
                display: block;
                margin-bottom: 4px;
                font-weight: 600;
            }

            .status {
                background: rgba(93, 214, 167, 0.07);
                border-color: rgba(93, 214, 167, 0.25);
                color: var(--success);
            }

            .errors ul {
                margin: 0;
                padding-left: 18px;
            }

            .errors p {
                margin: 4px 0 0;
            }

            .panel-foot {
                margin-top: 24px;
                padding-top: 18px;
                border-top: 1px solid var(--line);
                display: flex;
                justify-content: space-between;
                align-items: center;
                font-size: 11.5px;
                color: var(--muted);
                font-family: 'JetBrains Mono', ui-monospace, monospace;
                letter-spacing: 0.06em;
            }

            .panel-foot .dot {
                width: 6px;
                height: 6px;
                border-radius: 50%;
                background: var(--success);
                box-shadow: 0 0 8px var(--success);
                display: inline-block;
                margin-right: 6px;
                vertical-align: middle;
            }

            @media (max-width: 480px) {
                .auth-panel {
                    padding: 28px 22px 24px;
                }

                .actions {
                    align-items: stretch;
                    flex-direction: column-reverse;
                }

                button[type="submit"],
                .button {
                    width: 100%;
                }
            }
        </style>
    </head>
    <body>
        <div class="bg-grid"></div>

        <main>
            <section class="auth-panel">
                <p class="brand-tag">Oryntra // AI Operations</p>
                <div class="brand-row">
                    <span class="brand-mark" aria-hidden="true">O</span>
                    <h1 class="brand">{{ $heading ?? 'Oryntra' }}</h1>
                </div>
                <p class="lede">{{ $subtitle ?? 'Acesse o painel operacional.' }}</p>

                @if (session('status'))
                    <div class="status">{{ session('status') }}</div>
                @endif

                @if ($errors->any())
                    <div class="errors">
                        <strong>Falha na requisicao</strong>
                        <ul>
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{ $slot }}

                <div class="panel-foot">
                    <span><span class="dot"></span>{{ $statusLabel ?? 'SYSTEM ONLINE' }}</span>
                    <span>{{ $versionLabel ?? 'v0.1' }}</span>
                </div>
            </section>
        </main>
    </body>
</html>
