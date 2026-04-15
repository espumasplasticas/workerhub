<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>WorkerHub Monitor</title>
    <style>
        :root {
            --bg:#edf4ff;
            --panel:rgba(255,255,255,.94);
            --panel-strong:#ffffff;
            --ink:#13294d;
            --muted:#6880a8;
            --accent:#1d69e2;
            --accent-strong:#0e4fb7;
            --accent-soft:rgba(29,105,226,.10);
            --warn:#c68414;
            --warn-soft:rgba(230,178,65,.16);
            --danger:#ca4266;
            --danger-soft:rgba(202,66,102,.12);
            --success:#138769;
            --success-soft:rgba(19,135,105,.12);
            --navy:#0c2146;
            --navy-soft:#153567;
            --line:rgba(28,78,156,.12);
            --line-strong:rgba(28,78,156,.2);
            --shadow:0 26px 70px rgba(9,35,78,.12);
        }
        * { box-sizing:border-box; }
        html, body { overflow-x:hidden; }
        body {
            margin:0;
            font-family:"Manrope","Segoe UI",sans-serif;
            color:var(--ink);
            background:
                radial-gradient(circle at top left, rgba(29,105,226,.18), transparent 24%),
                radial-gradient(circle at 85% 0%, rgba(115,179,255,.14), transparent 22%),
                linear-gradient(180deg, #f7faff 0%, var(--bg) 100%);
        }
        .shell { width:min(1580px, calc(100vw - 28px)); margin:0 auto; padding:16px 0 28px; }
        .topbar { margin-bottom:14px; }
        .topbar-card {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:16px;
            padding:16px 20px;
            border:1px solid rgba(255,255,255,.12);
            border-radius:22px;
            background:linear-gradient(135deg, rgba(11,34,80,.97), rgba(17,54,110,.94));
            box-shadow:var(--shadow);
            backdrop-filter:blur(16px);
        }
        .brand { display:flex; align-items:center; gap:14px; }
        .brand-mark {
            width:46px;
            height:46px;
            border-radius:15px;
            background:linear-gradient(145deg, var(--accent), #49a0ff);
            box-shadow:inset 0 1px 0 rgba(255,255,255,.24);
        }
        .brand-copy small {
            display:block;
            margin-bottom:4px;
            color:rgba(223,235,255,.74);
            text-transform:uppercase;
            letter-spacing:.18em;
            font-size:11px;
            font-weight:700;
        }
        .brand-copy strong {
            display:block;
            font-size:22px;
            line-height:1;
            font-family:"Instrument Sans","Segoe UI",sans-serif;
            color:#fff;
        }
        .brand-copy span { color:rgba(235,243,255,.82); font-size:13px; }
        .nav {
            display:flex;
            align-items:center;
            flex-wrap:wrap;
            gap:10px;
        }
        .nav-link, .nav button {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-height:42px;
            padding:0 16px;
            border-radius:999px;
            border:1px solid rgba(255,255,255,.14);
            background:rgba(255,255,255,.08);
            color:#eff5ff;
            text-decoration:none;
            font:inherit;
            font-size:13px;
            font-weight:700;
            cursor:pointer;
        }
        .nav-link.active, .nav-link:hover, .nav button:hover { background:#fff; border-color:#fff; color:var(--navy); }
        .operator-chip {
            margin-left:6px;
            padding-left:14px;
            border-left:1px solid rgba(255,255,255,.14);
            color:rgba(223,235,255,.78);
            font-size:13px;
            min-width:180px;
        }
        .operator-chip strong { display:block; color:#fff; }
        .hero { display:grid; grid-template-columns:minmax(0, 1fr); gap:12px; margin-bottom:12px; align-items:stretch; }
        .panel { background:var(--panel); border:1px solid var(--line); border-radius:22px; box-shadow:var(--shadow); backdrop-filter:blur(14px); }
        .headline { padding:18px 20px; background:linear-gradient(180deg, rgba(255,255,255,.96), rgba(244,248,255,.98)); color:var(--ink); }
        .eyebrow { text-transform:uppercase; letter-spacing:.18em; font-size:11px; color:var(--muted); margin-bottom:10px; font-weight:700; }
        .headline .eyebrow { color:var(--muted); }
        h1 { margin:0 0 8px; font-size:26px; line-height:1.08; font-family:"Instrument Sans","Segoe UI",sans-serif; max-width:none; color:var(--navy); }
        .lead { margin:0; color:var(--muted); font-size:14px; line-height:1.6; max-width:88ch; }
        .headline-meta { display:flex; flex-wrap:wrap; gap:10px; margin-top:14px; }
        .headline-pill {
            display:inline-flex;
            align-items:center;
            gap:8px;
            min-height:34px;
            padding:0 13px;
            border-radius:999px;
            border:1px solid var(--line);
            background:#f7faff;
            color:var(--ink);
            font-size:12px;
            font-weight:700;
        }
        .status-dot { display:inline-flex; align-items:center; gap:8px; font-size:13px; color:var(--muted); margin-top:16px; }
        .status-dot::before { content:""; width:10px; height:10px; border-radius:50%; background:#60b6ff; box-shadow:0 0 0 6px rgba(96,182,255,.12); }
        .status-grid { display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:10px; margin-top:12px; }
        .status-pill {
            padding:12px 14px;
            border-radius:16px;
            border:1px solid var(--line);
            background:#fbfdff;
        }
        .status-pill span {
            display:block;
            font-size:11px;
            letter-spacing:.12em;
            text-transform:uppercase;
            color:var(--muted);
            margin-bottom:6px;
            font-weight:700;
        }
        .status-pill strong {
            display:block;
            font-size:18px;
            line-height:1.1;
            color:var(--navy);
            margin-bottom:4px;
        }
        .status-pill small {
            display:block;
            color:var(--muted);
            font-size:12px;
            line-height:1.5;
        }
        .cards { display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:10px; margin-bottom:10px; }
        .metric { padding:14px 16px; min-height:92px; background:linear-gradient(180deg, rgba(255,255,255,.9), rgba(246,250,255,.92)); }
        .metric-keyline {
            width:34px;
            height:3px;
            border-radius:999px;
            margin-bottom:10px;
            background:linear-gradient(90deg, var(--accent), #5ab7ff);
        }
        .metric-label { font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:.14em; }
        .metric-value { font-size:30px; margin:6px 0 4px; font-family:"Instrument Sans","Segoe UI",sans-serif; }
        .metric-subtle, .hint, .lineage-meta { color:var(--muted); font-size:13px; }
        .process-footer {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            flex-wrap:wrap;
            margin:0 16px 16px;
            padding-top:14px;
            border-top:1px solid var(--line);
        }
        .process-footer-copy strong {
            display:block;
            margin-bottom:4px;
            font-size:14px;
            color:var(--navy);
        }
        .process-footer-copy span {
            display:block;
            font-size:12px;
            color:var(--muted);
        }
        .process-pills {
            display:flex;
            align-items:center;
            gap:8px;
            flex-wrap:wrap;
        }
        .process-pill {
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
        .workspace { display:grid; grid-template-columns:minmax(0, 1fr); gap:14px; align-items:start; }
        .workspace > article.panel { overflow:hidden; }
        .toolbar { display:grid; gap:12px; padding:14px 16px; }
        .toolbar.primary, .toolbar.secondary { grid-template-columns:repeat(auto-fit, minmax(190px, 1fr)); border-bottom:1px solid var(--line); }
        .toolbar.actions { grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); padding-top:8px; }
        .field { display:flex; flex-direction:column; gap:6px; }
        .field label { font-size:11px; font-weight:800; letter-spacing:.12em; text-transform:uppercase; color:var(--muted); }
        .toolbar input, .toolbar select, .toolbar button, .actions button {
            min-height:46px;
            border:1px solid var(--line);
            border-radius:14px;
            padding:0 14px;
            font:inherit;
            background:#fff;
            color:var(--ink);
            box-shadow:0 1px 0 rgba(255,255,255,.8), inset 0 1px 0 rgba(255,255,255,.75);
        }
        .toolbar input:focus, .toolbar select:focus {
            outline:none;
            border-color:rgba(29,105,226,.34);
            box-shadow:0 0 0 4px rgba(29,105,226,.08);
            background:#fff;
        }
        .toolbar button, .actions button { background:var(--accent); border-color:var(--accent); color:#fff; cursor:pointer; font-weight:700; }
        .toolbar button.secondary, .actions button.secondary { background:#fff; color:var(--ink); border-color:var(--line); }
        .toolbar-summary {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:16px;
            padding:6px 16px 14px;
            border-bottom:1px solid var(--line);
        }
        .toolbar-summary strong {
            display:block;
            margin-bottom:4px;
            font-size:16px;
            color:var(--navy);
        }
        .toolbar-summary span { display:block; font-size:13px; color:var(--muted); }
        .table-wrap { overflow:auto; max-height:680px; background:#fff; }
        table { width:100%; border-collapse:collapse; }
        th, td { padding:13px 16px; text-align:left; border-bottom:1px solid rgba(26,90,176,.08); vertical-align:top; }
        th { position:sticky; top:0; background:#f5f8fd; font-size:11px; text-transform:uppercase; letter-spacing:.12em; color:var(--muted); z-index:1; }
        tbody tr:nth-child(even) td { background:rgba(245,248,253,.72); }
        tr:hover td { background:rgba(13,98,214,.05); }
        tr.is-selected td { background:rgba(13,98,214,.10); }
        .badge { display:inline-flex; align-items:center; border-radius:999px; padding:6px 10px; font-size:12px; font-weight:700; letter-spacing:.04em; }
        .badge.received, .badge.published, .badge.queued, .badge.processing { background:var(--accent-soft); color:var(--accent); }
        .badge.completed { background:var(--success-soft); color:var(--success); }
        .badge.failed, .badge.rejected { background:var(--danger-soft); color:var(--danger); }
        .badge.default { background:rgba(96,119,156,.12); color:#4d6186; }
        .badge.high { background:var(--warn-soft); color:var(--warn); }
        .mono { font-family:Consolas, Monaco, monospace; font-size:12px; }
        .checkbox { width:16px; height:16px; accent-color:var(--accent); }
        .detail-modal {
            position:fixed;
            inset:0;
            display:none;
            align-items:center;
            justify-content:center;
            padding:24px;
            z-index:60;
        }
        .detail-modal.is-open { display:flex; }
        .detail-backdrop {
            position:absolute;
            inset:0;
            background:rgba(8,18,39,.42);
            backdrop-filter:blur(4px);
        }
        .detail-dialog {
            position:relative;
            width:min(1120px, calc(100vw - 48px));
            max-height:calc(100vh - 48px);
            overflow:auto;
            border-radius:24px;
            border:1px solid var(--line);
            background:linear-gradient(180deg, rgba(255,255,255,.99), rgba(247,250,255,.98));
            box-shadow:0 30px 70px rgba(8,18,39,.24);
        }
        .detail-header {
            display:flex;
            align-items:flex-start;
            justify-content:space-between;
            gap:16px;
            padding:20px 20px 0;
        }
        .detail-close {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-width:40px;
            height:40px;
            border-radius:999px;
            border:1px solid var(--line);
            background:#fff;
            color:var(--navy);
            font:inherit;
            font-size:20px;
            cursor:pointer;
        }
        .detail {
            padding:0 20px 20px;
            color:var(--ink);
        }
        .detail h2 { margin-top:0; font-size:28px; line-height:1.08; color:var(--navy); }
        .detail .eyebrow, .detail .hint, .detail .lineage-meta { color:var(--muted); }
        .detail-tabs {
            display:inline-flex;
            gap:8px;
            flex-wrap:wrap;
            margin:16px 0 18px;
            padding:6px;
            border-radius:999px;
            background:#eef4ff;
            border:1px solid var(--line);
        }
        .detail-tab {
            border:0;
            background:transparent;
            color:var(--muted);
            border-radius:999px;
            padding:10px 14px;
            font:inherit;
            font-size:12px;
            font-weight:800;
            letter-spacing:.12em;
            text-transform:uppercase;
            cursor:pointer;
        }
        .detail-tab.active {
            background:var(--accent);
            color:#fff;
        }
        .detail-pane { display:none; }
        .detail-pane.active { display:block; }
        .detail-grid { display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:12px; margin-bottom:18px; }
        .detail-card {
            padding:14px;
            border-radius:16px;
            border:1px solid var(--line);
            background:#fff;
            min-width:0;
            box-shadow:0 8px 18px rgba(11,38,82,.05);
        }
        .detail-card.full { grid-column:1 / -1; }
        .detail-label { font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:.14em; margin-bottom:6px; }
        .detail-value { font-size:15px; font-weight:600; min-width:0; overflow-wrap:anywhere; word-break:break-word; color:var(--navy); }
        .detail-value.code { font-family:Consolas, Monaco, monospace; font-size:12px; font-weight:600; color:var(--navy); }
        .detail-value.error { max-height:240px; overflow:auto; padding:10px 12px; border-radius:12px; background:rgba(202,66,102,.10); color:#8a2844; }
        .detail-value.inline-badge { display:flex; align-items:center; flex-wrap:wrap; gap:8px; }
        .collapsed-note {
            margin-top:10px;
            padding:12px 14px;
            border:1px dashed var(--line-strong);
            border-radius:14px;
            background:#f8fbff;
            color:var(--muted);
            font-size:13px;
            line-height:1.5;
        }
        details summary {
            cursor:pointer;
            font-size:13px;
            font-weight:800;
            color:var(--accent);
            letter-spacing:.04em;
        }
        .stream { margin-top:16px; border-top:1px solid var(--line); padding-top:16px; }
        .stream-item { padding:12px 0; border-bottom:1px dashed var(--line); }
        .stream-item:last-child { border-bottom:0; }
        .stream-head, .lineage-header { display:flex; justify-content:space-between; gap:12px; margin-bottom:4px; }
        .stream-event { font-weight:700; color:var(--navy); }
        .empty { padding:36px 20px; text-align:center; color:var(--muted); }
        .lineage-node { border-left:2px solid var(--line); margin:8px 0 0 10px; padding-left:12px; }
        .lineage-node.selected { border-left-color:var(--accent); }
        .task-id strong { display:block; font-size:14px; line-height:1.4; }
        .task-id .mono { display:block; margin-top:4px; color:var(--muted); }
        .table-actions { display:flex; gap:8px; align-items:center; }
        .detail-trigger {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-height:34px;
            padding:0 12px;
            border-radius:999px;
            border:1px solid var(--line);
            background:#fff;
            color:var(--accent-strong);
            font:inherit;
            font-size:12px;
            font-weight:700;
            cursor:pointer;
        }
        @media (max-width:1480px) { .cards { grid-template-columns:repeat(2, minmax(0,1fr)); } }
        @media (max-width:1280px) { .hero, .workspace, .status-grid { grid-template-columns:1fr; } .cards { grid-template-columns:repeat(2, minmax(0,1fr)); } .detail-dialog { width:min(100vw - 28px, 100%); } }
        @media (max-width:960px) { .topbar-card { flex-direction:column; align-items:flex-start; } .nav { width:100%; } .operator-chip { margin-left:0; padding-left:0; border-left:0; min-width:0; } .shell { width:min(100vw - 20px, 100%); } .toolbar-summary { flex-direction:column; align-items:flex-start; } }
        @media (max-width:720px) { h1 { font-size:32px; } .cards, .toolbar.primary, .toolbar.secondary, .toolbar.actions, .detail-grid { grid-template-columns:1fr; } .process-footer { align-items:flex-start; } }
    </style>
</head>
<body>
<div class="shell">
    <section class="topbar">
        <div class="topbar-card">
            <div class="brand">
                <div class="brand-mark"></div>
                <div class="brand-copy">
                    <small>Comodísimos Operations</small>
                    <strong>WorkerHub Monitor</strong>
                    <span>Colas, replays, lineage y operación diaria en una sola consola.</span>
                </div>
            </div>
            <div class="nav">
                <a class="nav-link active" href="{{ route('monitor.dashboard') }}">Monitor</a>
                <a class="nav-link" href="{{ url('/horizon') }}">Horizon</a>
                @if (is_array($operator ?? null))
                    <div class="operator-chip">
                        <strong>{{ $operator['name'] ?? $operator['email'] ?? 'sesion activa' }}</strong>
                        <span>{{ $operator['email'] ?? ($accessChannel ?? 'web_session') }}</span>
                    </div>
                @endif
                @if (($accessChannel ?? 'web') === 'web_session')
                    <form method="post" action="{{ route('workerhub.logout') }}" style="margin:0;">
                        @csrf
                        <button type="submit">Salir</button>
                    </form>
                @endif
            </div>
        </div>
    </section>
    <section class="hero">
        <article class="panel headline">
            <div class="eyebrow">WorkerHub Operations</div>
            <h1>Centro operativo de colas y migracion.</h1>
            <p class="lead">Bandeja central para revisar estado, tarea programada, incidentes y detalle tecnico sin duplicar bloques visuales.</p>
            <div class="headline-meta">
                <div class="headline-pill">
                    <span>Canal</span>
                    <strong id="access-channel">{{ $accessChannel ?? 'web' }}</strong>
                </div>
                @if (is_array($operator ?? null))
                    <div class="headline-pill">
                        <span>Operador</span>
                        <strong>{{ $operator['name'] ?? $operator['email'] ?? 'sesion activa' }}</strong>
                    </div>
                @endif
                <div class="headline-pill">
                    <span>Acceso</span>
                    <strong>Monitor y Horizon unificados</strong>
                </div>
            </div>
            <div class="status-dot">Infraestructura operativa</div>
            <div class="status-grid">
                <div class="status-pill">
                    <span>Realtime</span>
                    <strong id="socket-state">Polling</strong>
                    <small id="socket-detail">Esperando configuracion de socket...</small>
                </div>
                <div class="status-pill">
                    <span>Despacho</span>
                    <strong id="dispatch-mode">-</strong>
                    <small id="health-detail">Health operativo pendiente...</small>
                </div>
                <div class="status-pill">
                    <span>Backoffice</span>
                    <strong id="backoffice-state">Pendiente</strong>
                    <small>La sesion operativa y el acceso a Horizon comparten esta dependencia.</small>
                </div>
            </div>
            @if (false)
            <h1>Monitor azul para operación de colas, DLQ y replay.</h1>
            <p class="lead">Vista central para tareas en Kafka o `direct_queue`, encolamiento Redis/Horizon y migraciones documentales a Siesa. El panel prioriza claridad operativa, lectura rápida y trazabilidad completa por tarea.</p>
            <p class="hint" style="margin-top:14px;">
                Canal de acceso: <strong id="access-channel">{{ $accessChannel ?? 'web' }}</strong>
                @if (is_array($operator ?? null))
                    | Operador: <strong>{{ $operator['name'] ?? $operator['email'] ?? 'sesion activa' }}</strong>
                @endif
            </p>
            <p class="hint" style="margin-top:12px;">Acceso unificado con backoffice: la misma sesión habilita este monitor y el dashboard de Horizon.</p>
            @endif
        </article>
    </section>

    <section class="cards">
        <article class="panel metric"><div class="metric-keyline"></div><div class="metric-label">Total</div><div class="metric-value" data-key="total">0</div><div class="metric-subtle">Volumen acumulado de tareas registradas</div></article>
        <article class="panel metric"><div class="metric-keyline"></div><div class="metric-label">Procesando</div><div class="metric-value" data-key="processing">0</div><div class="metric-subtle">Carga activa en ejecucion</div></article>
        <article class="panel metric"><div class="metric-keyline"></div><div class="metric-label">Completadas</div><div class="metric-value" data-key="completed">0</div><div class="metric-subtle">Tareas cerradas sin error</div></article>
        <article class="panel metric"><div class="metric-keyline"></div><div class="metric-label">Dead Letters</div><div class="metric-value" data-key="dead_letters">0</div><div class="metric-subtle">Incidentes que requieren accion</div></article>
    </section>
    <section class="workspace">
        <article class="panel">
            <div class="toolbar primary">
                <div class="field">
                    <label for="filter-source">Origen</label>
                    <input id="filter-source" type="text" placeholder="crm, erp, api">
                </div>
                <div class="field">
                    <label for="filter-schedule-name">Tarea programada</label>
                    <input id="filter-schedule-name" type="text" placeholder="ImportarRecibos">
                </div>
                <div class="field">
                    <label for="filter-process-key">Proceso</label>
                    <select id="filter-process-key">
                        <option value="">Todos los procesos</option>
                        @foreach (($processDefinitions ?? []) as $definition)
                            <option value="{{ $definition['key'] }}">{{ $definition['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="filter-status">Estado</label>
                    <select id="filter-status">
                        <option value="">Todos los estados</option>
                        <option value="received">received</option>
                        <option value="published">published</option>
                        <option value="queued">queued</option>
                        <option value="processing">processing</option>
                        <option value="completed">completed</option>
                        <option value="failed">failed</option>
                        <option value="rejected">rejected</option>
                    </select>
                </div>
                <div class="field">
                    <label for="filter-type">Tipo</label>
                    <select id="filter-type">
                        <option value="">Todos los tipos</option>
                        <option value="document_migration">document_migration</option>
                    </select>
                </div>
                <div class="field">
                    <label for="filter-mode">Vista</label>
                    <select id="filter-mode">
                        <option value="all">Vista general</option>
                        <option value="dead_letters">Solo DLQ</option>
                    </select>
                </div>
                <div class="field">
                    <label for="filter-priority">Prioridad</label>
                    <select id="filter-priority">
                        <option value="">Todas las prioridades</option>
                        <option value="default">default</option>
                        <option value="high">high</option>
                    </select>
                </div>
            </div>
            <div class="toolbar secondary">
                <div class="field">
                    <label for="filter-queue">Queue</label>
                    <input id="filter-queue" type="text" placeholder="migration-high">
                </div>
                <div class="field">
                    <label for="filter-date-from">Desde</label>
                    <input id="filter-date-from" type="date">
                </div>
                <div class="field">
                    <label for="filter-date-to">Hasta</label>
                    <input id="filter-date-to" type="date">
                </div>
                <div class="field">
                    <label for="filter-replay-mode">Replay</label>
                    <select id="filter-replay-mode">
                        <option value="all">Originales y replays</option>
                        <option value="originals">Solo originales</option>
                        <option value="replays">Solo replays</option>
                    </select>
                </div>
                <div class="field">
                    <label for="filter-error-mode">Error</label>
                    <select id="filter-error-mode">
                        <option value="all">Con y sin error</option>
                        <option value="with_error">Solo con error</option>
                        <option value="without_error">Solo sin error</option>
                    </select>
                </div>
            </div>
            <div class="toolbar actions">
                <button id="refresh-button" type="button">Actualizar</button>
                <button id="reset-filters-button" class="secondary" type="button">Limpiar filtros</button>
                <button id="retry-batch-button" class="secondary" type="button">Retry lote</button>
                <button id="retry-filtered-button" class="secondary" type="button">Retry filtrado</button>
                <button id="export-tasks-button" class="secondary" type="button">Exportar tareas</button>
                <button id="export-dead-letters-button" class="secondary" type="button">Exportar DLQ</button>
                <button id="export-actions-button" class="secondary" type="button">Exportar acciones</button>
            </div>
            <div class="toolbar-summary">
                <div>
                    <strong>Bandeja operativa</strong>
                    <span>Filtra por proceso, tarea programada o estado. El detalle tecnico queda concentrado a la derecha.</span>
                </div>
                <div class="hint" id="filter-summary">Sin filtros activos.</div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Tarea</th>
                        <th></th>
                        <th>Detalle</th>
                        <th>Estado</th>
                        <th>Proceso</th>
                        <th>Programada</th>
                        <th>Tipo</th>
                        <th>Origen</th>
                        <th>Queue</th>
                        <th>Intentos</th>
                        <th>Replay</th>
                    </tr>
                    </thead>
                    <tbody id="tasks-body">
                    <tr><td class="empty" colspan="11">Cargando tareas...</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="process-footer">
                <div class="process-footer-copy">
                    <strong>Resumen por proceso</strong>
                    <span>Usa el selector de proceso en los filtros para escalar esta vista cuando la operacion crezca.</span>
                </div>
                <div class="process-pills">
                    <span class="process-pill" id="process-summary-name">Todos los procesos</span>
                    <span class="process-pill" id="process-summary-total">0 tareas</span>
                    <span class="process-pill" id="process-summary-failed">0 incidentes</span>
                </div>
            </div>
        </article>

    </section>

    <div class="detail-modal" id="detail-modal" aria-hidden="true">
        <div class="detail-backdrop" id="detail-backdrop"></div>
        <div class="detail-dialog" role="dialog" aria-modal="true" aria-labelledby="detail-title">
            <div class="detail-header">
                <div>
                    <div class="eyebrow">Detalle</div>
                    <h2 id="detail-title">Selecciona una tarea</h2>
                    <p class="hint" id="detail-subtitle">Aqui veras contexto, lineage, eventos y acciones sin saturar la bandeja principal.</p>
                </div>
                <button id="detail-close" class="detail-close" type="button" aria-label="Cerrar detalle">×</button>
            </div>
            <div class="detail">
                <div class="detail-tabs">
                    <button class="detail-tab active" type="button" data-detail-tab="summary">Resumen</button>
                    <button class="detail-tab" type="button" data-detail-tab="lineage">Lineage</button>
                    <button class="detail-tab" type="button" data-detail-tab="events">Eventos</button>
                    <button class="detail-tab" type="button" data-detail-tab="actions">Acciones</button>
                </div>
                <div class="detail-pane active" data-detail-pane="summary">
                    <div class="detail-grid" id="detail-grid"></div>
                    <div class="actions" style="display:flex; gap:10px; margin-bottom:12px;">
                        <button id="retry-button" type="button" disabled>Reencolar tarea</button>
                        <button id="open-api-button" class="secondary" type="button" disabled>Abrir JSON</button>
                    </div>
                    <div class="hint" id="detail-error"></div>
                </div>
                <div class="detail-pane" data-detail-pane="lineage">
                    <div class="stream" style="margin-top:0; border-top:0; padding-top:0;">
                        <div class="eyebrow">Lineage</div>
                        <div id="detail-lineage" class="hint">Sin datos.</div>
                    </div>
                </div>
                <div class="detail-pane" data-detail-pane="events">
                    <div class="stream" style="margin-top:0; border-top:0; padding-top:0;">
                        <div class="eyebrow">Eventos</div>
                        <div id="detail-events" class="hint">Sin datos.</div>
                    </div>
                </div>
                <div class="detail-pane" data-detail-pane="actions">
                    <div class="stream" style="margin-top:0; border-top:0; padding-top:0;">
                        <div class="eyebrow">Acciones operativas</div>
                        <div id="detail-actions" class="hint">Sin datos.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.socket.io/4.7.5/socket.io.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/laravel-echo@1.16.1/dist/echo.iife.js"></script>
<script>
window.workerhubOperatorToken = @json($operatorToken ?? '');
window.workerhubProcessDefinitions = @json($processDefinitions ?? []);
const processDefinitionMap = new Map((window.workerhubProcessDefinitions || []).map(item => [item.key, item]));

const FILTER_STORAGE_KEY = 'workerhub.monitor.filters';
const TASK_STORAGE_KEY = 'workerhub.monitor.selected_task_id';
const defaultFilters = { status: '', type: '', source: '', process_key: '', schedule_name: '', mode: 'all', priority: '', queue: '', date_from: '', date_to: '', replay_mode: 'all', error_mode: 'all' };
const state = {
    filters: loadStoredFilters(),
    tasks: [],
    actions: [],
    lineage: null,
    selectedTaskId: loadStoredTaskId(),
    selectedIds: new Set(),
    echo: null,
    refreshTimer: null,
    summary: null,
    detailModalOpen: false,
};
const nodes = {
    body: document.getElementById('tasks-body'),
    detailTitle: document.getElementById('detail-title'),
    detailSubtitle: document.getElementById('detail-subtitle'),
    detailGrid: document.getElementById('detail-grid'),
    detailEvents: document.getElementById('detail-events'),
    detailActions: document.getElementById('detail-actions'),
    detailLineage: document.getElementById('detail-lineage'),
    detailError: document.getElementById('detail-error'),
    retryButton: document.getElementById('retry-button'),
    retryBatchButton: document.getElementById('retry-batch-button'),
    retryFilteredButton: document.getElementById('retry-filtered-button'),
    exportTasksButton: document.getElementById('export-tasks-button'),
    exportDeadLettersButton: document.getElementById('export-dead-letters-button'),
    exportActionsButton: document.getElementById('export-actions-button'),
    openApiButton: document.getElementById('open-api-button'),
    socketState: document.getElementById('socket-state'),
    socketDetail: document.getElementById('socket-detail'),
    healthDetail: document.getElementById('health-detail'),
    dispatchMode: document.getElementById('dispatch-mode'),
    backofficeState: document.getElementById('backoffice-state'),
    filterSummary: document.getElementById('filter-summary'),
    processSummaryName: document.getElementById('process-summary-name'),
    processSummaryTotal: document.getElementById('process-summary-total'),
    processSummaryFailed: document.getElementById('process-summary-failed'),
    detailModal: document.getElementById('detail-modal'),
    detailBackdrop: document.getElementById('detail-backdrop'),
    detailClose: document.getElementById('detail-close'),
    detailTabs: Array.from(document.querySelectorAll('[data-detail-tab]')),
    detailPanes: Array.from(document.querySelectorAll('[data-detail-pane]')),
};
const filterInputs = {
    source: document.getElementById('filter-source'),
    schedule_name: document.getElementById('filter-schedule-name'),
    process_key: document.getElementById('filter-process-key'),
    status: document.getElementById('filter-status'),
    type: document.getElementById('filter-type'),
    mode: document.getElementById('filter-mode'),
    priority: document.getElementById('filter-priority'),
    queue: document.getElementById('filter-queue'),
    date_from: document.getElementById('filter-date-from'),
    date_to: document.getElementById('filter-date-to'),
    replay_mode: document.getElementById('filter-replay-mode'),
    error_mode: document.getElementById('filter-error-mode'),
};

hydrateFilters();
renderFilterSummary();
activateDetailTab('summary');

document.getElementById('refresh-button').addEventListener('click', () => refreshAll(true));
document.getElementById('reset-filters-button').addEventListener('click', () => {
    state.filters = { ...defaultFilters };
    state.selectedIds.clear();
    saveFilters();
    hydrateFilters();
    renderFilterSummary();
    refreshAll(false);
});

Object.entries(filterInputs).forEach(([key, input]) => {
    input.addEventListener('change', () => {
        state.filters[key] = input.value;
        saveFilters();
        renderFilterSummary();
        refreshAll(true);
    });
});

nodes.detailTabs.forEach(button => {
    button.addEventListener('click', () => activateDetailTab(button.dataset.detailTab));
});
nodes.detailClose.addEventListener('click', closeDetailModal);
nodes.detailBackdrop.addEventListener('click', closeDetailModal);
document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
        closeDetailModal();
    }
});

nodes.exportTasksButton.addEventListener('click', () => window.open(`/api/monitor/tasks/export?${buildTaskParams()}`, '_blank'));
nodes.exportDeadLettersButton.addEventListener('click', () => {
    const params = buildTaskParams();
    params.set('only_dead_letters', '1');
    window.open(`/api/monitor/dead-letters/export?${params.toString()}`, '_blank');
});
nodes.exportActionsButton.addEventListener('click', () => window.open(`/api/monitor/actions/export?${buildActionParams()}`, '_blank'));

nodes.retryButton.addEventListener('click', async () => {
    if (!state.selectedTaskId || !window.confirm(`Se reencolara la tarea ${state.selectedTaskId}. Deseas continuar?`)) {
        return;
    }

    nodes.retryButton.disabled = true;
    nodes.detailError.textContent = '';
    const response = await fetch(`/api/monitor/tasks/${state.selectedTaskId}/retry`, { method: 'POST', headers: { 'Accept': 'application/json', ...operatorHeaders() } });
    const data = await response.json();

    if (!response.ok) {
        nodes.detailError.textContent = data.message || 'No fue posible reencolar la tarea.';
        nodes.retryButton.disabled = false;
        return;
    }

    nodes.detailError.textContent = `Replay aceptado. Nueva tarea: ${data.task_id}`;
    await refreshAll(true);
    await showTask(data.task_id, { openModal: true });
});

nodes.retryBatchButton.addEventListener('click', async () => {
    const taskIds = Array.from(state.selectedIds);
    if (taskIds.length === 0) {
        nodes.detailError.textContent = 'Selecciona al menos una tarea fallida o rechazada.';
        return;
    }
    if (!window.confirm(`Se reencolaran ${taskIds.length} tareas seleccionadas. Deseas continuar?`)) {
        return;
    }

    const response = await fetch('/api/monitor/tasks/retry-batch', {
        method: 'POST',
        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', ...operatorHeaders() },
        body: JSON.stringify({ task_ids: taskIds }),
    });
    const data = await response.json();

    if (!response.ok) {
        nodes.detailError.textContent = data.message || 'No fue posible ejecutar el replay por lote.';
        return;
    }

    nodes.detailError.textContent = `Replay lote: ${data.accepted_count} aceptadas, ${data.error_count} con error.`;
    state.selectedIds.clear();
    await refreshAll(true);
});

nodes.retryFilteredButton.addEventListener('click', async () => {
    if (!window.confirm('Se reencolaran tareas terminales usando los filtros actuales. Deseas continuar?')) {
        return;
    }

    const response = await fetch('/api/monitor/tasks/retry-filtered', {
        method: 'POST',
        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', ...operatorHeaders() },
        body: JSON.stringify({ ...getTaskFilterPayload(), limit: 100 }),
    });
    const data = await response.json();

    if (!response.ok) {
        nodes.detailError.textContent = data.message || 'No fue posible ejecutar el retry filtrado.';
        return;
    }

    nodes.detailError.textContent = `Retry filtrado: ${data.accepted_count}/${data.matched_count} aceptadas, ${data.error_count} con error.`;
    state.selectedIds.clear();
    await refreshAll(true);
});

nodes.openApiButton.addEventListener('click', () => {
    if (!state.selectedTaskId) {
        return;
    }
    window.open(`/api/monitor/tasks/${state.selectedTaskId}?${buildTokenQuery().toString()}`, '_blank');
});

function loadStoredFilters() {
    try {
        return { ...defaultFilters, ...(JSON.parse(window.localStorage.getItem(FILTER_STORAGE_KEY) || '{}')) };
    } catch (error) {
        return { ...defaultFilters };
    }
}

function loadStoredTaskId() {
    return window.localStorage.getItem(TASK_STORAGE_KEY) || null;
}

function saveFilters() {
    window.localStorage.setItem(FILTER_STORAGE_KEY, JSON.stringify(state.filters));
}

function saveSelectedTaskId(taskId) {
    if (!taskId) {
        window.localStorage.removeItem(TASK_STORAGE_KEY);
        return;
    }
    window.localStorage.setItem(TASK_STORAGE_KEY, taskId);
}

function hydrateFilters() {
    Object.entries(filterInputs).forEach(([key, input]) => {
        input.value = state.filters[key] || '';
    });
}

function operatorHeaders() {
    const headers = {};
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    if (csrfToken) {
        headers['X-CSRF-TOKEN'] = csrfToken;
    }

    if (window.workerhubOperatorToken) {
        headers['X-WorkerHub-Token'] = window.workerhubOperatorToken;
    }

    return headers;
}

function buildTokenQuery() {
    const params = new URLSearchParams();
    if (window.workerhubOperatorToken) {
        params.set('token', window.workerhubOperatorToken);
    }
    return params;
}

function getTaskFilterPayload() {
    return {
        status: state.filters.status,
        type: state.filters.type,
        source: state.filters.source,
        process_key: state.filters.process_key,
        schedule_name: state.filters.schedule_name,
        priority: state.filters.priority,
        queue: state.filters.queue,
        date_from: state.filters.date_from,
        date_to: state.filters.date_to,
        replay_mode: state.filters.replay_mode,
        error_mode: state.filters.error_mode,
        only_dead_letters: state.filters.mode === 'dead_letters',
    };
}

function buildTaskParams() {
    const params = buildTokenQuery();
    Object.entries(getTaskFilterPayload()).forEach(([key, value]) => {
        if (value !== null && value !== undefined && String(value) !== '' && String(value) !== 'false') {
            params.set(key, String(value));
        }
    });
    return params;
}

function buildActionParams() {
    const params = buildTokenQuery();
    if (state.selectedTaskId) {
        params.set('worker_task_id', state.selectedTaskId);
    }
    if (state.filters.date_from) {
        params.set('date_from', state.filters.date_from);
    }
    if (state.filters.date_to) {
        params.set('date_to', state.filters.date_to);
    }
    return params;
}

function badgeClass(value) {
    return `badge ${String(value || '').toLowerCase() || 'default'}`;
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function renderFilterSummary() {
    const labels = [];
    if (state.filters.mode === 'dead_letters') labels.push('modo=DLQ');
    if (state.filters.process_key) {
        const processLabel = processDefinitionMap.get(state.filters.process_key)?.label || state.filters.process_key;
        labels.push(`proceso=${processLabel}`);
    }
    if (state.filters.schedule_name) labels.push(`programada=${state.filters.schedule_name}`);
    if (state.filters.status) labels.push(`estado=${state.filters.status}`);
    if (state.filters.type) labels.push(`tipo=${state.filters.type}`);
    if (state.filters.source) labels.push(`origen=${state.filters.source}`);
    if (state.filters.priority) labels.push(`prioridad=${state.filters.priority}`);
    if (state.filters.queue) labels.push(`queue=${state.filters.queue}`);
    if (state.filters.replay_mode !== 'all') labels.push(`replays=${state.filters.replay_mode}`);
    if (state.filters.error_mode !== 'all') labels.push(`errores=${state.filters.error_mode}`);
    if (state.filters.date_from) labels.push(`desde=${state.filters.date_from}`);
    if (state.filters.date_to) labels.push(`hasta=${state.filters.date_to}`);

    nodes.filterSummary.textContent = labels.length === 0 ? 'Sin filtros activos.' : `Filtros activos: ${labels.join(' | ')}`;
}

function renderProcessRail(summary) {
    const processSummary = Array.isArray(summary?.processes) ? summary.processes : [];
    const byKey = new Map(processSummary.map(item => [item.key, item]));
    const total = summary?.total ?? 0;
    const failed = summary?.dead_letters ?? 0;
    const key = state.filters.process_key || '';
    const definition = key ? processDefinitionMap.get(key) : null;
    const selected = key ? (byKey.get(key) || { key, label: definition?.label || key, total: 0, failed: 0 }) : null;

    if (nodes.processSummaryName) {
        nodes.processSummaryName.textContent = selected?.label || 'Todos los procesos';
    }

    if (nodes.processSummaryTotal) {
        nodes.processSummaryTotal.textContent = `${selected?.total ?? total} tareas`;
    }

    if (nodes.processSummaryFailed) {
        nodes.processSummaryFailed.textContent = `${selected?.failed ?? failed} incidentes`;
    }
}

function activateDetailTab(tab) {
    nodes.detailTabs.forEach(button => {
        button.classList.toggle('active', button.dataset.detailTab === tab);
    });

    nodes.detailPanes.forEach(pane => {
        pane.classList.toggle('active', pane.dataset.detailPane === tab);
    });
}

function openDetailModal() {
    state.detailModalOpen = true;
    nodes.detailModal.classList.add('is-open');
    nodes.detailModal.setAttribute('aria-hidden', 'false');
}

function closeDetailModal() {
    state.detailModalOpen = false;
    nodes.detailModal.classList.remove('is-open');
    nodes.detailModal.setAttribute('aria-hidden', 'true');
}

async function fetchJson(url) {
    const response = await fetch(url, {
        headers: { 'Accept': 'application/json', ...operatorHeaders() },
        credentials: 'same-origin',
    });
    if (!response.ok) {
        throw new Error(`Request failed: ${response.status}`);
    }
    return response.json();
}

async function refreshSummary() {
    const params = buildTokenQuery();
    params.set('silent', '1');
    const summary = await fetchJson(`/api/monitor/tasks/summary?${params.toString()}`);
    state.summary = summary;
    document.querySelectorAll('[data-key]').forEach(node => {
        node.textContent = summary[node.dataset.key] ?? '0';
    });
    renderProcessRail(summary);
}

async function refreshHealth() {
    try {
        const payload = await fetchJson(`/api/health/workerhub?${buildTokenQuery().toString()}`);
        const alerts = Array.isArray(payload.alerts) ? payload.alerts : [];
        nodes.dispatchMode.textContent = payload.checks?.kafka?.dispatch_mode || 'desconocido';
        nodes.backofficeState.textContent = payload.checks?.backoffice?.ok ? 'Disponible' : 'Degradado';
        nodes.healthDetail.textContent = alerts.length === 0
            ? `Health ${payload.status}: sin alertas operativas.`
            : `Health ${payload.status}: ${alerts.join(' | ')}`;
    } catch (error) {
        nodes.dispatchMode.textContent = 'error';
        nodes.backofficeState.textContent = 'Error';
        nodes.healthDetail.textContent = `Health degradado: ${error.message}`;
    }
}

async function refreshTasks(keepSelection = false) {
    const params = buildTaskParams();
    params.set('silent', '1');
    const url = state.filters.mode === 'dead_letters'
        ? `/api/monitor/dead-letters?${params.toString()}`
        : `/api/monitor/tasks?${params.toString()}`;
    const payload = await fetchJson(url);
    state.tasks = payload.data || [];
    renderTasks();

    if (!keepSelection || !state.selectedTaskId) {
        if (state.tasks[0]) {
            await showTask(state.tasks[0].id, { openModal: false });
        } else {
            renderTaskDetail(null);
        }
        return;
    }

    const selected = state.tasks.find(task => task.id === state.selectedTaskId);
    if (selected) {
        await showTask(selected.id, { openModal: false });
    } else if (state.tasks[0]) {
        await showTask(state.tasks[0].id, { openModal: false });
    } else {
        renderTaskDetail(null);
    }
}

async function refreshActions() {
    const params = buildActionParams();
    params.set('silent', '1');
    const payload = await fetchJson(`/api/monitor/actions?${params.toString()}`);
    state.actions = payload.data || [];
    renderActionHistory();
}

function renderTasks() {
    if (state.tasks.length === 0) {
        nodes.body.innerHTML = '<tr><td class="empty" colspan="11">No hay tareas para los filtros actuales.</td></tr>';
        return;
    }

    nodes.body.innerHTML = state.tasks.map(task => `
        <tr data-task-id="${escapeHtml(task.id)}" class="${task.id === state.selectedTaskId ? 'is-selected' : ''}">
            <td class="task-id"><strong>${escapeHtml(task.id)}</strong><span class="mono">${escapeHtml(task.kafka_key || '-')}</span></td>
            <td><input class="checkbox task-selector" type="checkbox" value="${escapeHtml(task.id)}" ${state.selectedIds.has(task.id) ? 'checked' : ''} ${!['failed', 'rejected'].includes(task.status) ? 'disabled' : ''}></td>
            <td><button class="detail-trigger" type="button" data-detail-task-id="${escapeHtml(task.id)}">Ver detalle</button></td>
            <td><span class="${badgeClass(task.status)}">${escapeHtml(task.status)}</span></td>
            <td><span class="${badgeClass('default')}">${escapeHtml(task.process_label || 'General')}</span></td>
            <td>${escapeHtml(task.schedule_name || task.task_name || '-')}</td>
            <td>${escapeHtml(task.type)}</td>
            <td>${escapeHtml(task.source || '-')}</td>
            <td>${escapeHtml(task.queue || '-')}</td>
            <td>${escapeHtml(task.attempts ?? 0)}</td>
            <td>${task.parent_task_id ? `<span class="${badgeClass('high')}">de ${escapeHtml(task.parent_task_id)}</span>` : '-'}</td>
        </tr>
    `).join('');

    nodes.body.querySelectorAll('.task-selector').forEach(input => {
        input.addEventListener('click', event => event.stopPropagation());
        input.addEventListener('change', event => {
            if (event.target.checked) {
                state.selectedIds.add(event.target.value);
            } else {
                state.selectedIds.delete(event.target.value);
            }
        });
    });

    nodes.body.querySelectorAll('[data-detail-task-id]').forEach(button => {
        button.addEventListener('click', async event => {
            event.stopPropagation();
            await showTask(button.dataset.detailTaskId, { openModal: true });
        });
    });
}

async function showTask(taskId, { openModal = false } = {}) {
    state.selectedTaskId = taskId;
    saveSelectedTaskId(taskId);
    renderTasks();

    const [task, lineage] = await Promise.all([
        fetchJson(`/api/monitor/tasks/${taskId}?${buildTokenQuery().toString()}`),
        fetchJson(`/api/monitor/tasks/${taskId}/lineage?${buildTokenQuery().toString()}`),
    ]);

    state.lineage = lineage;
    renderTaskDetail(task);
    if (openModal || state.detailModalOpen) {
        openDetailModal();
    }
}

function renderTaskDetail(task) {
    if (!task) {
        state.selectedTaskId = null;
        state.lineage = null;
        saveSelectedTaskId(null);
        closeDetailModal();
        nodes.detailTitle.textContent = 'Selecciona una tarea';
        nodes.detailSubtitle.textContent = 'Aqui veras eventos, payload resumido y opciones operativas.';
        nodes.detailGrid.innerHTML = '';
        nodes.detailLineage.innerHTML = '<div class="hint">Sin datos.</div>';
        nodes.detailEvents.innerHTML = '<div class="hint">Sin datos.</div>';
        nodes.detailActions.innerHTML = '<div class="hint">Sin datos.</div>';
        nodes.retryButton.disabled = true;
        nodes.openApiButton.disabled = true;
        return;
    }

    state.selectedTaskId = task.id;
    nodes.detailTitle.textContent = task.id;
    nodes.detailSubtitle.textContent = `${task.process_label || 'General'} - ${task.schedule_name || task.task_name || 'sin tarea programada'} - ${task.source || 'sin origen'}`;
    nodes.retryButton.disabled = !['failed', 'rejected'].includes(task.status);
    nodes.openApiButton.disabled = false;

    const replays = Array.isArray(task.replays) ? task.replays.length : 0;
    const payload = task.payload || {};
    const detailItems = [
        { label: 'Estado', value: `<span class="${badgeClass(task.status)}">${escapeHtml(task.status)}</span>`, className: 'inline-badge' },
        { label: 'Prioridad', value: `<span class="${badgeClass(task.priority)}">${escapeHtml(task.priority || 'default')}</span>`, className: 'inline-badge' },
        { label: 'Proceso', value: escapeHtml(task.process_label || 'General') },
        { label: 'Programada', value: escapeHtml(task.schedule_name || task.task_name || '-') },
        { label: 'Cola', value: escapeHtml(task.queue || '-'), className: 'code' },
        { label: 'Intentos', value: escapeHtml(task.attempts ?? 0) },
        { label: 'Documento', value: escapeHtml(payload.document_id || '-'), className: 'code' },
        { label: 'Replay count', value: escapeHtml(replays) },
        { label: 'Parent task', value: escapeHtml(task.parent_task_id || '-'), className: 'code' },
        {
            label: 'Error',
            value: task.error_message
                ? `<details><summary>Ver detalle tecnico</summary><div class="detail-value error" style="margin-top:10px;">${escapeHtml(task.error_message)}</div></details>`
                : '<div class="collapsed-note">Sin error registrado para la tarea activa.</div>',
            full: true
        },
    ];

    nodes.detailGrid.innerHTML = detailItems.map(item => {
        const cardClass = item.full ? 'detail-card full' : 'detail-card';
        const valueClass = ['detail-value', item.className].filter(Boolean).join(' ');

        return `<div class="${cardClass}"><div class="detail-label">${item.label}</div><div class="${valueClass}">${item.value}</div></div>`;
    }).join('');

    const events = Array.isArray(task.events) ? task.events : [];
    nodes.detailEvents.innerHTML = events.length === 0
        ? '<div class="hint">La tarea no tiene eventos registrados.</div>'
        : events.map(event => `<div class="stream-item"><div class="stream-head"><span class="stream-event">${escapeHtml(event.event)}</span><span class="hint">${escapeHtml(event.created_at || '')}</span></div><div>${escapeHtml(event.message || 'Sin mensaje')}</div></div>`).join('');

    renderLineage();
    renderActionHistory();
}

function renderLineageNode(node) {
    const children = Array.isArray(node.children) ? node.children : [];
    return `<div class="lineage-node ${node.is_selected ? 'selected' : ''}">
        <div class="lineage-header"><strong>${escapeHtml(node.id)}</strong><span class="${badgeClass(node.status)}">${escapeHtml(node.status)}</span></div>
        <div class="lineage-meta">proceso=${escapeHtml(node.process_label || 'General')} | programada=${escapeHtml(node.schedule_name || '-')} | prioridad=${escapeHtml(node.priority || '-')} | queue=${escapeHtml(node.queue || '-')} | parent=${escapeHtml(node.parent_task_id || '-')}</div>
        <div class="hint">solicitado=${escapeHtml(node.requested_at || '-')} | completado=${escapeHtml(node.completed_at || '-')} | fallido=${escapeHtml(node.failed_at || '-')}</div>
        ${node.error_message ? `<div class="hint">error=${escapeHtml(node.error_message)}</div>` : ''}
        ${children.map(child => renderLineageNode(child)).join('')}
    </div>`;
}

function renderLineage() {
    if (!state.lineage || !state.lineage.lineage) {
        nodes.detailLineage.innerHTML = '<div class="hint">No hay lineage disponible.</div>';
        return;
    }
    nodes.detailLineage.innerHTML = renderLineageNode(state.lineage.lineage);
}

function renderActionHistory() {
    nodes.detailActions.innerHTML = state.actions.length === 0
        ? '<div class="hint">No hay acciones operativas recientes para esta vista.</div>'
        : state.actions.map(action => `<div class="stream-item"><div class="stream-head"><span class="stream-event">${escapeHtml(action.action)}</span><span class="hint">${escapeHtml(action.created_at || '')}</span></div><div>${escapeHtml(action.actor || 'sistema')} - ${escapeHtml(action.status || 'success')}</div><div class="mono">${escapeHtml(action.worker_task_id || '-')}</div></div>`).join('');
}

async function setupSockets() {
    try {
        const config = await fetchJson(`/api/monitor/socket-config?${buildTokenQuery().toString()}`);
        nodes.socketState.textContent = 'Sockets activos';
        nodes.socketDetail.textContent = `${config.host}:${config.port} - ${config.channels.monitor}`;
        if (!window.io || !window.Echo) {
            throw new Error('Echo client no disponible');
        }
        state.echo = new window.Echo({ broadcaster: 'socket.io', host: `${config.scheme}://${config.host}:${config.port}`, transports: ['websocket', 'polling'] });
        state.echo.channel(config.channels.monitor).listen('.worker-task.updated', async () => {
            await refreshAll(true);
        });
    } catch (error) {
        nodes.socketState.textContent = 'Polling';
        nodes.socketDetail.textContent = 'Socket no disponible, se mantiene actualizacion periodica.';
    }
}

async function refreshAll(keepSelection = false) {
    await Promise.all([refreshSummary(), refreshTasks(keepSelection), refreshActions(), refreshHealth()]);
}

async function boot() {
    await refreshAll(Boolean(state.selectedTaskId));
    await setupSockets();
    state.refreshTimer = setInterval(() => refreshAll(true), 8000);
}

boot().catch(error => {
    nodes.body.innerHTML = `<tr><td class="empty" colspan="10">Error cargando monitor: ${escapeHtml(error.message)}</td></tr>`;
    nodes.socketState.textContent = 'Error';
    nodes.socketDetail.textContent = error.message;
});
</script>
</body>
</html>
