<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>WorkerHub | Acceso operativo</title>
    <style>
        :root {
            --bg:#eef5ff;
            --panel:rgba(255,255,255,.88);
            --panel-strong:#ffffff;
            --ink:#10203d;
            --muted:#5d7298;
            --primary:#0d62d6;
            --primary-strong:#0848a2;
            --primary-soft:rgba(13,98,214,.12);
            --line:rgba(26,90,176,.14);
            --danger:#c73b55;
            --danger-soft:rgba(199,59,85,.12);
            --shadow:0 30px 70px rgba(13,53,120,.16);
        }
        * { box-sizing:border-box; }
        body {
            margin:0;
            min-height:100vh;
            color:var(--ink);
            font-family:"Manrope","Segoe UI",sans-serif;
            background:
                radial-gradient(circle at top left, rgba(13,98,214,.20), transparent 30%),
                radial-gradient(circle at bottom right, rgba(92,157,255,.18), transparent 28%),
                linear-gradient(180deg, #f8fbff 0%, var(--bg) 100%);
        }
        .shell { min-height:100vh; display:grid; place-items:center; padding:28px; }
        .card {
            width:min(1080px, calc(100vw - 32px));
            display:grid;
            grid-template-columns:1.15fr .85fr;
            border:1px solid var(--line);
            border-radius:30px;
            overflow:hidden;
            background:var(--panel);
            backdrop-filter:blur(18px);
            box-shadow:var(--shadow);
        }
        .brand {
            padding:42px;
            background:
                radial-gradient(circle at top left, rgba(13,98,214,.16), transparent 35%),
                linear-gradient(155deg, #0d62d6 0%, #0b4ea9 52%, #16336f 100%);
            color:#fff;
            position:relative;
        }
        .brand::after {
            content:"";
            position:absolute;
            inset:auto -10% -18% auto;
            width:260px;
            height:260px;
            border-radius:50%;
            background:rgba(255,255,255,.08);
            filter:blur(6px);
        }
        .eyebrow {
            display:inline-flex;
            align-items:center;
            gap:10px;
            margin:0 0 18px;
            padding:8px 12px;
            border-radius:999px;
            background:rgba(255,255,255,.12);
            text-transform:uppercase;
            letter-spacing:.18em;
            font-size:11px;
            font-weight:700;
        }
        .brand h1 {
            margin:0 0 16px;
            font-size:44px;
            line-height:.95;
            font-family:"Instrument Sans","Segoe UI",sans-serif;
        }
        .brand p {
            margin:0 0 20px;
            color:rgba(255,255,255,.82);
            line-height:1.75;
            max-width:44ch;
        }
        .brand-grid {
            display:grid;
            grid-template-columns:repeat(2, minmax(0, 1fr));
            gap:14px;
            margin-top:28px;
        }
        .brand-card {
            padding:16px;
            border:1px solid rgba(255,255,255,.12);
            border-radius:18px;
            background:rgba(255,255,255,.08);
        }
        .brand-card strong { display:block; margin-bottom:6px; font-size:14px; }
        .brand-card span { color:rgba(255,255,255,.74); font-size:13px; line-height:1.5; }
        .form-wrap {
            padding:42px 38px;
            background:linear-gradient(180deg, rgba(255,255,255,.72), var(--panel-strong));
        }
        .form-head small {
            color:var(--muted);
            text-transform:uppercase;
            letter-spacing:.16em;
            font-size:11px;
            font-weight:700;
        }
        .form-head h2 {
            margin:10px 0 10px;
            font-size:34px;
            line-height:1;
            font-family:"Instrument Sans","Segoe UI",sans-serif;
        }
        .form-head p {
            margin:0 0 28px;
            color:var(--muted);
            line-height:1.7;
        }
        .field { margin-bottom:18px; }
        label {
            display:block;
            margin:0 0 8px;
            font-size:12px;
            text-transform:uppercase;
            letter-spacing:.12em;
            color:var(--muted);
            font-weight:700;
        }
        input {
            width:100%;
            padding:15px 16px;
            border:1px solid var(--line);
            border-radius:16px;
            font:inherit;
            color:var(--ink);
            background:rgba(244,248,255,.9);
            outline:none;
            transition:border-color .18s ease, box-shadow .18s ease, background .18s ease;
        }
        input:focus {
            border-color:rgba(13,98,214,.38);
            box-shadow:0 0 0 5px rgba(13,98,214,.08);
            background:#fff;
        }
        .submit {
            width:100%;
            padding:15px 18px;
            border:0;
            border-radius:16px;
            background:linear-gradient(135deg, var(--primary), #3b8dff);
            color:#fff;
            font:inherit;
            font-weight:700;
            cursor:pointer;
            box-shadow:0 18px 32px rgba(13,98,214,.24);
        }
        .submit:hover { background:linear-gradient(135deg, var(--primary-strong), var(--primary)); }
        .footer-links {
            display:flex;
            flex-wrap:wrap;
            gap:10px;
            margin-top:18px;
        }
        .link-chip {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-height:40px;
            padding:0 14px;
            border-radius:999px;
            border:1px solid var(--line);
            background:#fff;
            color:var(--ink);
            text-decoration:none;
            font-size:13px;
            font-weight:700;
        }
        .errors {
            margin:0 0 18px;
            padding:14px 16px;
            border-radius:16px;
            background:var(--danger-soft);
            color:var(--danger);
            border:1px solid rgba(199,59,85,.18);
        }
        @media (max-width: 920px) {
            .card { grid-template-columns:1fr; }
            .brand, .form-wrap { padding:30px 24px; }
            .brand-grid { grid-template-columns:1fr; }
        }
    </style>
</head>
<body>
    <main class="shell">
        <section class="card">
            <aside class="brand">
                <div class="eyebrow">Comodísimos Operations</div>
                <h1>WorkerHub opera colas, migraciones y trazabilidad en una sola vista.</h1>
                <p>Acceso operativo unificado contra <strong>backoffice_service</strong>. Solo usuarios activos con rol administrador de backoffice pueden abrir el monitor y Horizon.</p>
                <div class="brand-grid">
                    <div class="brand-card">
                        <strong>Monitor azul corporativo</strong>
                        <span>Resumen de tareas, DLQ, lineage y replay manual en una interfaz operativa más clara.</span>
                    </div>
                    <div class="brand-card">
                        <strong>Una sola sesión</strong>
                        <span>El mismo login habilita el monitor interno y el dashboard de Horizon sin un segundo gate local.</span>
                    </div>
                </div>
            </aside>
            <section class="form-wrap">
                <div class="form-head">
                    <small>Ingreso operativo</small>
                    <h2>Validar acceso</h2>
                    <p>Usa tus credenciales de backoffice. WorkerHub validará estado del usuario y autorización administrativa antes de crear la sesión local.</p>
                </div>

                @if ($errors->any())
                    <div class="errors">
                        @foreach ($errors->all() as $error)
                            <div>{{ $error }}</div>
                        @endforeach
                    </div>
                @endif

                <form method="post" action="{{ route('workerhub.login.store') }}">
                    @csrf
                    <div class="field">
                        <label for="username">Usuario o correo</label>
                        <input id="username" name="username" type="text" value="{{ old('username') }}" autocomplete="username" required>
                    </div>

                    <div class="field">
                        <label for="password">Contrasena</label>
                        <input id="password" name="password" type="password" autocomplete="current-password" required>
                    </div>

                    <button class="submit" type="submit">Entrar a WorkerHub</button>
                </form>

                <div class="footer-links">
                    <a class="link-chip" href="{{ url('/monitor') }}">Monitor</a>
                    <a class="link-chip" href="{{ url('/horizon') }}">Horizon</a>
                </div>
            </section>
        </section>
    </main>
</body>
</html>
