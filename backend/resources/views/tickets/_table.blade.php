<div class="overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-5 py-3">Solicitação</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Prioridade</th><th class="px-4 py-3">Prazo SLA</th><th class="px-5 py-3">Responsável</th></tr></thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($tickets as $ticket)
                @php($sla = app(\App\Services\TicketSlaPresenter::class)->present($ticket))
                <tr class="hover:bg-slate-50/70">
                    <td class="px-5 py-4"><a href="{{ route('tickets.show', $ticket) }}" class="font-semibold text-slate-900 hover:text-campus-700">{{ $ticket->title }}</a><div class="mt-1 text-xs text-slate-500">{{ $ticket->code }} · {{ $ticket->category->name }}@unless($compact ?? false) · {{ $ticket->requester->name }}@endunless</div></td>
                    <td class="px-4 py-4"><span class="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700">{{ $ticket->status->label() }}</span></td>
                    <td class="px-4 py-4"><span class="inline-flex items-center gap-1.5 text-xs font-semibold {{ ['critical' => 'text-rose-700', 'high' => 'text-orange-700', 'medium' => 'text-amber-700', 'low' => 'text-slate-600'][$ticket->priority->value] }}"><span class="size-1.5 rounded-full bg-current"></span>{{ $ticket->priority->label() }}</span></td>
                    <td class="px-4 py-4 text-xs"><span class="font-medium {{ $sla['tone'] === 'danger' ? 'text-rose-600' : ($sla['tone'] === 'paused' ? 'text-amber-700' : 'text-slate-600') }}">{{ $sla['message'] }}</span>@if($sla['deadline'])<span class="mt-1 block text-slate-400">{{ $sla['deadline'] }}</span>@endif</td>
                    <td class="px-5 py-4 text-xs text-slate-600">{{ $ticket->assignedTechnician?->name ?? $ticket->team?->name ?? 'Aguardando atribuição' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-5 py-12 text-center"><div class="text-2xl">▤</div><p class="mt-2 font-medium text-slate-800">Nenhuma solicitação encontrada</p><p class="mt-1 text-sm text-slate-500">Comece abrindo uma nova solicitação.</p></td></tr>
            @endforelse
        </tbody>
    </table>
</div>
