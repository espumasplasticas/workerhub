<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>WorkerHub Monitor</title>
    <style>
        :root {
            --bg: #f3efe6;
            --panel: #fffdf8;
            --ink: #1d2a2f;
            --muted: #6b7b83;
            --accent: #0b6e4f;
            --accent-soft: #d9efe6;
            --warn: #b95c00;
            --warn-soft: #ffe3c2;
            --danger: #a33030;
            --danger-soft: #f8d9d9;
            --line: #d9d0c4;
            --shadow: 0 20px 50px rgba(29, 42, 47, 0.12);
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Georgia, "Times New Roman", serif;
            color: var(--ink);
            background:
                radial-gradient(circle at top left, rgba(11, 110, 79, 0.12), transparent 25%),
                linear-gradient(180deg, #f8f4ec 0%, var(--bg) 100%);
        }
        .shell { max-width: 1400px; margin: 0 auto; padding: 32px 20px 48px; }
        .hero { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 24px; }
        .panel { background: var(--panel); border: 1px solid rgba(217, 208, 196, 0.9); border-radius: 22px; box-shadow: var(--shadow); }
        .headline { padding: 28px; }
        .eyebrow { text-transform: uppercase; letter-spacing: 0.18em; font-size: 12px; color: var(--muted); margin-bottom: 10px; }
        h1 { margin: 0 0 10px; font-size: 42px; line-height: 1; }
        .lead { margin: 0; color: var(--muted); font-size: 17px; line-height: 1.6; }
        .status-box { padding: 24px; display: flex; flex-direction: column; justify-content: space-between; background: linear-gradient(135deg, rgba(11, 110, 79, 0.08), rgba(255, 253, 248, 1)); }
        .status-dot { display: inline-flex; align-items: center; gap: 8px; font-size: 14px; color: var(--muted); }
        .status-dot::before { content: ""; width: 10px; height: 10px; border-radius: 50%; background: var(--accent); box-shadow: 0 0 0 6px rgba(11, 110, 79, 0.08); }
        .cards { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 14px; margin-bottom: 24px; }
        .metric { padding: 18px; min-height: 120px; }
        .metric-label { font-size: 13px; color: var(--muted); text-transform: uppercase; letter-spacing: 0.12em; }
        .metric-value { font-size: 38px; margin: 10px 0 6px; }
        .metric-subtle { color: var(--muted); font-size: 14px; }
        .workspace { display: grid; grid-template-columns: 1.7fr 1fr; gap: 20px; }
        .toolbar { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)) auto auto auto; gap: 10px; padding: 18px; border-bottom: 1px solid var(--line); }
        .toolbar input, .toolbar select, .toolbar button, .actions button {
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 12px 14px;
            font: inherit;
            background: #fff;
            color: var(--ink);
        }
        .toolbar button, .actions button { background: var(--accent); border-color: var(--accent); color: #fff; cursor: pointer; }
        .toolbar button.secondary, .actions button.secondary { background: #fff; color: var(--ink); border-color: var(--line); }
        .table-wrap { overflow: auto; max-height: 680px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 14px 18px; text-align: left; border-bottom: 1px solid rgba(217, 208, 196, 0.7); vertical-align: top; }
        th { position: sticky; top: 0; background: var(--panel); font-size: 12px; text-transform: uppercase; letter-spacing: 0.12em; color: var(--muted); }
        tr:hover td { background: rgba(11, 110, 79, 0.03); }
        .badge { display: inline-flex; align-items: center; border-radius: 999px; padding: 6px 10px; font-size: 12px; font-weight: 700; letter-spacing: 0.04em; }
        .badge.received, .badge.published, .badge.queued, .badge.processing { background: var(--accent-soft); color: var(--accent); }
        .badge.completed { background: #dff5db; color: #245b1b; }
        .badge.failed, .badge.rejected { background: var(--danger-soft); color: var(--danger); }
        .badge.default { background: #ece8df; color: #5a5c58; }
        .badge.high { background: var(--warn-soft); color: var(--warn); }
        .mono { font-family: Consolas, Monaco, monospace; font-size: 12px; }
        .checkbox { width: 16px; height: 16px; accent-color: var(--accent); }
        .detail { padding: 22px; }
        .detail h2 { margin-top: 0; font-size: 26px; }
        .detail-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin-bottom: 18px; }
        .detail-card { padding: 14px; border-radius: 14px; border: 1px solid var(--line); background: #fff; }
        .detail-label { font-size: 12px; color: var(--muted); text-transform: uppercase; letter-spacing: 0.12em; margin-bottom: 6px; }
        .stream { margin-top: 16px; border-top: 1px solid var(--line); padding-top: 16px; }
        .stream-item { padding: 12px 0; border-bottom: 1px dashed var(--line); }
        .stream-item:last-child { border-bottom: 0; }
        .stream-head { display: flex; justify-content: space-between; gap: 12px; margin-bottom: 4px; }
        .stream-event { font-weight: 700; }
        .hint { color: var(--muted); font-size: 14px; }
        .empty { padding: 36px 20px; text-align: center; color: var(--muted); }
        @media (max-width: 1100px) {
            .hero, .workspace, .cards { grid-template-columns: 1fr; }
            .toolbar { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 720px) {
            h1 { font-size: 32px; }
            .toolbar { grid-template-columns: 1fr; }
            .detail-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="shell">
    <section class="hero">
        <article class="panel headline">
            <div class="eyebrow">WorkerHub Operations</div>
            <h1>Monitor operativo de colas, replay y DLQ</h1>
            <p class="lead">
                Vista central para tareas en Kafka, encolamiento Redis/Horizon y migraciones documentales a Siesa.
                El panel usa la API de monitoreo y se actualiza por polling con aceleracion via sockets cuando estan disponibles.
            </p>
        </article>
        <aside class="panel status-box">
            <div class="status-dot">Estado en tiempo real</div>
            <div>
                <div class="metric-value" id="socket-state">Polling</div>
                <div class="metric-subtle" id="socket-detail">Esperando configuracion de socket...</div>
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
            <div class="toolbar">
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
                <button id="refresh-button" type="button">Actualizar</button>
                <button id="export-dead-letters-button" class="secondary" type="button">Exportar DLQ</button>
                <button id="retry-batch-button" class="secondary" type="button">Retry lote</button>
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

const state = {
    filters: { status: '', type: '', source: '', mode: 'all' },
    tasks: [],
    actions: [],
    selectedTaskId: null,
    selectedIds: new Set(),
    echo: null,
    refreshTimer: null,
};

const nodes = {
    body: document.getElementById('tasks-body'),
    detailTitle: document.getElementById('detail-title'),
    detailSubtitle: document.getElementById('detail-subtitle'),
    detailGrid: document.getElementById('detail-grid'),
    detailEvents: document.getElementById('detail-events'),
    detailActions: document.getElementById('detail-actions'),
    detailError: document.getElementById('detail-error'),
    retryButton: document.getElementById('retry-button'),
    retryBatchButton: document.getElementById('retry-batch-button'),
    exportDeadLettersButton: document.getElementById('export-dead-letters-button'),
    openApiButton: document.getElementById('open-api-button'),
    socketState: document.getElementById('socket-state'),
    socketDetail: document.getElementById('socket-detail'),
};

document.getElementById('refresh-button').addEventListener('click', () => refreshAll(true));
document.getElementById('filter-status').addEventListener('change', event => {
    state.filters.status = event.target.value;
    refreshTasks(true);
});
document.getElementById('filter-type').addEventListener('change', event => {
    state.filters.type = event.target.value;
    refreshTasks(true);
});
document.getElementById('filter-source').addEventListener('input', event => {
    state.filters.source = event.target.value.trim();
    refreshTasks(true);
});
document.getElementById('filter-mode').addEventListener('change', event => {
    state.filters.mode = event.target.value;
    refreshTasks(true);
});
nodes.exportDeadLettersButton.addEventListener('click', () => {
    const suffix = window.workerhubOperatorToken ? `?token=${encodeURIComponent(window.workerhubOperatorToken)}` : '';
    window.open(`/api/monitor/dead-letters/export${suffix}`, '_blank');
});

nodes.retryButton.addEventListener('click', async () => {
    if (!state.selectedTaskId) {
        return;
    }

    nodes.retryButton.disabled = true;
    nodes.detailError.textContent = '';

    const response = await fetch(`/api/monitor/tasks/${state.selectedTaskId}/retry`, {
        method: 'POST',
        headers: { 'Accept': 'application/json', ...operatorHeaders() },
    });

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

    const response = await fetch('/api/monitor/tasks/retry-batch', {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            ...operatorHeaders(),
        },
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

nodes.openApiButton.addEventListener('click', () => {
    if (!state.selectedTaskId) {
        return;
    }

    const suffix = window.workerhubOperatorToken ? `?token=${encodeURIComponent(window.workerhubOperatorToken)}` : '';
    window.open(`/api/monitor/tasks/${state.selectedTaskId}${suffix}`, '_blank');
});

function operatorHeaders() {
    return window.workerhubOperatorToken ? { 'X-WorkerHub-Token': window.workerhubOperatorToken } : {};
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

async function fetchJson(url) {
    const response = await fetch(url, { headers: { 'Accept': 'application/json', ...operatorHeaders() } });
    if (!response.ok) {
        throw new Error(`Request failed: ${response.status}`);
    }

    return response.json();
}

async function refreshSummary() {
    const suffix = window.workerhubOperatorToken ? `?token=${encodeURIComponent(window.workerhubOperatorToken)}` : '';
    const summary = await fetchJson(`/api/monitor/tasks/summary${suffix}`);
    document.querySelectorAll('[data-key]').forEach(node => {
        node.textContent = summary[node.dataset.key] ?? '0';
    });
}

async function refreshTasks(keepSelection = false) {
    const params = new URLSearchParams();
    if (state.filters.status) params.set('status', state.filters.status);
    if (state.filters.type) params.set('type', state.filters.type);
    if (state.filters.source) params.set('source', state.filters.source);
    if (window.workerhubOperatorToken) params.set('token', window.workerhubOperatorToken);

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
    const suffix = window.workerhubOperatorToken ? `?token=${encodeURIComponent(window.workerhubOperatorToken)}` : '';
    const payload = await fetchJson(`/api/monitor/actions${suffix}`);
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
            <td>
                <strong>${escapeHtml(task.id)}</strong><br>
                <span class="mono">${escapeHtml(task.kafka_key || '-')}</span>
            </td>
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
    const suffix = window.workerhubOperatorToken ? `?token=${encodeURIComponent(window.workerhubOperatorToken)}` : '';
    const task = await fetchJson(`/api/monitor/tasks/${taskId}${suffix}`);
    renderTaskDetail(task);
}

function renderTaskDetail(task) {
    if (!task) {
        state.selectedTaskId = null;
        nodes.detailTitle.textContent = 'Selecciona una tarea';
        nodes.detailSubtitle.textContent = 'Aqui veras eventos, payload resumido y opciones operativas.';
        nodes.detailGrid.innerHTML = '';
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
    ].map(([label, value]) => `
        <div class="detail-card">
            <div class="detail-label">${label}</div>
            <div>${value}</div>
        </div>
    `).join('');

    const events = Array.isArray(task.events) ? task.events : [];

    nodes.detailEvents.innerHTML = events.length === 0
        ? '<div class="hint">La tarea no tiene eventos registrados.</div>'
        : events.map(event => `
            <div class="stream-item">
                <div class="stream-head">
                    <span class="stream-event">${escapeHtml(event.event)}</span>
                    <span class="hint">${escapeHtml(event.created_at || '')}</span>
                </div>
                <div>${escapeHtml(event.message || 'Sin mensaje')}</div>
            </div>
        `).join('');

    renderActionHistory();
}

function renderActionHistory() {
    const actions = state.selectedTaskId
        ? state.actions.filter(action => !action.worker_task_id || action.worker_task_id === state.selectedTaskId)
        : state.actions;

    nodes.detailActions.innerHTML = actions.length === 0
        ? '<div class="hint">No hay acciones operativas recientes para esta vista.</div>'
        : actions.map(action => `
            <div class="stream-item">
                <div class="stream-head">
                    <span class="stream-event">${escapeHtml(action.action)}</span>
                    <span class="hint">${escapeHtml(action.created_at || '')}</span>
                </div>
                <div>${escapeHtml(action.actor || 'sistema')} - ${escapeHtml(action.status || 'success')}</div>
                <div class="mono">${escapeHtml(action.worker_task_id || '-')}</div>
            </div>
        `).join('');
}

async function setupSockets() {
    try {
        const suffix = window.workerhubOperatorToken ? `?token=${encodeURIComponent(window.workerhubOperatorToken)}` : '';
        const config = await fetchJson(`/api/monitor/socket-config${suffix}`);
        nodes.socketState.textContent = 'Sockets activos';
        nodes.socketDetail.textContent = `${config.host}:${config.port} - ${config.channels.monitor}`;

        if (!window.io || !window.Echo) {
            throw new Error('Echo client no disponible');
        }

        state.echo = new window.Echo({
            broadcaster: 'socket.io',
            host: `${config.scheme}://${config.host}:${config.port}`,
            transports: ['websocket', 'polling'],
        });

        state.echo.channel(config.channels.monitor).listen('.worker-task.updated', async () => {
            await refreshAll(true);
        });
    } catch (error) {
        nodes.socketState.textContent = 'Polling';
        nodes.socketDetail.textContent = 'Socket no disponible, se mantiene actualizacion periodica.';
    }
}

async function refreshAll(keepSelection = false) {
    await Promise.all([refreshSummary(), refreshTasks(keepSelection), refreshActions()]);
}

async function boot() {
    await refreshAll(false);
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
