@php($slaPresentation = $slaPresentation ?? app(\App\Services\TicketSlaPresenter::class)->present($ticket))
@php($tone = match ($slaPresentation['tone']) { 'danger' => 'border-rose-200 bg-rose-50 text-rose-800', 'paused' => 'border-amber-200 bg-amber-50 text-amber-900', 'success' => 'border-emerald-200 bg-emerald-50 text-emerald-900', 'active' => 'border-blue-100 bg-blue-50 text-blue-900', default => 'border-slate-200 bg-slate-50 text-slate-700' })
<section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="font-semibold text-slate-900">Prazo de atendimento</h2>
    <div class="mt-4 rounded-xl border p-4 {{ $tone }}">
        @if ($slaPresentation['deadline'])
            <p class="text-xl font-bold">{{ $slaPresentation['deadline'] }}</p>
        @endif
        <p class="{{ $slaPresentation['deadline'] ? 'mt-1' : '' }} text-sm font-semibold">{{ $slaPresentation['message'] }}</p>
        @if ($slaPresentation['detail'])
            <p class="mt-2 text-xs opacity-80">{{ $slaPresentation['detail'] }}</p>
        @endif
    </div>
</section>
