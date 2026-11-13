<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'CampusFix') · {{ config('app.name', 'CampusFix') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-800">
    <div data-sidebar-backdrop class="fixed inset-0 z-30 hidden bg-slate-950/50 backdrop-blur-sm lg:hidden"></div>

    <aside id="app-sidebar" data-app-sidebar class="fixed inset-y-0 left-0 z-40 flex w-[17rem] -translate-x-full flex-col border-r border-white/10 bg-campus-950 text-white shadow-2xl shadow-slate-950/20 transition-transform duration-200 lg:translate-x-0">
        <div class="flex h-20 items-center border-b border-white/10 px-6">
            <x-brand-mark />
        </div>

        <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-5" aria-label="Navegação principal">
            <p class="px-3 pb-2 text-[11px] font-semibold uppercase tracking-[0.16em] text-white/40">Espaço de trabalho</p>
            @if (Route::has('dashboard'))
                <a href="{{ route('dashboard') }}" @class(['flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition', 'bg-white/12 text-white' => request()->routeIs('dashboard'), 'text-white/70 hover:bg-white/8 hover:text-white' => ! request()->routeIs('dashboard')])>
                    <span class="grid size-8 place-items-center rounded-lg bg-white/8">⌂</span><span>Painel</span>
                </a>
            @endif
            @if (Route::has('tickets.index') && auth()->user()->can('viewAny', \App\Models\Ticket::class))
                <a href="{{ route('tickets.index') }}" @class(['flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition', 'bg-white/12 text-white' => request()->routeIs('tickets.*'), 'text-white/70 hover:bg-white/8 hover:text-white' => ! request()->routeIs('tickets.*')])>
                    <span class="grid size-8 place-items-center rounded-lg bg-white/8">▤</span><span>Solicitações</span>
                </a>
            @endif
            @if (Route::has('tickets.create') && auth()->user()->can('create', \App\Models\Ticket::class))
                <a href="{{ route('tickets.create') }}" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-white/70 transition hover:bg-white/8 hover:text-white">
                    <span class="grid size-8 place-items-center rounded-lg bg-white/8">＋</span><span>Abrir solicitação</span>
                </a>
            @endif

            @if (auth()->user()->hasAnyRole(['manager', 'administrator']))
                <p class="px-3 pb-2 pt-7 text-[11px] font-semibold uppercase tracking-[0.16em] text-white/40">Gestão</p>
                @if (auth()->user()->hasRole('manager') && Route::has('manager.teams.index'))
                    <a href="{{ route('manager.teams.index') }}" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-white/70 transition hover:bg-white/8 hover:text-white"><span class="grid size-8 place-items-center rounded-lg bg-white/8">⌘</span><span>Minhas equipes</span></a>
                @endif
                @if (Route::has('teams.workload'))
                    <a href="{{ route('teams.workload') }}" @class(['flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition', 'bg-white/12 text-white' => request()->routeIs('teams.workload'), 'text-white/70 hover:bg-white/8 hover:text-white' => ! request()->routeIs('teams.workload')])><span class="grid size-8 place-items-center rounded-lg bg-white/8">▥</span><span>Carga de trabalho</span></a>
                @endif
                @if (Route::has('reports.index') && (auth()->user()->can('reports.view-all') || auth()->user()->can('reports.view-team')))
                    <a href="{{ route('reports.index') }}" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-white/70 transition hover:bg-white/8 hover:text-white"><span class="grid size-8 place-items-center rounded-lg bg-white/8">▥</span><span>Indicadores</span></a>
                @endif
                @if (auth()->user()->hasRole('administrator'))
                    <p class="px-3 pb-2 pt-7 text-[11px] font-semibold uppercase tracking-[0.16em] text-white/40">Administração</p>
                    @foreach ([['admin.users.index', 'Usuários'], ['admin.teams.index', 'Equipes'], ['admin.campuses.index', 'Campi'], ['admin.places.index', 'Prédios e locais'], ['admin.categories.index', 'Categorias'], ['admin.sla.index', 'Políticas SLA'], ['admin.calendars.index', 'Calendários']] as [$routeName, $label])
                        @if (Route::has($routeName))
                            <a href="{{ route($routeName) }}" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-white/70 transition hover:bg-white/8 hover:text-white"><span class="grid size-8 place-items-center rounded-lg bg-white/8">◈</span><span>{{ $label }}</span></a>
                        @endif
                    @endforeach
                @endif
            @endif
            @if (auth()->user()->hasRole('technician') && auth()->user()->can('reports.view-team') && Route::has('reports.index'))
                <a href="{{ route('reports.index') }}" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-white/70 transition hover:bg-white/8 hover:text-white"><span class="grid size-8 place-items-center rounded-lg bg-white/8">▥</span><span>Indicadores</span></a>
            @endif
        </nav>

        <div class="border-t border-white/10 p-4">
            <div class="flex items-center gap-3 rounded-xl bg-white/6 p-3">
                <span class="grid size-9 shrink-0 place-items-center rounded-full bg-campus-500 text-sm font-bold text-white">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
                <span class="min-w-0 flex-1"><span class="block truncate text-sm font-semibold text-white">{{ auth()->user()->name }}</span><span class="block truncate text-xs text-white/50">{{ auth()->user()->getRoleNames()->join(', ') }}</span></span>
                <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" title="Sair" class="rounded-lg p-2 text-white/55 transition hover:bg-white/10 hover:text-white"><span aria-hidden="true">↗</span><span class="sr-only">Sair</span></button></form>
            </div>
        </div>
    </aside>

    <div class="min-h-screen lg:pl-[17rem]">
        <header class="sticky top-0 z-20 flex h-20 items-center justify-between border-b border-slate-200/80 bg-white/90 px-4 backdrop-blur-xl sm:px-7 lg:px-10">
            <div class="flex min-w-0 items-center gap-3">
                <button data-sidebar-toggle aria-controls="app-sidebar" aria-expanded="false" aria-label="Abrir menu" class="grid size-10 shrink-0 place-items-center rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50 lg:hidden">☰</button>
                <div class="min-w-0"><p class="truncate text-xs font-semibold uppercase tracking-[0.12em] text-campus-700">CampusFix <span class="text-slate-300">/</span> @yield('eyebrow', 'Visão geral')</p><h1 class="truncate text-lg font-semibold tracking-tight text-slate-900 sm:text-xl">@yield('page-title', 'Painel')</h1></div>
            </div>
            <div class="flex items-center gap-2 sm:gap-3">
                <a href="{{ route('notifications.index') }}" class="relative grid size-10 place-items-center rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50" aria-label="Notificações">
                    <span aria-hidden="true">♧</span>
                    @if (auth()->user()->unreadNotifications()->exists())<span class="absolute -right-1 -top-1 min-w-5 rounded-full bg-rose-600 px-1 text-center text-[10px] font-bold text-white">{{ auth()->user()->unreadNotifications()->count() }}</span>@endif
                </a>
                <span class="hidden text-right sm:block"><span class="block text-sm font-semibold text-slate-800">{{ auth()->user()->name }}</span><span class="block text-xs text-slate-500">{{ auth()->user()->user_type->label() }}</span></span>
                <span class="grid size-10 place-items-center rounded-full bg-campus-100 text-sm font-bold text-campus-800">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
            </div>
        </header>

        <main class="mx-auto w-full max-w-[1600px] px-4 py-7 sm:px-7 sm:py-9 lg:px-10">
            @if (session('status'))
                <div role="status" class="mb-6 flex items-start gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"><span aria-hidden="true">✓</span><span>{{ session('status') }}</span></div>
            @endif
            @if ($errors->any())
                <div role="alert" class="mb-6 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800"><ul class="list-inside list-disc space-y-1">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif
            @yield('content')
        </main>
    </div>
</body>
</html>
