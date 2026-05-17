<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ?? config('app.name', 'Oryntra') }}</title>

        <style>
            :root {
                color-scheme: light;
                --bg: #f6f4ef;
                --panel: #fffdf8;
                --ink: #181713;
                --muted: #6c675e;
                --line: #dfd9cf;
                --accent: #b45309;
                --accent-strong: #8a3c04;
                --danger: #b91c1c;
            }

            * {
                box-sizing: border-box;
            }

            body {
                min-height: 100vh;
                margin: 0;
                color: var(--ink);
                background:
                    linear-gradient(135deg, rgba(180, 83, 9, 0.10), transparent 34%),
                    radial-gradient(circle at 80% 18%, rgba(17, 94, 89, 0.12), transparent 30%),
                    var(--bg);
                font-family: ui-serif, Georgia, Cambria, "Times New Roman", Times, serif;
            }

            main {
                display: grid;
                min-height: 100vh;
                place-items: center;
                padding: 32px 16px;
            }

            .auth-panel {
                width: min(100%, 440px);
                border: 1px solid var(--line);
                border-radius: 8px;
                background: var(--panel);
                box-shadow: 0 24px 80px rgba(24, 23, 19, 0.12);
                padding: 32px;
            }

            .brand {
                margin: 0 0 8px;
                font-size: 34px;
                line-height: 1;
                letter-spacing: 0;
            }

            .lede {
                margin: 0 0 28px;
                color: var(--muted);
                font-family: ui-sans-serif, system-ui, sans-serif;
                font-size: 14px;
                line-height: 1.6;
            }

            label {
                display: block;
                margin: 16px 0 6px;
                font-family: ui-sans-serif, system-ui, sans-serif;
                font-size: 13px;
                font-weight: 700;
            }

            input {
                width: 100%;
                min-height: 44px;
                border: 1px solid var(--line);
                border-radius: 6px;
                background: #ffffff;
                color: var(--ink);
                font: 15px ui-sans-serif, system-ui, sans-serif;
                padding: 10px 12px;
            }

            input:focus {
                border-color: var(--accent);
                outline: 3px solid rgba(180, 83, 9, 0.16);
            }

            .check {
                display: flex;
                align-items: center;
                gap: 10px;
                margin: 16px 0;
                font-family: ui-sans-serif, system-ui, sans-serif;
                font-size: 14px;
            }

            .check input {
                width: 16px;
                min-height: 16px;
            }

            .actions {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 16px;
                margin-top: 24px;
            }

            button,
            .button {
                min-height: 44px;
                border: 0;
                border-radius: 6px;
                background: var(--accent);
                color: #fff;
                cursor: pointer;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                font: 700 14px ui-sans-serif, system-ui, sans-serif;
                padding: 0 18px;
                text-decoration: none;
            }

            button:hover,
            .button:hover {
                background: var(--accent-strong);
            }

            a {
                color: var(--accent-strong);
                font-family: ui-sans-serif, system-ui, sans-serif;
                font-size: 14px;
                text-decoration: none;
            }

            a:hover {
                text-decoration: underline;
            }

            .errors,
            .status {
                border-radius: 6px;
                font-family: ui-sans-serif, system-ui, sans-serif;
                font-size: 14px;
                line-height: 1.5;
                margin: 0 0 18px;
                padding: 12px 14px;
            }

            .errors {
                background: #fef2f2;
                color: var(--danger);
            }

            .status {
                background: #ecfdf5;
                color: #047857;
            }

            .errors ul {
                margin: 0;
                padding-left: 18px;
            }

            @media (max-width: 480px) {
                .auth-panel {
                    padding: 24px;
                }

                .actions {
                    align-items: stretch;
                    flex-direction: column;
                }

                button,
                .button {
                    width: 100%;
                }
            }
        </style>
    </head>
    <body>
        <main>
            <section class="auth-panel">
                <h1 class="brand">Oryntra</h1>
                <p class="lede">{{ $subtitle ?? 'Acesse o painel operacional.' }}</p>

                @if (session('status'))
                    <div class="status">{{ session('status') }}</div>
                @endif

                @if ($errors->any())
                    <div class="errors">
                        <ul>
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{ $slot }}
            </section>
        </main>
    </body>
</html>
