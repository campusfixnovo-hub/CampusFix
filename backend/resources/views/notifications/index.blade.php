@extends('layouts.app')
@section('title', 'Notificações')
@section('page-title', 'Notificações')
@section('eyebrow', 'Atualizações')
@section('content')
<section class="mx-auto max-w-4xl overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="border-b border-slate-100 px-5 py-4"><h2 class="font-semibold">Avisos do CampusFix</h2><p class="mt-1 text-xs text-slate-500">Alertas e atualizações associados à sua conta.</p></div>
    <div class="divide-y divide-slate-100">
        @forelse($notifications as $notification)
            @php($data = $notification->data ?? [])
            <article class="flex flex-wrap items-center justify-between gap-4 px-5 py-4 {{ $notification->read_at ? 'bg-white' : 'bg-campus-50/60' }}">
                <div class="min-w-0 flex-1"><div class="flex flex-wrap items-center gap-2"><p class="text-sm font-semibold text-slate-800">{{ $data['message'] ?? 'Atualização do sistema' }}</p>@if(!$notification->read_at)<span class="rounded-full bg-campus-100 px-2 py-0.5 text-[10px] font-semibold text-campus-800">Nova</span>@endif</div>
                    @if(!empty($data['ticket_code']))<p class="mt-1 text-xs text-slate-500">{{ $data['ticket_code'] }} · {{ $data['ticket_title'] ?? '' }}</p>@endif
                    <p class="mt-1 text-xs text-slate-400">{{ $notification->created_at?->diffForHumans() }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-3">@if(!empty($data['url']))<a href="{{ $data['url'] }}" class="text-sm font-semibold text-campus-700">Ver chamado</a>@endif @if(!$notification->read_at)<form method="POST" action="{{ route('notifications.read', $notification->id) }}">@csrf @method('PATCH')<button class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-600">Marcar como lida</button></form>@endif</div>
            </article>
        @empty
            <p class="p-8 text-center text-sm text-slate-500">Você não tem notificações.</p>
        @endforelse
    </div>
    <div class="border-t border-slate-100 p-4">{{ $notifications->links() }}</div>
</section>
@endsection
