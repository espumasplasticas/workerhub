<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>WorkerHub | Acceso operativo</title>
    <style>
        :root {
            --bg:#eef4fc;
            --panel:#ffffff;
            --ink:#12284b;
            --muted:#667b9d;
            --line:rgba(28,78,156,.14);
            --accent:#1d69e2;
            --accent-strong:#0f4fb7;
            --accent-soft:rgba(29,105,226,.08);
            --danger:#c73b55;
            --danger-soft:rgba(199,59,85,.10);
            --shadow:0 24px 60px rgba(12,35,74,.10);
        }
        * { box-sizing:border-box; }
        body {
            margin:0;
            min-height:100vh;
            display:grid;
            place-items:center;
            padding:24px;
            font-family:"Manrope","Segoe UI",sans-serif;
            color:var(--ink);
            background:
                radial-gradient(circle at top left, rgba(29,105,226,.14), transparent 26%),
                linear-gradient(180deg, #f8fbff 0%, var(--bg) 100%);
        }
        .card {
            width:min(520px, calc(100vw - 32px));
            padding:28px;
            border-radius:24px;
            border:1px solid var(--line);
            background:var(--panel);
            box-shadow:var(--shadow);
        }
        .eyebrow {
            margin:0 0 10px;
            color:var(--muted);
            font-size:11px;
            font-weight:800;
            letter-spacing:.18em;
            text-transform:uppercase;
        }
        h1 {
            margin:0 0 8px;
            font-size:32px;
            line-height:1.04;
            font-family:"Instrument Sans","Segoe UI",sans-serif;
            color:var(--ink);
        }
        .lead {
            margin:0 0 20px;
            color:var(--muted);
            line-height:1.65;
            font-size:14px;
        }
        .meta {
            display:flex;
            gap:8px;
            flex-wrap:wrap;
            margin-bottom:18px;
        }
        .meta span {
            display:inline-flex;
            align-items:center;
            min-height:32px;
            padding:0 12px;
            border-radius:999px;
            border:1px solid var(--line);
            background:#f7faff;
            color:var(--accent-strong);
            font-size:12px;
            font-weight:700;
        }
        .notice {
            margin:0 0 18px;
            padding:12px 14px;
            border-radius:16px;
            background:var(--accent-soft);
            border:1px solid var(--line);
            color:var(--muted);
            font-size:13px;
            line-height:1.55;
        }
        .errors {
            margin:0 0 18px;
            padding:14px 16px;
            border-radius:16px;
            background:var(--danger-soft);
            color:var(--danger);
            border:1px solid rgba(199,59,85,.18);
        }
        .field { display:flex; flex-direction:column; gap:8px; margin-bottom:16px; }
        label {
            font-size:11px;
            font-weight:800;
            letter-spacing:.14em;
            text-transform:uppercase;
            color:var(--muted);
        }
        input {
            width:100%;
            min-height:48px;
            padding:0 14px;
            border:1px solid var(--line);
            border-radius:14px;
            font:inherit;
            color:var(--ink);
            background:#fbfdff;
        }
        input:focus {
            outline:none;
            border-color:rgba(29,105,226,.34);
            box-shadow:0 0 0 4px rgba(29,105,226,.08);
            background:#fff;
        }
        .submit {
            width:100%;
            min-height:50px;
            border:0;
            border-radius:16px;
            background:linear-gradient(135deg, var(--accent), #3a86ff);
            color:#fff;
            font:inherit;
            font-weight:800;
            cursor:pointer;
        }
        .submit:hover { background:linear-gradient(135deg, var(--accent-strong), var(--accent)); }
        .footer {
            display:flex;
            justify-content:space-between;
            gap:12px;
            align-items:center;
            flex-wrap:wrap;
            margin-top:16px;
            padding-top:16px;
            border-top:1px solid var(--line);
        }
        .footer a {
            color:var(--accent-strong);
            text-decoration:none;
            font-size:13px;
            font-weight:700;
        }
        .footer small {
            color:var(--muted);
            font-size:12px;
        }
    </style>
</head>
<body>
    <main class="card">
        <div class="eyebrow">WorkerHub Operations</div>
        <h1>Acceso operativo</h1>
        <p class="lead">Inicia sesion para abrir el monitor y Horizon. En desarrollo, si backoffice no responde y el bypass local esta habilitado, WorkerHub dejara continuar con una sesion de desarrollo.</p>

        <div class="meta">
            <span>Monitor</span>
            <span>Horizon</span>
            <span>Backoffice</span>
        </div>

        <div class="notice">
            Usa tu usuario de backoffice. Si el servidor de desarrollo no alcanza <strong>backoffice_service</strong>, el acceso puede continuar por bypass local solo cuando el entorno lo permita.
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

        <div class="footer">
            <small>Monitor y dashboard operan con la misma sesion.</small>
            <a href="{{ url('/horizon') }}">Abrir Horizon</a>
        </div>
    </main>
</body>
</html>
