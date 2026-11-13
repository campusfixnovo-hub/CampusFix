export function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
    })[character]);
}

export function formatDate(value) {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '—';
    return new Intl.DateTimeFormat('pt-BR', { timeZone: 'America/Manaus', day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(date);
}

export function statusLabel(value) {
    return ({ open: 'Aberta', in_triage: 'Em triagem', awaiting_service: 'Aguardando atendimento', in_progress: 'Em andamento', awaiting_material: 'Aguardando material', awaiting_requester: 'Aguardando você', resolved: 'Resolvida', closed: 'Encerrada', reopened: 'Reaberta', cancelled: 'Cancelada' })[value] || 'Aberta';
}

export function priorityLabel(value) {
    return ({ critical: 'Crítica', high: 'Alta', medium: 'Média', low: 'Baixa' })[value] || 'Média';
}
