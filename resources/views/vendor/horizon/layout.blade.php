@php
    $operator = session(config('workerhub.backoffice.session_key', 'workerhub.operator'));
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="shortcut icon" href="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAAGXRFWHRTb2Z0d2FyZQBBZG9iZSBJbWFnZVJlYWR5ccllPAAAAipJREFUeNrEV8txwjAQtQ2HHCmB3JKbSQOYCoA0gD0pgFBBwpEToQAGKmDglpwgFdg5kZtNB1BBsuusZ4RY2ZZjYGd2jGWh97Q/rUwjpziPT3V4dECboDZoXZoSka5Al5vFNMqzrpkD2IFHn8B1ZAM6BCKbQgQAuAaPWQFgjoinsoipAEcTr0FrRjmyJxLLTAI5wXFXAehBGMPYcDKIIIm5kkAGOJpwAjqHRfYpbkOXvTBBypIwpT+HCvA3Cqi9Rta8EhHOHS1YCy1oWMKHmQIcGQ90wGMfLaZIoEGAoiDGOHmxhFTr5PGZJgncZYszEGC6ogX6nNn/Ay6RGDCfYveYVOFCJuAaumbPiIk1kyUNS2H6SZngyZrMWM+i/JVlXjK4QUVI3pRTpYPlaG6yeyGvm0Jef1ItiArwQBKu8G5bTMEIhKLkU3q65D+HgieE7+MCBHbygMVMOlCK+CnVDOUZ5s00ghCt2T45C+DDD2MBW/O066YFLYGvuXU5C9i6GYaLUzqr+olQtS5aIMwwtW6QfQnv7awNVanolEWgo9nABBb1cNeSmMDyigRWZkqdPrdEkDm3SRYMr7D7odwRXdIK8e7lOuAxh8W5pHtSiOhw8S4A7iX9IErlyC5b/7t+/7Ar4TKiEuyyRuJA5cQ5Wz8gEhgPNyXvfCQPVtgI+SPxAT/vSqiSEbXh70Uvp27GRSMNeJjV2Jp5V6MGpUeuUR0wAemKuwdy8ivAAJcc0R2NFxWtAAAAAElFTkSuQmCC">
    <title>WorkerHub Horizon{{ config('horizon.name') ? ' - ' . config('horizon.name') : '' }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=manrope:400,500,600,700|instrument+sans:400,500,600" rel="stylesheet" />
    {{ Laravel\Horizon\Horizon::css() }}
    <style>
        :root {
            --wh-bg: #eef5ff;
            --wh-surface: rgba(255,255,255,.88);
            --wh-surface-strong: #ffffff;
            --wh-ink: #0f1f3d;
            --wh-muted: #5c7197;
            --wh-line: rgba(36, 85, 164, .14);
            --wh-primary: #0d62d6;
            --wh-primary-strong: #0a4ca8;
            --wh-primary-soft: rgba(13, 98, 214, .12);
            --wh-danger: #c73b55;
            --wh-danger-soft: rgba(199, 59, 85, .12);
            --wh-success: #138a63;
            --wh-success-soft: rgba(19, 138, 99, .12);
            --wh-shadow: 0 24px 60px rgba(13, 53, 120, .12);
        }
        body {
            background:
                radial-gradient(circle at top left, rgba(13, 98, 214, .16), transparent 28%),
                radial-gradient(circle at top right, rgba(79, 144, 255, .22), transparent 22%),
                linear-gradient(180deg, #f7fbff 0%, var(--wh-bg) 100%);
            color: var(--wh-ink);
            font-family: "Manrope", "Segoe UI", sans-serif;
        }
        .wh-topbar {
            max-width: 1480px;
            margin: 0 auto;
            padding: 22px 20px 0;
        }
        .wh-topbar-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            padding: 18px 22px;
            border: 1px solid var(--wh-line);
            border-radius: 22px;
            background: linear-gradient(145deg, rgba(255,255,255,.92), rgba(244,249,255,.84));
            backdrop-filter: blur(14px);
            box-shadow: var(--wh-shadow);
        }
        .wh-brand {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .wh-brand-mark {
            width: 44px;
            height: 44px;
            border-radius: 14px;
            background: linear-gradient(145deg, var(--wh-primary), #48a2ff);
            box-shadow: inset 0 1px 0 rgba(255,255,255,.22);
        }
        .wh-brand-copy small {
            display: block;
            margin-bottom: 4px;
            color: var(--wh-muted);
            text-transform: uppercase;
            letter-spacing: .18em;
            font-size: 11px;
        }
        .wh-brand-copy strong {
            display: block;
            font-size: 22px;
            line-height: 1;
            color: var(--wh-ink);
        }
        .wh-brand-copy span {
            color: var(--wh-muted);
            font-size: 13px;
        }
        .wh-nav {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .wh-link, .wh-logout {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 0 16px;
            border-radius: 999px;
            border: 1px solid var(--wh-line);
            background: rgba(255,255,255,.88);
            color: var(--wh-ink);
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: .01em;
        }
        .wh-link.active, .wh-link:hover, .wh-logout:hover {
            background: var(--wh-primary);
            border-color: var(--wh-primary);
            color: #fff;
        }
        .wh-operator {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-left: 8px;
            padding-left: 12px;
            border-left: 1px solid var(--wh-line);
            color: var(--wh-muted);
            font-size: 13px;
        }
        .wh-operator strong {
            color: var(--wh-ink);
            display: block;
        }
        .wh-logout-form { margin: 0; }
        #horizon .container {
            max-width: 1480px;
        }
        #horizon .header {
            padding-top: 24px !important;
            border-bottom: 1px solid var(--wh-line);
            margin-bottom: 8px;
        }
        #horizon .header .logo h1,
        #horizon .header .logo strong {
            color: var(--wh-ink);
            font-family: "Instrument Sans", "Segoe UI", sans-serif;
        }
        #horizon .header .logo .fill-primary {
            fill: var(--wh-primary);
        }
        #horizon .sidebar .nav-link {
            border: 1px solid transparent;
            border-radius: 14px;
            color: var(--wh-muted);
            font-weight: 700;
            padding: 12px 14px;
            margin-bottom: 8px;
            background: rgba(255,255,255,.55);
        }
        #horizon .sidebar .nav-link.active,
        #horizon .sidebar .nav-link:hover {
            background: var(--wh-primary-soft);
            border-color: rgba(13, 98, 214, .18);
            color: var(--wh-primary);
        }
        #horizon .btn-primary,
        #horizon .bg-primary {
            background-color: var(--wh-primary) !important;
            border-color: var(--wh-primary) !important;
        }
        #horizon .btn-primary:hover {
            background-color: var(--wh-primary-strong) !important;
            border-color: var(--wh-primary-strong) !important;
        }
        #horizon .text-primary,
        #horizon .fill-primary {
            color: var(--wh-primary) !important;
            fill: var(--wh-primary) !important;
        }
        #horizon .card,
        #horizon .table-card,
        #horizon .card-header,
        #horizon .card-body,
        #horizon .card-footer,
        #horizon .queue-card {
            border-color: var(--wh-line) !important;
        }
        #horizon .card,
        #horizon .table-card {
            border-radius: 20px !important;
            box-shadow: 0 18px 42px rgba(13, 53, 120, .08);
            background: rgba(255,255,255,.88);
        }
        #horizon .btn-muted {
            border-radius: 999px;
            border: 1px solid var(--wh-line);
            background: rgba(255,255,255,.88);
            color: var(--wh-muted);
        }
        #horizon .btn-muted.active {
            background: var(--wh-primary-soft);
            color: var(--wh-primary);
            border-color: rgba(13, 98, 214, .18);
        }
        @media (max-width: 960px) {
            .wh-topbar-card {
                flex-direction: column;
                align-items: flex-start;
            }
            .wh-nav {
                width: 100%;
            }
            .wh-operator {
                margin-left: 0;
                padding-left: 0;
                border-left: 0;
            }
        }
    </style>
    {{ Laravel\Horizon\Horizon::js() }}
