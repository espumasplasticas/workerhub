<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>WorkerHub Monitor</title>
    <style>
        :root {
            --bg:#eef5ff;
            --panel:rgba(255,255,255,.88);
            --panel-strong:#ffffff;
            --ink:#10203d;
            --muted:#5d7298;
            --accent:#0d62d6;
            --accent-strong:#0848a2;
            --accent-soft:rgba(13,98,214,.12);
            --warn:#b26a12;
            --warn-soft:rgba(255,184,78,.18);
            --danger:#c73b55;
            --danger-soft:rgba(199,59,85,.12);
            --success:#138a63;
            --success-soft:rgba(19,138,99,.12);
            --line:rgba(26,90,176,.14);
            --shadow:0 26px 60px rgba(13,53,120,.12);
        }
        * { box-sizing:border-box; }
        body {
            margin:0;
            font-family:"Manrope","Segoe UI",sans-serif;
            color:var(--ink);
            background:
                radial-gradient(circle at top left, rgba(13,98,214,.18), transparent 28%),
                radial-gradient(circle at top right, rgba(82,146,255,.18), transparent 22%),
                linear-gradient(180deg, #f7fbff 0%, var(--bg) 100%);
        }
        .shell { max-width:1480px; margin:0 auto; padding:32px 20px 48px; }
        .topbar { margin-bottom:18px; }
        .topbar-card {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:18px;
            padding:18px 22px;
            border:1px solid var(--line);
            border-radius:24px;
            background:linear-gradient(145deg, rgba(255,255,255,.92), rgba(244,249,255,.84));
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
            color:var(--muted);
            text-transform:uppercase;
            letter-spacing:.18em;
            font-size:11px;
            font-weight:700;
        }
        .brand-copy strong {
            display:block;
            font-size:24px;
            line-height:1;
            font-family:"Instrument Sans","Segoe UI",sans-serif;
        }
        .brand-copy span { color:var(--muted); font-size:13px; }
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
            border:1px solid var(--line);
            background:#fff;
            color:var(--ink);
            text-decoration:none;
            font:inherit;
            font-size:13px;
            font-weight:700;
            cursor:pointer;
        }
        .nav-link.active, .nav-link:hover, .nav button:hover { background:var(--accent); border-color:var(--accent); color:#fff; }
        .operator-chip {
            margin-left:6px;
            padding-left:14px;
            border-left:1px solid var(--line);
            color:var(--muted);
            font-size:13px;
        }
        .operator-chip strong { display:block; color:var(--ink); }
        .hero { display:grid; grid-template-columns:2fr 1fr; gap:20px; margin-bottom:24px; }
        .panel { background:var(--panel); border:1px solid var(--line); border-radius:24px; box-shadow:var(--shadow); backdrop-filter:blur(14px); }
        .headline { padding:28px; }
        .eyebrow { text-transform:uppercase; letter-spacing:.18em; font-size:12px; color:var(--muted); margin-bottom:10px; font-weight:700; }
        h1 { margin:0 0 10px; font-size:44px; line-height:.95; font-family:"Instrument Sans","Segoe UI",sans-serif; }
        .lead { margin:0; color:var(--muted); font-size:17px; line-height:1.6; }
        .status-box { padding:24px; display:flex; flex-direction:column; justify-content:space-between; background:linear-gradient(145deg, rgba(13,98,214,.10), rgba(255,255,255,.96)); }
        .status-dot { display:inline-flex; align-items:center; gap:8px; font-size:14px; color:var(--muted); }
        .status-dot::before { content:""; width:10px; height:10px; border-radius:50%; background:var(--accent); box-shadow:0 0 0 6px rgba(13,98,214,.10); }
        .cards { display:grid; grid-template-columns:repeat(6, minmax(0,1fr)); gap:14px; margin-bottom:24px; }
        .metric { padding:18px; min-height:120px; background:linear-gradient(180deg, rgba(255,255,255,.84), rgba(245,249,255,.84)); }
        .metric-label { font-size:13px; color:var(--muted); text-transform:uppercase; letter-spacing:.12em; }
        .metric-value { font-size:38px; margin:10px 0 6px; }
        .metric-subtle, .hint, .lineage-meta { color:var(--muted); font-size:14px; }
        .workspace { display:grid; grid-template-columns:1.8fr 1fr; gap:20px; }
        .toolbar { display:grid; gap:10px; padding:18px; }
        .toolbar.primary, .toolbar.secondary { grid-template-columns:repeat(5, minmax(0,1fr)); border-bottom:1px solid var(--line); }
        .toolbar.actions { grid-template-columns:repeat(6, minmax(0,1fr)); }
        .toolbar input, .toolbar select, .toolbar button, .actions button { border:1px solid var(--line); border-radius:14px; padding:12px 14px; font:inherit; background:#fff; color:var(--ink); }
        .toolbar button, .actions button { background:var(--accent); border-color:var(--accent); color:#fff; cursor:pointer; }
        .toolbar button.secondary, .actions button.secondary { background:#fff; color:var(--ink); border-color:var(--line); }
        .toolbar-summary { padding:0 18px 18px; border-bottom:1px solid var(--line); }
        .table-wrap { overflow:auto; max-height:720px; }
        table { width:100%; border-collapse:collapse; }
        th, td { padding:14px 18px; text-align:left; border-bottom:1px solid rgba(26,90,176,.10); vertical-align:top; }
        th { position:sticky; top:0; background:var(--panel); font-size:12px; text-transform:uppercase; letter-spacing:.12em; color:var(--muted); }
        tr:hover td { background:rgba(13,98,214,.04); }
        .badge { display:inline-flex; align-items:center; border-radius:999px; padding:6px 10px; font-size:12px; font-weight:700; letter-spacing:.04em; }
        .badge.received, .badge.published, .badge.queued, .badge.processing { background:var(--accent-soft); color:var(--accent); }
        .badge.completed { background:var(--success-soft); color:var(--success); }
        .badge.failed, .badge.rejected { background:var(--danger-soft); color:var(--danger); }
        .badge.default { background:rgba(96,119,156,.12); color:#4d6186; }
        .badge.high { background:var(--warn-soft); color:var(--warn); }
        .mono { font-family:Consolas, Monaco, monospace; font-size:12px; }
        .checkbox { width:16px; height:16px; accent-color:var(--accent); }
        .detail { padding:22px; }
        .detail h2 { margin-top:0; font-size:26px; }
        .detail-grid { display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:12px; margin-bottom:18px; }
        .detail-card { padding:14px; border-radius:16px; border:1px solid var(--line); background:#fff; }
        .detail-label { font-size:12px; color:var(--muted); text-transform:uppercase; letter-spacing:.12em; margin-bottom:6px; }
        .stream { margin-top:16px; border-top:1px solid var(--line); padding-top:16px; }
        .stream-item { padding:12px 0; border-bottom:1px dashed var(--line); }
        .stream-item:last-child { border-bottom:0; }
        .stream-head, .lineage-header { display:flex; justify-content:space-between; gap:12px; margin-bottom:4px; }
        .stream-event { font-weight:700; }
        .empty { padding:36px 20px; text-align:center; color:var(--muted); }
        .lineage-node { border-left:2px solid var(--line); margin:8px 0 0 10px; padding-left:12px; }
        .lineage-node.selected { border-left-color:var(--accent); }
        @media (max-width:1280px) { .hero, .workspace, .cards { grid-template-columns:1fr; } .toolbar.primary, .toolbar.secondary, .toolbar.actions { grid-template-columns:repeat(2, minmax(0,1fr)); } }
        @media (max-width:960px) { .topbar-card { flex-direction:column; align-items:flex-start; } .nav { width:100%; } .operator-chip { margin-left:0; padding-left:0; border-left:0; } }
        @media (max-width:720px) { h1 { font-size:32px; } .toolbar.primary, .toolbar.secondary, .toolbar.actions, .detail-grid { grid-template-columns:1fr; } }
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
            <h1>Monitor azul para operación de colas, DLQ y replay.</h1>
            <p class="lead">Vista central para tareas en Kafka o `direct_queue`, encolamiento Redis/Horizon y migraciones documentales a Siesa. El panel prioriza claridad operativa, lectura rápida y trazabilidad completa por tarea.</p>
            <p class="hint" style="margin-top:14px;">
                Canal de acceso: <strong id="access-channel">{{ $accessChannel ?? 'web' }}</strong>
                @if (is_array($operator ?? null))
                    | Operador: <strong>{{ $operator['name'] ?? $operator['email'] ?? 'sesion activa' }}</strong>
                @endif
            </p>
            <p class="hint" style="margin-top:12px;">Acceso unificado con backoffice: la misma sesión habilita este monitor y el dashboard de Horizon.</p>
        </article>
        <aside class="panel status-box">
            <div class="status-dot">Estado en tiempo real</div>
            <div>
                <div class="metric-value" id="socket-state">Polling</div>
                <div class="metric-subtle" id="socket-detail">Esperando configuracion de socket...</div>
                <div class="metric-subtle" id="health-detail" style="margin-top:12px;">Health operativo pendiente...</div>
            </div>
        </aside>
    </section>

    <section class="cards">
        <article class="panel metric"><div class="metric-label">Total</div><div class="metric-value" data-key="total">0</div><div class="metric-subtle">Tareas registradas</div></article>
        <article class="panel metric"><div class="metric-label">Procesando</div><div class="metric-value" data-key="processing">0</div><div class="metric-subtle">En ejecucion</div></article>
        <article class="panel metric"><div class="metric-label">Completadas</div><div class="metric-value" data-key="completed">0</div><div class="metric-subtle">Exitosas</div></article>
        <article class="panel metric"><div class="metric-label">Dead Letters</div><div class="metric-value" data-key="dead_letters">0</div><div class="metric-subtle">Fallidas o rechazadas</div></article>
        <article class="panel metric"><div class="metric-label">Replays</div><div class="metric-value" data-key="replayed">0</div><div class="metric-subtle">Reencoladas manualmente</div></article>
        <article class="panel metric"><div class="metric-label">Publicadas</div><div class="metric-value" data-key="published">0</div><div class="metric-subtle">Entregadas a Kafka</div></article>
    </section>
    <section class="workspace">
        <article class="panel">
            <div class="toolbar primary">
                <input id="filter-source" type="text" placeholder="Origen: crm, erp, api">
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
                <select id="filter-type">
                    <option value="">Todos los tipos</option>
                    <option value="document_migration">document_migration</option>
                </select>
                <select id="filter-mode">
                    <option value="all">Vista general</option>
                    <option value="dead_letters">Solo DLQ</option>
                </select>
                <select id="filter-priority">
                    <option value="">Todas las prioridades</option>
                    <option value="default">default</option>
                    <option value="high">high</option>
                </select>
            </div>
            <div class="toolbar secondary">
                <input id="filter-queue" type="text" placeholder="Queue: migration-high">
                <input id="filter-date-from" type="date">
                <input id="filter-date-to" type="date">
                <select id="filter-replay-mode">
                    <option value="all">Originales y replays</option>
                    <option value="originals">Solo originales</option>
                    <option value="replays">Solo replays</option>
                </select>
                <select id="filter-error-mode">
                    <option value="all">Con y sin error</option>
                    <option value="with_error">Solo con error</option>
                    <option value="without_error">Solo sin error</option>
                </select>
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
                <div class="hint" id="filter-summary">Sin filtros activos.</div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Tarea</th>
                        <th></th>
                        <th>Estado</th>
                        <th>Tipo</th>
                        <th>Origen</th>
                        <th>Queue</th>
                        <th>Intentos</th>
                        <th>Replay</th>
                    </tr>
                    </thead>
                    <tbody id="tasks-body">
                    <tr><td class="empty" colspan="8">Cargando tareas...</td></tr>
                    </tbody>
                </table>
            </div>
        </article>

        <aside class="panel detail">
            <div class="eyebrow">Detalle</div>
            <h2 id="detail-title">Selecciona una tarea</h2>
            <p class="hint" id="detail-subtitle">Aqui veras eventos, payload resumido y opciones operativas.</p>
            <div class="detail-grid" id="detail-grid"></div>
            <div class="actions" style="display:flex; gap:10px; margin-bottom:12px;">
                <button id="retry-button" type="button" disabled>Reencolar tarea</button>
                <button id="open-api-button" class="secondary" type="button" disabled>Abrir JSON</button>
            </div>
            <div class="hint" id="detail-error"></div>
            <div class="stream">
                <div class="eyebrow">Lineage</div>
                <div id="detail-lineage" class="hint">Sin datos.</div>
            </div>
            <div class="stream">
                <div class="eyebrow">Eventos</div>
                <div id="detail-events" class="hint">Sin datos.</div>
            </div>
            <div class="stream">
                <div class="eyebrow">Acciones operativas</div>
                <div id="detail-actions" class="hint">Sin datos.</div>
            </div>
        </aside>
    </section>
</div>
<script src="https://cdn.socket.io/4.7.5/socket.io.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/laravel-echo@1.16.1/dist/echo.iife.js"></script>
<script>
window.workerhubOperatorToken = @json($operatorToken ?? '');

const FILTER_STORAGE_KEY = 'workerhub.monitor.filters';
const TASK_STORAGE_KEY = 'workerhub.monitor.selected_task_id';
const defaultFilters = { status: '', type: '', source: '', mode: 'all', priority: '', queue: '', date_from: '', date_to: '', replay_mode: 'all', error_mode: 'all' };
const state = { filters: loadStoredFilters(), tasks: [], actions: [], lineage: null, selectedTaskId: loadStoredTaskId(), selectedIds: new Set(), echo: null, refreshTimer: null };
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
    filterSummary: document.getElementById('filter-summary'),
};
const filterInputs = {
    source: document.getElementById('filter-source'),
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
    await showTask(data.task_id);
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
    return window.workerhubOperatorToken ? { 'X-WorkerHub-Token': window.workerhubOperatorToken } : {};
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

async function fetchJson(url) {
    const response = await fetch(url, { headers: { 'Accept': 'application/json', ...operatorHeaders() } });
    if (!response.ok) {
        throw new Error(`Request failed: ${response.status}`);
    }
    return response.json();
}

async function refreshSummary() {
    const params = buildTokenQuery();
    params.set('silent', '1');
    const summary = await fetchJson(`/api/monitor/tasks/summary?${params.toString()}`);
    document.querySelectorAll('[data-key]').forEach(node => {
        node.textContent = summary[node.dataset.key] ?? '0';
    });
}

async function refreshHealth() {
    try {
        const payload = await fetchJson(`/api/health/workerhub?${buildTokenQuery().toString()}`);
        const alerts = Array.isArray(payload.alerts) ? payload.alerts : [];
        nodes.healthDetail.textContent = alerts.length === 0
            ? `Health ${payload.status}: sin alertas operativas.`
            : `Health ${payload.status}: ${alerts.join(' | ')}`;
    } catch (error) {
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
            await showTask(state.tasks[0].id);
        } else {
            renderTaskDetail(null);
        }
        return;
    }

    const selected = state.tasks.find(task => task.id === state.selectedTaskId);
    if (selected) {
        await showTask(selected.id);
    } else if (state.tasks[0]) {
        await showTask(state.tasks[0].id);
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
        nodes.body.innerHTML = '<tr><td class="empty" colspan="8">No hay tareas para los filtros actuales.</td></tr>';
        return;
    }

    nodes.body.innerHTML = state.tasks.map(task => `
        <tr data-task-id="${escapeHtml(task.id)}">
            <td><strong>${escapeHtml(task.id)}</strong><br><span class="mono">${escapeHtml(task.kafka_key || '-')}</span></td>
            <td><input class="checkbox task-selector" type="checkbox" value="${escapeHtml(task.id)}" ${state.selectedIds.has(task.id) ? 'checked' : ''} ${!['failed', 'rejected'].includes(task.status) ? 'disabled' : ''}></td>
            <td><span class="${badgeClass(task.status)}">${escapeHtml(task.status)}</span></td>
            <td>${escapeHtml(task.type)}</td>
            <td>${escapeHtml(task.source || '-')}</td>
            <td>${escapeHtml(task.queue || '-')}</td>
            <td>${escapeHtml(task.attempts ?? 0)}</td>
            <td>${task.parent_task_id ? `<span class="${badgeClass('high')}">de ${escapeHtml(task.parent_task_id)}</span>` : '-'}</td>
        </tr>
    `).join('');

    nodes.body.querySelectorAll('tr[data-task-id]').forEach(row => {
        row.addEventListener('click', async () => showTask(row.dataset.taskId));
    });

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
}

async function showTask(taskId) {
    state.selectedTaskId = taskId;
    saveSelectedTaskId(taskId);

    const [task, lineage] = await Promise.all([
        fetchJson(`/api/monitor/tasks/${taskId}?${buildTokenQuery().toString()}`),
        fetchJson(`/api/monitor/tasks/${taskId}/lineage?${buildTokenQuery().toString()}`),
    ]);

    state.lineage = lineage;
    renderTaskDetail(task);
}

function renderTaskDetail(task) {
    if (!task) {
        state.selectedTaskId = null;
        state.lineage = null;
        saveSelectedTaskId(null);
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
    nodes.detailSubtitle.textContent = `${task.type} - ${task.source || 'sin origen'} - ${task.kafka_topic || 'sin topic'}`;
    nodes.retryButton.disabled = !['failed', 'rejected'].includes(task.status);
    nodes.openApiButton.disabled = false;

    const replays = Array.isArray(task.replays) ? task.replays.length : 0;
    const payload = task.payload || {};
    nodes.detailGrid.innerHTML = [
        ['Estado', `<span class="${badgeClass(task.status)}">${escapeHtml(task.status)}</span>`],
        ['Prioridad', `<span class="${badgeClass(task.priority)}">${escapeHtml(task.priority || 'default')}</span>`],
        ['Cola', escapeHtml(task.queue || '-')],
        ['Intentos', escapeHtml(task.attempts ?? 0)],
        ['Documento', escapeHtml(payload.document_id || '-')],
        ['Replay count', escapeHtml(replays)],
        ['Parent task', escapeHtml(task.parent_task_id || '-')],
        ['Error', escapeHtml(task.error_message || '-')],
    ].map(([label, value]) => `<div class="detail-card"><div class="detail-label">${label}</div><div>${value}</div></div>`).join('');

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
        <div class="lineage-meta">prioridad=${escapeHtml(node.priority || '-')} | queue=${escapeHtml(node.queue || '-')} | parent=${escapeHtml(node.parent_task_id || '-')}</div>
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
    nodes.body.innerHTML = `<tr><td class="empty" colspan="8">Error cargando monitor: ${escapeHtml(error.message)}</td></tr>`;
    nodes.socketState.textContent = 'Error';
    nodes.socketDetail.textContent = error.message;
});
</script>
</body>
</html>
