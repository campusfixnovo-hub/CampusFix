<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Acesso' }} · {{ config('app.name', 'CampusFix') }}</title>
    <style>
        :root { color-scheme: light; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color: #17212b; background: #f3f7f6; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; background: radial-gradient(circle at 12% 8%, #e0f2ed 0, transparent 34%), #f3f7f6; }
        a { color: #087e70; font-weight: 650; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .shell { min-height: 100vh; display: grid; grid-template-columns: minmax(260px, 0.9fr) minmax(340px, 1.1fr); }
        .brand { display: flex; flex-direction: column; justify-content: space-between; padding: clamp(32px, 7vw, 84px); color: #f4fffb; background: linear-gradient(145deg, #073f3b, #087e70 70%, #119886); }
        .brand-mark { display: flex; align-items: center; gap: 13px; font-size: 1.35rem; font-weight: 800; letter-spacing: -.04em; }
        .mark-icon { width: 42px; height: 42px; display: grid; place-items: center; border: 1px solid #ffffff55; border-radius: 13px; background: #ffffff18; font-size: 1.3rem; }
        .brand-copy { max-width: 440px; margin: 48px 0; }
        .brand-copy h1 { margin: 0 0 18px; font-size: clamp(2.25rem, 4.3vw, 4rem); line-height: 1.04; letter-spacing: -.055em; }
        .brand-copy p { max-width: 380px; color: #d0eee7; font-size: 1.05rem; line-height: 1.7; }
        .brand-foot { color: #c1e3dc; font-size: .88rem; }
        .content { display: grid; place-items: center; padding: 32px 22px; }
        .card { width: min(100%, 450px); padding: clamp(26px, 4vw, 42px); border: 1px solid #dce7e3; border-radius: 20px; background: #fff; box-shadow: 0 24px 70px #17483d12; }
        .card h2 { margin: 0 0 8px; font-size: 1.7rem; letter-spacing: -.035em; }
        .intro { margin: 0 0 26px; color: #65736f; line-height: 1.55; }
        .field { margin: 17px 0; }
        label { display: block; margin-bottom: 7px; font-size: .9rem; font-weight: 650; }
        input, select { width: 100%; min-height: 46px; padding: 10px 12px; border: 1px solid #cbd8d4; border-radius: 10px; background: #fff; color: #17212b; font: inherit; }
        input:focus, select:focus { outline: 3px solid #0d9b842a; border-color: #0d9b84; }
        .hint { margin: 7px 0 0; color: #71807b; font-size: .8rem; }
        .row { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .remember { display: flex; align-items: center; gap: 8px; color: #586762; font-size: .9rem; }
        .remember input { width: 16px; min-height: auto; accent-color: #087e70; }
        .button { width: 100%; min-height: 48px; margin-top: 8px; border: 0; border-radius: 10px; background: #087e70; color: white; font: inherit; font-weight: 750; cursor: pointer; }
        .button:hover { background: #06675d; }
        .links { margin-top: 22px; color: #687771; text-align: center; font-size: .92rem; line-height: 1.8; }
        .notice, .errors { margin: 0 0 18px; padding: 12px 14px; border-radius: 10px; font-size: .9rem; line-height: 1.5; }
        .notice { border: 1px solid #b9e2d0; background: #eef9f3; color: #176446; }
        .errors { border: 1px solid #f0c6c3; background: #fff3f2; color: #9d3028; }
        .errors ul { margin: 0; padding-left: 19px; }
        .home-card { width: min(100%, 620px); }
        .home-meta { margin: 20px 0; padding: 16px; border-radius: 12px; background: #f3f8f6; color: #45554f; line-height: 1.7; }
        .logout { width: auto; padding: 0 18px; }
        @media (max-width: 760px) { .shell { display: block; } .brand { min-height: auto; padding: 24px; } .brand-copy { margin: 28px 0 18px; } .brand-copy h1 { font-size: 2.2rem; } .brand-copy p, .brand-foot { display: none; } .content { padding: 22px 15px 36px; } }
    </style>
</head>
<body>
<main class="shell">
    <section class="brand" aria-label="CampusFix">
        <div class="brand-mark"><span class="mark-icon" aria-hidden="true">⌂</span><span>CampusFix</span></div>
        <div class="brand-copy">
            <h1>Um campus melhor começa com cuidado.</h1>
            <p>Registre e acompanhe solicitações de manutenção e infraestrutura da sua instituição.</p>
        </div>
        <div class="brand-foot">Manutenção universitária com acompanhamento claro.</div>
    </section>
    <section class="content">
        <div class="card {{ $cardClass ?? '' }}">
            @if (session('status'))
                <div class="notice" role="status">{{ session('status') }}</div>
            @endif
            @if ($errors->any())
                <div class="errors" role="alert">
                    <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif
            @yield('content')
        </div>
    </section>
</main>
</body>
</html>