</head>
<body>
<div class="wh-topbar">
    <div class="wh-topbar-card">
        <div class="wh-brand">
            <div class="wh-brand-mark"></div>
            <div class="wh-brand-copy">
                <small>Comodísimos Operations</small>
                <strong>WorkerHub Horizon</strong>
                <span>Dashboard operativo de colas y throughput</span>
            </div>
        </div>
        <div class="wh-nav">
            <a class="wh-link" href="{{ route('monitor.dashboard') }}">Monitor</a>
            <a class="wh-link active" href="{{ url('/horizon') }}">Horizon</a>
            @if (is_array($operator))
                <div class="wh-operator">
                    <div>
                        <strong>{{ $operator['name'] ?? $operator['email'] ?? 'Operador activo' }}</strong>
                        <span>{{ $operator['email'] ?? 'web_session' }}</span>
                    </div>
                </div>
            @endif
            <form class="wh-logout-form" method="post" action="{{ route('workerhub.logout') }}">
                @csrf
                <button class="wh-logout" type="submit">Salir</button>
            </form>
        </div>
    </div>
</div>

<div id="horizon" v-cloak>
    <alert :message="alert.message"
           :type="alert.type"
           :auto-close="alert.autoClose"
           :confirmation-proceed="alert.confirmationProceed"
           :confirmation-cancel="alert.confirmationCancel"
           v-if="alert.type"></alert>

    <div class="container mb-5">
        <div class="d-flex align-items-center py-4 header">
            <router-link to="/" class="logo d-flex align-items-center">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 30 30">
                    <path class="fill-primary" d="M5.26176342 26.4094389C2.04147988 23.6582233 0 19.5675182 0 15c0-4.1421356 1.67893219-7.89213562 4.39339828-10.60660172C7.10786438 1.67893219 10.8578644 0 15 0c8.2842712 0 15 6.71572875 15 15 0 8.2842712-6.7157288 15-15 15-3.716753 0-7.11777662-1.3517984-9.73823658-3.5905611zM4.03811305 15.9222506C5.70084247 14.4569342 6.87195416 12.5 10 12.5c5 0 5 5 10 5 3.1280454 0 4.2991572-1.9569336 5.961887-3.4222502C25.4934253 8.43417206 20.7645408 4 15 4 8.92486775 4 4 8.92486775 4 15c0 .3105915.01287248.6181765.03811305.9222506z"/>
                </svg>
                <h1 class="h4 mb-0 ms-2">
                    <strong>WorkerHub</strong> Horizon{{ config('horizon.name') ? ' - ' . config('horizon.name') : '' }}
                </h1>
            </router-link>
            <div class="ms-auto">
                <scheme-toggler></scheme-toggler>
                <button class="btn btn-muted ms-2" :class="{active: autoLoadsNewEntries}" v-on:click.prevent="autoLoadNewEntries" title="Auto Load New Entries">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon" fill="currentColor">
                        <path fill-rule="evenodd" d="M15.312 11.424a5.5 5.5 0 01-9.201 2.466l-.312-.311h2.433a.75.75 0 000-1.5H3.989a.75.75 0 00-.75.75v4.242a.75.75 0 001.5 0v-2.43l.31.31a7 7 0 0011.712-3.138.75.75 0 00-1.449-.39zm1.23-3.723a.75.75 0 00.219-.53V2.929a.75.75 0 00-1.5 0V5.36l-.31-.31A7 7 0 003.239 8.188a.75.75 0 101.448.389A5.5 5.5 0 0113.89 6.11l.311.31h-2.432a.75.75 0 000 1.5h4.243a.75.75 0 00.53-.219z" clip-rule="evenodd" />
                    </svg>
                </button>
            </div>
        </div>

        <div class="row mt-4">
            <div class="col-2 sidebar">
                <ul class="nav flex-column">
                    <li class="nav-item"><router-link active-class="active" to="/dashboard" class="nav-link d-flex align-items-center"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.25 2A2.25 2.25 0 002 4.25v2.5A2.25 2.25 0 004.25 9h2.5A2.25 2.25 0 009 6.75v-2.5A2.25 2.25 0 006.75 2h-2.5zm0 9A2.25 2.25 0 002 13.25v2.5A2.25 2.25 0 004.25 18h2.5A2.25 2.25 0 009 15.75v-2.5A2.25 2.25 0 006.75 11h-2.5zm9-9A2.25 2.25 0 0011 4.25v2.5A2.25 2.25 0 0013.25 9h2.5A2.25 2.25 0 0018 6.75v-2.5A2.25 2.25 0 0015.75 2h-2.5zm0 9A2.25 2.25 0 0011 13.25v2.5A2.25 2.25 0 0013.25 18h2.5A2.25 2.25 0 0018 15.75v-2.5A2.25 2.25 0 0015.75 11h-2.5z" clip-rule="evenodd" /></svg><span>Dashboard</span></router-link></li>
                    <li class="nav-item"><router-link active-class="active" to="/monitoring" class="nav-link d-flex align-items-center"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zM2 9a7 7 0 1112.452 4.391l3.328 3.329a.75.75 0 11-1.06 1.06l-3.329-3.328A7 7 0 012 9z" clip-rule="evenodd" /></svg><span>Monitoring</span></router-link></li>
                    <li class="nav-item"><router-link active-class="active" to="/metrics" class="nav-link d-flex align-items-center"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M15.5 2A1.5 1.5 0 0014 3.5v13a1.5 1.5 0 001.5 1.5h1a1.5 1.5 0 001.5-1.5v-13A1.5 1.5 0 0016.5 2h-1zM9.5 6A1.5 1.5 0 008 7.5v9A1.5 1.5 0 009.5 18h1a1.5 1.5 0 001.5-1.5v-9A1.5 1.5 0 0010.5 6h-1zM3.5 10A1.5 1.5 0 002 11.5v5A1.5 1.5 0 003.5 18h1A1.5 1.5 0 006 16.5v-5A1.5 1.5 0 004.5 10h-1z" /></svg><span>Metrics</span></router-link></li>
                    <li class="nav-item"><router-link active-class="active" to="/batches" class="nav-link d-flex align-items-center"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M2 3.75A.75.75 0 012.75 3h14.5a.75.75 0 010 1.5H2.75A.75.75 0 012 3.75zm0 4.167a.75.75 0 01.75-.75h14.5a.75.75 0 010 1.5H2.75a.75.75 0 01-.75-.75zm0 4.166a.75.75 0 01.75-.75h14.5a.75.75 0 010 1.5H2.75a.75.75 0 01-.75-.75zm0 4.167a.75.75 0 01.75-.75h14.5a.75.75 0 010 1.5H2.75a.75.75 0 01-.75-.75z" clip-rule="evenodd" /></svg><span>Batches</span></router-link></li>
                    <li class="nav-item"><router-link active-class="active" to="/jobs/pending" class="nav-link d-flex align-items-center"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M2 10a8 8 0 1116 0 8 8 0 01-16 0zm5-2.25A.75.75 0 017.75 7h.5a.75.75 0 01.75.75v4.5a.75.75 0 01-.75.75h-.5a.75.75 0 01-.75-.75v-4.5zm4 0a.75.75 0 01.75-.75h.5a.75.75 0 01.75.75v4.5a.75.75 0 01-.75.75h-.5a.75.75 0 01-.75-.75v-4.5z" clip-rule="evenodd" /></svg><span>Pending Jobs</span></router-link></li>
                    <li class="nav-item"><router-link active-class="active" to="/jobs/completed" class="nav-link d-flex align-items-center"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" /></svg><span>Completed Jobs</span></router-link></li>
                    <li class="nav-item"><router-link active-class="active" to="/jobs/silenced" class="nav-link d-flex align-items-center"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M4 8c0-.26.017-.517.049-.77l7.722 7.723a33.56 33.56 0 01-3.722-.01 2 2 0 003.862.15l1.134 1.134a3.5 3.5 0 01-6.53-1.409 32.91 32.91 0 01-3.257-.508.75.75 0 01-.515-1.076A11.448 11.448 0 004 8zM17.266 13.9a.756.756 0 01-.068.116L6.389 3.207A6 6 0 0116 8c.001 1.887.455 3.665 1.258 5.234a.75.75 0 01.01.666zM3.28 2.22a.75.75 0 00-1.06 1.06l14.5 14.5a.75.75 0 101.06-1.06L3.28 2.22z" /></svg><span>Silenced Jobs</span></router-link></li>
                    <li class="nav-item"><router-link active-class="active" to="/failed" class="nav-link d-flex align-items-center"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-5a.75.75 0 01.75.75v4.5a.75.75 0 01-1.5 0v-4.5A.75.75 0 0110 5zm0 10a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" /></svg><span>Failed Jobs</span></router-link></li>
                </ul>
            </div>
            <div class="col-10">
                @if ($isDownForMaintenance)
                    <div class="alert alert-warning">
                        This application is in "maintenance mode". Queued jobs may not be processed unless your worker is using the "force" flag.
                    </div>
                @endif
                <router-view></router-view>
            </div>
        </div>
    </div>
</div>
</body>
</html>
