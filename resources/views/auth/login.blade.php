<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>WorkerHub | Acceso operativo</title>
    <style>
        :root { --bg:#f3efe6; --panel:#fffdf8; --ink:#1d2a2f; --muted:#6b7b83; --accent:#0b6e4f; --line:#d9d0c4; --danger:#a33030; --danger-soft:#f8d9d9; --shadow:0 20px 50px rgba(29,42,47,.12); }
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; display:grid; place-items:center; background:radial-gradient(circle at top left, rgba(11,110,79,.12), transparent 25%), linear-gradient(180deg, #f8f4ec 0%, var(--bg) 100%); color:var(--ink); font-family:Georgia,"Times New Roman",serif; }
        .card { width:min(460px, calc(100vw - 32px)); padding:32px; border:1px solid var(--line); border-radius:24px; background:var(--panel); box-shadow:var(--shadow); }
        .eyebrow { margin:0 0 12px; text-transform:uppercase; letter-spacing:.18em; font-size:12px; color:var(--muted); }
        h1 { margin:0 0 12px; font-size:34px; line-height:1; }
        p { margin:0 0 24px; color:var(--muted); line-height:1.6; }
        label { display:block; margin:0 0 6px; font-size:13px; text-transform:uppercase; letter-spacing:.12em; color:var(--muted); }
        input { width:100%; padding:14px; border:1px solid var(--line); border-radius:14px; font:inherit; margin-bottom:18px; }
        button { width:100%; padding:14px; border:0; border-radius:14px; background:var(--accent); color:#fff; font:inherit; cursor:pointer; }
        .errors { margin:0 0 18px; padding:14px; border-radius:14px; background:var(--danger-soft); color:var(--danger); }
    </style>
</head>
<body>
    <main class="card">
        <div class="eyebrow">WorkerHub Access</div>
        <h1>Ingreso operativo</h1>
        <p>Autenticacion delegada a <code>backoffice_service</code>. Solo usuarios con rol administrador de backoffice pueden acceder al monitor.</p>

        @if ($errors->any())
            <div class="errors">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <form method="post" action="{{ route('workerhub.login.store') }}">
            @csrf
            <label for="username">Usuario o correo</label>
            <input id="username" name="username" type="text" value="{{ old('username') }}" autocomplete="username" required>

            <label for="password">Contrasena</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>

            <button type="submit">Validar acceso</button>
        </form>
    </main>
</body>
</html>
