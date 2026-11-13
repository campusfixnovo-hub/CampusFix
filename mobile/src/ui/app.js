import { campusfixApi } from '../services/campusfix-api.js';
import { ApiError, apiUrl } from '../services/api.js';
import { secureSession } from '../services/secure-session.js';
import { sessionRecoveryState } from '../services/session-recovery.js';
import { escapeHtml, formatDate, statusLabel, priorityLabel } from './format.js';
import { ticketPermissions } from './authorization.js';
import { isPreviewableImage } from '../services/attachment-utils.js';

const statusOptions = ['open', 'in_triage', 'awaiting_service', 'in_progress', 'awaiting_material', 'awaiting_requester', 'resolved', 'closed', 'reopened', 'cancelled'];

export async function startApp(root) {
    const state = { root, user: null, page: location.hash || '#/dashboard', ticketsPage: 1, selectedFiles: [], busy: false, sessionRecovery: null };
    window.addEventListener('hashchange', () => navigate(state, location.hash || '#/dashboard'));
    window.addEventListener('campusfix:unauthorized', () => void signOut(state, false));
    const token = await secureSession.getToken();
    if (token) {
        try {
            state.user = (await campusfixApi.me()).data;
        } catch (error) {
            state.sessionRecovery = sessionRecoveryState(error);
            if (state.sessionRecovery.clearToken) await secureSession.clearToken();
        }
    }
    await navigate(state, state.user ? state.page : '#/login');
}

async function navigate(state, page) {
    releaseAttachmentPreviews(state);
    state.page = page;
    if (!state.user && page !== '#/login') page = '#/login';
    if (state.user && page === '#/login') page = '#/dashboard';
    state.page = page;
    state.root.innerHTML = state.user ? shell(state, '<div class="loading-card"><span class="spinner"></span><p>Carregando CampusFix…</p></div>') : loginScreen(state);
    bindGlobal(state);
    if (!state.user) {
        bindLogin(state);
        return;
    }

    window.__campusfixUser = state.user;
    const content = await renderPage(state, page);
    state.root.innerHTML = shell(state, content);
    bindGlobal(state);
    bindPage(state, page);
    bindGlobalRetry(state);
}

async function renderPage(state, page) {
    try {
        if (page.startsWith('#/tickets/new')) return await newTicketScreen();
        if (page.startsWith('#/tickets/')) return await detailScreen(page.split('/')[2], state.user, state);
        if (page.startsWith('#/tickets')) return ticketListScreen();
        if (page.startsWith('#/notifications')) return notificationScreen();
        if (page.startsWith('#/profile')) return profileScreen(state.user);
        return await dashboardScreen(state.user);
    } catch (error) {
        return errorPanel(error.message || 'Não foi possível carregar esta tela.', () => void navigate(state, page));
    }
}

function shell(state, content) {
    const current = state.page.startsWith('#/tickets') ? 'tickets' : state.page.startsWith('#/notifications') ? 'notifications' : state.page.startsWith('#/profile') ? 'profile' : 'dashboard';
    return `<div class="app-shell">
      <header class="topbar"><a class="brand" href="#/dashboard"><span class="brand-mark">CF</span><span><strong>CampusFix</strong><small>Infraestrutura universitária</small></span></a><button class="icon-button" data-action="notifications" aria-label="Notificações">${icon('bell')}<span class="online-dot"></span></button></header>
      <main class="main-content">${content}</main>
      <nav class="bottom-nav" aria-label="Navegação principal">
        <a class="nav-item ${current === 'dashboard' ? 'active' : ''}" href="#/dashboard">${icon('home')}<span>Início</span></a>
        <a class="nav-item ${current === 'tickets' ? 'active' : ''}" href="#/tickets">${icon('ticket')}<span>Chamados</span></a>
        <a class="nav-item ${current === 'notifications' ? 'active' : ''}" href="#/notifications">${icon('bell')}<span>Avisos</span></a>
        <a class="nav-item ${current === 'profile' ? 'active' : ''}" href="#/profile">${icon('user')}<span>Perfil</span></a>
      </nav>
      <div id="toast" class="toast" role="status" aria-live="polite"></div>
      <div id="confirm-layer"></div>
    </div>`;
}

function loginScreen(state) {
    const recoveryNotice = state.sessionRecovery
        ? `<div class="notice-card" role="status"><span>${escapeHtml(state.sessionRecovery.message)}</span>${state.sessionRecovery.canRetry ? '<button class="button button-outline" type="button" data-action="retry-session">Tentar novamente</button>' : ''}</div>`
        : '';

    return `<main class="login-page"><div class="login-hero"><div class="brand-mark brand-mark-large">CF</div><p class="eyebrow">CUIDADO COM O CAMPUS</p><h1>Olá,<br>que bom ter você.</h1><p>Acompanhe solicitações e mantenha seu campus funcionando melhor.</p></div><form id="login-form" class="login-card">${recoveryNotice}<label for="email">E-mail institucional</label><input id="email" name="email" type="email" autocomplete="username" placeholder="voce@instituicao.edu.br" required maxlength="255"><label for="password">Senha</label><input id="password" name="password" type="password" autocomplete="current-password" placeholder="Digite sua senha" required><div id="login-error"></div><button class="button button-primary button-full" type="submit">Entrar <span>→</span></button><p class="muted small-text">Acesso protegido pelo CampusFix</p></form><footer class="login-foot">CampusFix <span>•</span> Universidade em movimento</footer></main>`;
}

async function dashboardScreen(user) {
    const [dashboard, notifications] = await Promise.all([campusfixApi.dashboard(), campusfixApi.notifications().catch(() => ({ data: [] }))]);
    const stats = dashboard.data.stats;
    const recent = dashboard.data.recent_tickets || [];
    const unread = (notifications.data || []).filter((item) => !item.read_at).length;
    const roleText = profileLabel(user);
    return `<section class="welcome-row"><div><p class="eyebrow">${escapeHtml(roleText)}</p><h1>Olá, ${escapeHtml(firstName(user.name))} <span class="wave">✦</span></h1><p class="subheading">Veja o que está acontecendo no seu campus.</p></div><a class="avatar" href="#/profile" aria-label="Abrir perfil">${escapeHtml(initials(user.name))}</a></section>
      <a class="quick-action" href="#/tickets/new"><span class="quick-icon">${icon('plus')}</span><span><strong>Precisa de ajuda?</strong><small>Abra uma solicitação em poucos passos</small></span><b>→</b></a>
      <section class="section-block"><div class="section-heading"><h2>Visão geral</h2><span class="live-badge"><i></i> Atualizado</span></div><div class="stats-grid">
        ${statCard('Abertos', stats.open, 'teal', 'ticket')}${statCard('Em atendimento', stats.in_progress, 'blue', 'clock')}${statCard('Resolvidos', stats.resolved, 'green', 'check')}${statCard('Atrasados', stats.overdue, 'red', 'alert')}
      </div></section>
      ${stats.near_due ? `<div class="notice-card notice-warn">${icon('clock')}<span><strong>${stats.near_due} ${stats.near_due === 1 ? 'chamado próximo' : 'chamados próximos'} do prazo</strong><small>Acompanhe os prazos de atendimento.</small></span></div>` : ''}
      <section class="section-block"><div class="section-heading"><h2>Chamados recentes</h2><a class="text-link" href="#/tickets">Ver todos</a></div>${recent.length ? `<div class="ticket-list">${recent.slice(0, 4).map(ticketCard).join('')}</div>` : emptyState('Tudo tranquilo por aqui', 'Quando houver chamados, eles aparecerão aqui.')}</section>
      <section class="section-block"><div class="section-heading"><h2>Notificações</h2><a class="text-link" href="#/notifications">${unread ? `${unread} não lidas` : 'Ver todas'}</a></div>${unread ? `<div class="notice-card">${icon('bell')}<span><strong>Você tem ${unread} ${unread === 1 ? 'atualização' : 'atualizações'}</strong><small>Toque para acompanhar as novidades dos seus chamados.</small></span></div>` : `<p class="muted inline-empty">Nenhuma notificação nova.</p>`}</section>`;
}

function ticketListScreen() {
    return `<section class="page-heading"><p class="eyebrow">ACOMPANHAMENTO</p><h1>Chamados</h1><p class="subheading">Acompanhe as solicitações do seu escopo.</p></section>
      <form id="ticket-filters" class="filter-bar"><div class="search-field">${icon('search')}<input name="q" type="search" placeholder="Buscar por título ou código" maxlength="120"></div><div class="filter-row"><select name="status"><option value="">Todos os status</option>${statusOptions.map((value) => `<option value="${value}">${statusLabel(value)}</option>`).join('')}</select><select name="priority"><option value="">Todas as prioridades</option><option value="critical">Crítica</option><option value="high">Alta</option><option value="medium">Média</option><option value="low">Baixa</option></select></div><div class="filter-row"><select name="sla"><option value="">Todos os prazos</option><option value="overdue">Atrasados</option><option value="paused">SLA pausado</option></select><button class="button button-outline" type="submit">Filtrar</button></div></form>
      <div id="ticket-results"><div class="loading-card"><span class="spinner"></span><p>Carregando chamados…</p></div></div>`;
}

async function newTicketScreen() {
    const catalogs = (await campusfixApi.catalogs()).data;
    return `<section class="page-heading"><a class="back-link" href="#/tickets">← Chamados</a><p class="eyebrow">NOVA SOLICITAÇÃO</p><h1>Como podemos ajudar?</h1><p class="subheading">Descreva o problema e informe onde ele acontece.</p></section>
      <form id="new-ticket-form" class="form-card">
        <label for="title">Título da solicitação</label><input id="title" name="title" required maxlength="180" placeholder="Ex.: Projetor não liga">
        <label for="description">O que está acontecendo?</label><textarea id="description" name="description" required maxlength="20000" rows="4" placeholder="Conte os detalhes que podem ajudar a equipe."></textarea>
        <label for="category_id">Categoria</label><select id="category_id" name="category_id" required><option value="">Selecione uma categoria</option>${catalogs.categories.map((item) => `<option value="${item.id}">${escapeHtml(item.name)}</option>`).join('')}</select>
        <label for="campus_id">Campus</label><select id="campus_id" name="campus_id" required><option value="">Selecione o campus</option>${catalogs.campuses.map((item) => `<option value="${item.id}">${escapeHtml(item.name)}</option>`).join('')}</select>
        <label for="building_id">Prédio <span class="optional">opcional</span></label><select id="building_id" name="building_id"><option value="">Selecione primeiro um campus</option></select>
        <label for="location_id">Local <span class="optional">opcional</span></label><select id="location_id" name="location_id"><option value="">Selecione primeiro um prédio</option></select>
        <label for="location_details">Referência adicional <span class="optional">opcional</span></label><input id="location_details" name="location_details" maxlength="2000" placeholder="Sala, andar ou ponto de referência">
        <label for="priority">Prioridade</label><select id="priority" name="priority" required><option value="low">Baixa</option><option value="medium" selected>Média</option><option value="high">Alta</option><option value="critical">Crítica</option></select>
        <label for="attachments">Fotos ou documentos <span class="optional">até 5 arquivos, 10 MB cada</span></label><input id="attachments" name="attachments" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" multiple><div id="file-preview" class="file-preview"></div>
        <div id="form-error"></div><button class="button button-primary button-full" type="submit">Enviar solicitação <span>→</span></button>
      </form>`;
}

async function detailScreen(id, user, state) {
    const [ticketResponse, historyResponse, commentsResponse, ratingResponse] = await Promise.all([
        campusfixApi.ticket(id), campusfixApi.history(id).catch(() => ({ data: [] })), campusfixApi.comments(id).catch(() => ({ data: [] })), campusfixApi.rating(id).catch(() => ({ data: null })),
    ]);
    const ticket = ticketResponse.data;
    const { canAssign, canManage, canAddInternalNote } = ticketPermissions(user);
    const assignmentResponse = canAssign && !['closed', 'cancelled'].includes(ticket.status?.value)
        ? await campusfixApi.assignmentOptions(id).catch(() => null)
        : null;
    state.assignmentOptions = assignmentResponse?.data || null;
    const isRequester = Number(ticket.requester?.id) === Number(user.id);
    const history = historyResponse.data || [];
    const comments = commentsResponse.data || [];
    let attachments = [];
    let attachmentsLoadError = false;
    try {
        const attachmentsResponse = await campusfixApi.attachments(id);
        attachments = attachmentsResponse.data || [];
    } catch {
        attachmentsLoadError = true;
    }
    const attachmentPreviews = new Map();
    const failedPreviews = new Set();
    await Promise.all(attachments.filter(isPreviewableImage).map(async (attachment) => {
        try {
            const objectUrl = URL.createObjectURL(await fetchPrivateAttachment(attachment.download_url));
            state.attachmentObjectUrls.push(objectUrl);
            attachmentPreviews.set(attachment.id, objectUrl);
        } catch {
            failedPreviews.add(attachment.id);
        }
    }));
    const team = ticket.team?.name || 'Aguardando atribuição';
    const tech = ticket.technician?.name || 'Ainda não definido';
    return `<section class="page-heading"><a class="back-link" href="#/tickets">← Chamados</a><div class="ticket-title-row"><div><p class="eyebrow">${escapeHtml(ticket.code)}</p><h1>${escapeHtml(ticket.title)}</h1></div>${statusPill(ticket.status?.value)}</div><div class="ticket-meta"><span class="priority-pill ${ticket.priority?.value}">${priorityLabel(ticket.priority?.value)}</span><span>${formatDate(ticket.created_at)}</span></div></section>
      <section class="detail-card"><h2>Solicitação</h2><p class="preserve-lines">${escapeHtml(ticket.description)}</p><div class="detail-grid"><div><small>Categoria</small><strong>${escapeHtml(ticket.category?.name || '—')}</strong></div><div><small>Local</small><strong>${escapeHtml([ticket.campus?.name, ticket.building?.name, ticket.location?.name].filter(Boolean).join(' · ') || '—')}</strong></div><div><small>Equipe responsável</small><strong>${escapeHtml(team)}</strong></div><div><small>Técnico</small><strong>${escapeHtml(tech)}</strong></div></div>
      ${renderSlaBanner(ticket)}
      ${isRequester && ['open', 'in_triage'].includes(ticket.status?.value) ? `<section class="detail-card"><button class="button button-danger button-full" data-action="cancel-ticket">Cancelar solicitação</button></section>` : ''}
      ${canManage ? `<section class="detail-card action-card"><h2>Atualizar atendimento</h2><form id="status-form"><label for="status-change">Novo status</label><select id="status-change" name="status">${statusOptions.map((value) => `<option value="${value}" ${ticket.status?.value === value ? 'selected' : ''}>${statusLabel(value)}</option>`).join('')}</select><button class="button button-primary" type="submit">Atualizar</button></form></section>` : ''}
      ${canAssign ? assignmentResponse ? `<section class="detail-card action-card"><h2>Atribuir atendimento</h2><form id="assignment-form"><label for="assignment-team">Equipe</label><select id="assignment-team" name="team_id"><option value="">Sem equipe</option>${assignmentResponse.data.teams.map((item) => `<option value="${item.id}" ${Number(item.id) === Number(assignmentResponse.data.current_team_id) ? 'selected' : ''}>${escapeHtml(item.name)}</option>`).join('')}</select><label for="assignment-technician">Técnico</label><select id="assignment-technician" name="assigned_user_id"><option value="">Sem técnico</option></select><button class="button button-primary" type="submit">Salvar atribuição</button></form></section>` : '<section class="detail-card"><h2>Atribuir atendimento</h2><p class="muted">As opções de atribuição estão indisponíveis para este chamado.</p></section>' : ''}
      <section class="detail-card"><div class="section-heading"><h2>Conversa</h2><span class="count-badge">${comments.length}</span></div><div class="conversation-list">${comments.length ? comments.map((comment) => `<article class="comment ${comment.is_internal ? 'internal' : ''}"><div class="comment-head"><strong>${escapeHtml(comment.author?.name || 'Usuário')}</strong><time>${formatDate(comment.created_at)}</time></div>${comment.is_internal ? '<span class="internal-label">Nota interna</span>' : ''}<p>${escapeHtml(comment.body)}</p></article>`).join('') : '<p class="muted">Nenhuma atualização na conversa.</p>'}</div>
      <form id="comment-form" class="comment-form"><label for="comment-body">Adicionar mensagem</label><textarea id="comment-body" name="body" rows="3" required maxlength="10000" placeholder="Escreva uma atualização…"></textarea>${canAddInternalNote ? '<label class="check-label"><input type="checkbox" name="is_internal"> Nota interna (visível à equipe)</label>' : ''}<button class="button button-outline" type="submit">Enviar mensagem</button></form></section>
      <section class="detail-card"><div class="section-heading"><h2>Anexos</h2><span class="count-badge">${attachmentsLoadError ? '—' : attachments.length}</span></div>${attachmentsLoadError ? '<div class="attachment-error" role="alert"><p>Não foi possível carregar os anexos.</p><button class="button button-outline" type="button" data-action="retry-attachments">Tentar novamente</button></div>' : attachments.length ? `<div class="attachment-list">${attachments.map((item) => renderAttachment(item, attachmentPreviews, failedPreviews)).join('')}</div>` : '<p class="muted">Nenhum anexo disponível.</p>'}<form id="attachment-form" class="attachment-form"><label for="detail-file">Adicionar anexo</label><input id="detail-file" type="file" name="file" accept="image/jpeg,image/png,image/webp,application/pdf"><div id="upload-progress"></div><button class="button button-outline" type="submit">Enviar arquivo</button></form></section>
      <section class="detail-card"><div class="section-heading"><h2>Histórico</h2><span class="count-badge">${history.length}</span></div>${history.length ? `<ol class="timeline">${history.map((event) => `<li><span class="timeline-dot"></span><div><strong>${escapeHtml(event.label || eventLabel(event.event))}</strong><p>${escapeHtml(typeof event.actor === 'string' ? event.actor : event.actor?.name || 'Sistema')} · ${formatDate(event.occurred_at)}</p>${event.description ? `<small>${escapeHtml(event.description)}</small>` : event.new_values?.status ? `<small>Status: ${statusLabel(event.new_values.status)}</small>` : ''}</div></li>`).join('')}</ol>` : '<p class="muted">Ainda não há atualizações no histórico.</p>'}</section>
      ${isRequester && ticket.status?.value === 'resolved' && !ratingResponse.data ? `<section class="detail-card"><h2>Avalie o atendimento</h2><form id="rating-form"><label for="score">Nota de 1 a 5</label><select id="score" name="score" required><option value="">Selecione</option><option value="5">5 — Excelente</option><option value="4">4 — Bom</option><option value="3">3 — Regular</option><option value="2">2 — Ruim</option><option value="1">1 — Muito ruim</option></select><textarea name="comment" rows="2" maxlength="2000" placeholder="Comentário opcional"></textarea><button class="button button-primary" type="submit">Enviar avaliação</button></form></section>` : ''}`;
}

function notificationScreen() {
    return `<section class="page-heading"><p class="eyebrow">ATUALIZAÇÕES</p><h1>Notificações</h1><p class="subheading">Acompanhe novidades dos seus chamados.</p></section><div id="notification-list"><div class="loading-card"><span class="spinner"></span><p>Carregando notificações…</p></div></div>`;
}

function profileScreen(user) {
    return `<section class="page-heading"><p class="eyebrow">SUA CONTA</p><h1>Perfil</h1><p class="subheading">Informações da sua conta CampusFix.</p></section><section class="profile-card"><div class="profile-avatar">${escapeHtml(initials(user.name))}</div><h2>${escapeHtml(user.name)}</h2><p>${escapeHtml(user.email)}</p><div class="profile-info"><div><small>Tipo de usuário</small><strong>${escapeHtml(userTypeLabel(user.user_type))}</strong></div><div><small>Perfil</small><strong>${escapeHtml(profileLabel(user))}</strong></div></div><button class="button button-danger button-full" data-action="logout">Sair da conta</button></section><p class="app-version">CampusFix · Aplicativo Android</p>`;
}

function bindGlobal(state) {
    state.root.querySelector('[data-action="notifications"]')?.addEventListener('click', () => { location.hash = '#/notifications'; });
    state.root.querySelector('[data-action="logout"]')?.addEventListener('click', () => void confirmAction(state, 'Sair da conta?', 'Sua sessão neste dispositivo será encerrada.', () => signOut(state, true)));
}

function bindLogin(state) {
    state.root.querySelector('[data-action="retry-session"]')?.addEventListener('click', async (event) => {
        const button = event.currentTarget;
        button.disabled = true;
        button.textContent = 'Verificando sessão…';

        try {
            state.user = (await campusfixApi.me()).data;
            state.sessionRecovery = null;
            location.hash = '#/dashboard';
            await navigate(state, '#/dashboard');
        } catch (error) {
            state.sessionRecovery = sessionRecoveryState(error);
            if (state.sessionRecovery.clearToken) await secureSession.clearToken();
            await navigate(state, '#/login');
        }
    });

    state.root.querySelector('#login-form')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        const data = new FormData(form);
        const email = String(data.get('email') || '').trim().toLowerCase();
        const password = String(data.get('password') || '');
        const errorBox = state.root.querySelector('#login-error');
        const button = form.querySelector('button');
        button.disabled = true;
        button.innerHTML = '<span class="spinner spinner-white"></span> Entrando…';
        errorBox.innerHTML = '';
        try {
            const response = await campusfixApi.login(email, password);
            await secureSession.saveToken(response.data.token);
            state.user = response.data.user;
            state.sessionRecovery = null;
            location.hash = '#/dashboard';
        } catch (error) {
            errorBox.innerHTML = `<p class="form-error">${escapeHtml(errorMessage(error))}</p>`;
            button.disabled = false;
            button.innerHTML = 'Entrar <span>→</span>';
        }
    });
}

function bindPage(state) {
    if (state.page.startsWith('#/tickets/new')) bindNewTicket(state);
    if (state.page.startsWith('#/tickets/') && !state.page.startsWith('#/tickets/new')) bindDetails(state);
    if (state.page.startsWith('#/tickets') && !state.page.startsWith('#/tickets/new') && !state.page.includes('/', 2)) bindTickets(state);
    if (state.page.startsWith('#/notifications')) void loadNotifications(state);
}

function bindTickets(state) {
    const form = state.root.querySelector('#ticket-filters');
    const results = state.root.querySelector('#ticket-results');
    const load = async (page = 1) => {
        state.ticketsPage = page;
        results.innerHTML = '<div class="loading-card"><span class="spinner"></span><p>Buscando chamados…</p></div>';
        try {
            const filters = Object.fromEntries(new FormData(form).entries());
            const response = await campusfixApi.tickets(filters, page);
            results.innerHTML = `${response.data.length ? `<div class="ticket-list">${response.data.map(ticketCard).join('')}</div>` : emptyState('Nenhum chamado encontrado', 'Tente ajustar os filtros ou abra uma nova solicitação.')}<div class="pagination"><button class="button button-outline" data-page="${page - 1}" ${page <= 1 ? 'disabled' : ''}>Anterior</button><span>Página ${response.meta.current_page} de ${response.meta.last_page}</span><button class="button button-outline" data-page="${page + 1}" ${page >= response.meta.last_page ? 'disabled' : ''}>Próxima</button></div>`;
            results.querySelectorAll('[data-page]').forEach((button) => button.addEventListener('click', () => void load(Number(button.dataset.page))));
        } catch (error) { results.innerHTML = errorPanel(errorMessage(error), () => void load(page)); }
    };
    form?.addEventListener('submit', (event) => { event.preventDefault(); void load(1); });
    void load(1);
}

function bindNewTicket(state) {
    const form = state.root.querySelector('#new-ticket-form');
    const campusSelect = form.elements.campus_id;
    const buildingSelect = form.elements.building_id;
    const locationSelect = form.elements.location_id;
    const fileInput = form.elements.attachments;
    campusSelect.addEventListener('change', async () => {
        buildingSelect.innerHTML = '<option value="">Carregando prédios…</option>';
        locationSelect.innerHTML = '<option value="">Selecione primeiro um prédio</option>';
        try {
            const data = (await campusfixApi.catalogs({ campus_id: campusSelect.value })).data;
            buildingSelect.innerHTML = `<option value="">Selecione o prédio</option>${data.buildings.map((item) => `<option value="${item.id}">${escapeHtml(item.name)}</option>`).join('')}`;
        } catch (error) { toast(state, errorMessage(error)); }
    });
    buildingSelect.addEventListener('change', async () => {
        locationSelect.innerHTML = '<option value="">Carregando locais…</option>';
        try {
            const data = (await campusfixApi.catalogs({ building_id: buildingSelect.value })).data;
            locationSelect.innerHTML = `<option value="">Selecione o local</option>${data.locations.map((item) => `<option value="${item.id}">${escapeHtml(item.name)}</option>`).join('')}`;
        } catch (error) { toast(state, errorMessage(error)); }
    });
    fileInput.addEventListener('change', () => renderFilePreviews(state, fileInput.files));
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const files = [...fileInput.files];
        const errorBox = state.root.querySelector('#form-error');
        const submit = form.querySelector('[type="submit"]');
        if (files.length > 5 || files.some((file) => file.size > 10 * 1024 * 1024 || !/^(image\/(jpeg|png|webp)|application\/pdf)$/.test(file.type))) {
            errorBox.innerHTML = '<p class="form-error">Escolha até 5 arquivos JPG, PNG, WebP ou PDF com no máximo 10 MB cada.</p>';
            return;
        }
        submit.disabled = true;
        submit.innerHTML = '<span class="spinner spinner-white"></span> Enviando…';
        errorBox.innerHTML = '';
        try {
            const data = Object.fromEntries(new FormData(form).entries());
            delete data.attachments;
            for (const field of ['building_id', 'location_id']) if (!data[field]) delete data[field];
            const created = await campusfixApi.createTicket(data);
            const failedFiles = [];
            for (let index = 0; index < files.length; index++) {
                try {
                    await campusfixApi.uploadAttachment(created.data.id, files[index], (percent) => { submit.innerHTML = `Enviando anexo ${index + 1}/${files.length} · ${percent}%`; });
                } catch {
                    failedFiles.push(files[index].name);
                }
            }
            toast(state, failedFiles.length ? `Chamado criado; ${failedFiles.length} anexo(s) falharam no envio.` : 'Solicitação enviada com sucesso.');
            location.hash = `#/tickets/${created.data.id}`;
        } catch (error) {
            errorBox.innerHTML = `<p class="form-error">${escapeHtml(errorMessage(error))}</p>`;
            submit.disabled = false;
            submit.innerHTML = 'Enviar solicitação <span>→</span>';
        }
    });
}

function bindDetails(state) {
    const id = state.page.split('/')[2];
    const assignmentForm = state.root.querySelector('#assignment-form');
    if (assignmentForm) {
        const teamSelect = assignmentForm.elements.team_id;
        const technicianSelect = assignmentForm.elements.assigned_user_id;
        const teams = state.assignmentOptions?.teams || [];
        const renderTechnicians = (preferredId = '') => {
            const team = teams.find((item) => Number(item.id) === Number(teamSelect.value));
            technicianSelect.innerHTML = `<option value="">Sem técnico</option>${(team?.technicians || []).map((item) => `<option value="${item.id}" ${Number(item.id) === Number(preferredId) ? 'selected' : ''}>${escapeHtml(item.name)}</option>`).join('')}`;
        };
        renderTechnicians(state.assignmentOptions?.current_assigned_user_id || '');
        teamSelect.addEventListener('change', () => renderTechnicians());
        assignmentForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const button = assignmentForm.querySelector('button[type="submit"]');
            button.disabled = true;
            try {
                await campusfixApi.assign(id, teamSelect.value, technicianSelect.value);
                toast(state, 'Atribuição atualizada.');
                await navigate(state, `#/tickets/${id}`);
            } catch (error) { toast(state, errorMessage(error)); button.disabled = false; }
        });
    }
    state.root.querySelector('[data-action="cancel-ticket"]')?.addEventListener('click', () => void confirmAction(state, 'Cancelar solicitação?', 'A solicitação será cancelada de acordo com as regras do sistema.', async () => {
        try { await campusfixApi.changeStatus(id, 'cancelled'); toast(state, 'Solicitação cancelada.'); await navigate(state, `#/tickets/${id}`); }
        catch (error) { toast(state, errorMessage(error)); }
    }));
    state.root.querySelector('#status-form')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const submit = event.currentTarget.querySelector('button');
        submit.disabled = true;
        try {
            await campusfixApi.changeStatus(id, event.currentTarget.elements.status.value);
            toast(state, 'Status atualizado.');
            await navigate(state, `#/tickets/${id}`);
        } catch (error) { toast(state, errorMessage(error)); submit.disabled = false; }
    });
    state.root.querySelector('#comment-form')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        const button = form.querySelector('button');
        button.disabled = true;
        try {
            await campusfixApi.addComment(id, form.elements.body.value, Boolean(form.elements.is_internal?.checked));
            toast(state, 'Mensagem enviada.');
            await navigate(state, `#/tickets/${id}`);
        } catch (error) { toast(state, errorMessage(error)); button.disabled = false; }
    });
    state.root.querySelector('#attachment-form')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        const file = form.elements.file.files[0];
        if (!file) return toast(state, 'Selecione um arquivo.');
        if (file.size > 10 * 1024 * 1024 || !/^(image\/(jpeg|png|webp)|application\/pdf)$/.test(file.type)) return toast(state, 'Formato ou tamanho de arquivo inválido.');
        const button = form.querySelector('button');
        button.disabled = true;
        try {
            await campusfixApi.uploadAttachment(id, file, (percent) => { form.querySelector('#upload-progress').textContent = `Enviando ${percent}%`; });
            toast(state, 'Anexo enviado.');
            await navigate(state, `#/tickets/${id}`);
        } catch (error) { toast(state, errorMessage(error)); button.disabled = false; }
    });
    state.root.querySelectorAll('[data-action="retry-attachments"]').forEach((button) => button.addEventListener('click', () => {
        void navigate(state, `#/tickets/${id}`);
    }));
    state.root.querySelectorAll('[data-preview-src]').forEach((button) => button.addEventListener('click', () => {
        openAttachmentPreview(state, button.dataset.previewSrc, button.dataset.previewAlt || 'Prévia da imagem');
    }));
    state.root.querySelectorAll('[data-close-preview]').forEach((button) => button.addEventListener('click', closeAttachmentPreview));
    state.root.querySelector('[data-preview-backdrop]')?.addEventListener('click', (event) => {
        if (event.target === event.currentTarget) closeAttachmentPreview();
    });
    state.root.querySelectorAll('[data-download]').forEach((link) => link.addEventListener('click', (event) => {
        event.preventDefault();
        void downloadAttachment(link.dataset.download, link.dataset.filename || link.textContent.trim()).catch((error) => toast(state, errorMessage(error)));
    }));
    state.root.querySelector('#rating-form')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        try {
            await campusfixApi.rate(id, Number(form.elements.score.value), form.elements.comment.value || null);
            toast(state, 'Obrigado pela avaliação.');
            await navigate(state, `#/tickets/${id}`);
        } catch (error) { toast(state, errorMessage(error)); }
    });
}

async function loadNotifications(state) {
    const container = state.root.querySelector('#notification-list');
    try {
        const response = await campusfixApi.notifications();
        const items = response.data || [];
        container.innerHTML = items.length ? `<div class="notification-list">${items.map((item) => `<article class="notification-card ${item.read_at ? '' : 'unread'}"><div class="notification-icon">${icon('bell')}</div><div><strong>${escapeHtml(notificationTitle(item))}</strong><p>${escapeHtml(item.data?.message || item.data?.body || 'Há uma atualização em um chamado.')}</p><small>${formatDate(item.created_at)}</small>${item.data?.ticket_id ? `<a class="text-link" href="#/tickets/${Number(item.data.ticket_id)}">Ver chamado →</a>` : ''}</div>${!item.read_at ? `<button class="mark-read" data-read="${escapeHtml(item.id)}" aria-label="Marcar como lida">✓</button>` : '<span class="read-indicator">Lida</span>'}</article>`).join('')}</div>` : emptyState('Você está em dia', 'Suas notificações aparecerão aqui.');
        container.querySelectorAll('[data-read]').forEach((button) => button.addEventListener('click', async () => {
            try { await campusfixApi.markNotificationRead(button.dataset.read); button.closest('.notification-card').classList.remove('unread'); button.remove(); toast(state, 'Notificação marcada como lida.'); }
            catch (error) { toast(state, errorMessage(error)); }
        }));
    } catch (error) { container.innerHTML = errorPanel(errorMessage(error), () => void loadNotifications(state)); }
}

async function downloadAttachment(url, filename) {
    const blob = await fetchPrivateAttachment(url);
    const objectUrl = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = objectUrl;
    anchor.download = filename;
    anchor.click();
    URL.revokeObjectURL(objectUrl);
}

async function fetchPrivateAttachment(url) {
    const token = await secureSession.getToken();
    if (!token) throw new ApiError('Sua sessão expirou. Entre novamente.', 401);
    const base = new URL(apiUrl);
    const basePath = base.pathname.replace(/\/$/, '');
    const sourcePath = new URL(url, apiUrl).pathname;
    if (!sourcePath.startsWith(`${basePath}/`)) throw new ApiError('Endereço de anexo inválido.');
    const filePath = sourcePath.slice(basePath.length);
    const response = await fetch(`${base.origin}${basePath}${filePath}`, { headers: { Authorization: `Bearer ${token}`, Accept: 'application/octet-stream' } });
    if (response.status === 401) {
        await secureSession.clearToken();
        window.dispatchEvent(new CustomEvent('campusfix:unauthorized'));
    }
    if (!response.ok) throw new ApiError('Não foi possível baixar este anexo.', response.status);
    return response.blob();
}

function renderAttachment(item, previews, failedPreviews) {
    const url = escapeHtml(item.download_url);
    const filename = escapeHtml(item.name);
    if (!isPreviewableImage(item)) {
        return `<a class="attachment-row" href="${url}" data-download="${url}" data-filename="${filename}"><span class="file-icon">${icon('file')}</span><span><strong>${filename}</strong><small>${escapeHtml(item.mime_type)} · ${formatBytes(item.size_bytes)}</small></span><b>↓</b></a>`;
    }

    const previewUrl = previews.get(item.id);
    const preview = previewUrl
        ? `<button class="attachment-image-preview" type="button" data-preview-src="${escapeHtml(previewUrl)}" data-preview-alt="${filename}"><img src="${escapeHtml(previewUrl)}" alt="Prévia de ${filename}"><span>Toque para ampliar</span></button>`
        : `<div class="attachment-image-fallback"><span class="file-icon">${icon('file')}</span><span>${failedPreviews.has(item.id) ? 'Prévia indisponível' : 'Imagem'}</span>${failedPreviews.has(item.id) ? '<button class="attachment-preview-retry" type="button" data-action="retry-attachments">Tentar novamente</button>' : ''}</div>`;

    return `<article class="attachment-image-card">${preview}<div class="attachment-image-meta"><span><strong>${filename}</strong><small>${escapeHtml(item.mime_type)} · ${formatBytes(item.size_bytes)}</small></span><a href="${url}" data-download="${url}" data-filename="${filename}">Baixar</a></div></article>`;
}

function openAttachmentPreview(state, source, alt) {
    const layer = state.root.querySelector('#confirm-layer');
    layer.innerHTML = `<div class="image-viewer-backdrop" data-preview-backdrop role="dialog" aria-modal="true" aria-label="Prévia ampliada"><div class="image-viewer"><button class="image-viewer-close" type="button" data-close-preview aria-label="Fechar prévia">×</button><img src="${escapeHtml(source)}" alt="${escapeHtml(alt)}"><p>${escapeHtml(alt)}</p></div></div>`;
    layer.querySelector('[data-close-preview]')?.addEventListener('click', closeAttachmentPreview);
    layer.querySelector('[data-preview-backdrop]')?.addEventListener('click', (event) => {
        if (event.target === event.currentTarget) closeAttachmentPreview();
    });
}

function closeAttachmentPreview() {
    document.querySelector('#confirm-layer')?.replaceChildren();
}

function releaseAttachmentPreviews(state) {
    for (const objectUrl of state.attachmentObjectUrls || []) URL.revokeObjectURL(objectUrl);
    state.attachmentObjectUrls = [];
}

async function signOut(state, callApi = true) {
    if (callApi) {
        try { await campusfixApi.logout(); } catch { /* local credentials are always cleared */ }
    }
    await secureSession.clearToken();
    state.user = null;
    location.hash = '#/login';
    await navigate(state, '#/login');
}

async function confirmAction(state, title, message, action) {
    const layer = state.root.querySelector('#confirm-layer');
    layer.innerHTML = `<div class="modal-backdrop"><section class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="confirm-title"><h2 id="confirm-title">${escapeHtml(title)}</h2><p>${escapeHtml(message)}</p><div><button class="button button-outline" data-cancel>Cancelar</button><button class="button button-danger" data-confirm>Continuar</button></div></section></div>`;
    layer.querySelector('[data-cancel]').addEventListener('click', () => { layer.innerHTML = ''; });
    layer.querySelector('[data-confirm]').addEventListener('click', async () => { layer.innerHTML = ''; await action(); });
}

function renderFilePreviews(state, files) {
    const preview = state.root.querySelector('#file-preview');
    preview.innerHTML = [...files].map((file) => `<div class="file-chip">${file.type.startsWith('image/') ? `<img src="${URL.createObjectURL(file)}" alt="Prévia de ${escapeHtml(file.name)}">` : `<span class="file-icon">${icon('file')}</span>`}<span>${escapeHtml(file.name)}</span></div>`).join('');
}

function ticketCard(ticket) {
    const status = ticket.status?.value || ticket.status;
    const sla = ticket.sla?.presentation;
    return `<a class="ticket-card" href="#/tickets/${ticket.id}"><div class="ticket-card-top"><span class="ticket-code">${escapeHtml(ticket.code)}</span>${statusPill(status)}</div><h3>${escapeHtml(ticket.title)}</h3>${sla && sla.state !== 'none' ? `<p class="ticket-sla-preview ${sla.state === 'overdue' || sla.state === 'late' ? 'danger' : sla.state === 'paused' ? 'paused' : ''}">${escapeHtml(sla.message)}</p>` : ''}<div class="ticket-card-bottom"><span class="priority-pill ${ticket.priority?.value || ticket.priority}">${priorityLabel(ticket.priority?.value || ticket.priority)}</span><span>${formatDate(ticket.created_at)}</span><span class="ticket-arrow">→</span></div></a>`;
}

function statCard(label, value, color, pictogram) {
    return `<article class="stat-card ${color}"><span class="stat-icon">${icon(pictogram)}</span><strong>${Number(value || 0)}</strong><small>${label}</small></article>`;
}

function statusPill(status) {
    return `<span class="status-pill status-${escapeHtml(status || 'open')}">${escapeHtml(statusLabel(status))}</span>`;
}

function emptyState(title, message) {
    return `<section class="empty-state"><span class="empty-icon">${icon('ticket')}</span><strong>${escapeHtml(title)}</strong><p>${escapeHtml(message)}</p><a class="button button-outline" href="#/tickets/new">Abrir chamado</a></section>`;
}

function errorPanel(message, retry) {
    return `<section class="error-panel"><span>!</span><strong>Não foi possível carregar</strong><p>${escapeHtml(message)}</p><button class="button button-outline" data-retry>Tentar novamente</button></section>`;
}

function bindGlobalRetry(state) {
    state.root.querySelector('[data-retry]')?.addEventListener('click', () => void navigate(state, state.page));
}

function toast(state, message) {
    const element = state.root.querySelector('#toast');
    if (!element) return;
    element.textContent = message;
    element.classList.add('visible');
    window.setTimeout(() => element.classList.remove('visible'), 3200);
}

function errorMessage(error) {
    if (error instanceof ApiError && error.status === 422) return Object.values(error.errors).flat()[0] || error.message;
    return error.message || 'Ocorreu um erro inesperado.';
}

function profileLabel(user) {
    const roles = user.roles || [];
    if (roles.includes('administrator')) return 'Administrador';
    if (roles.includes('manager')) return 'Gestor';
    if (roles.includes('technician')) return 'Técnico';
    return 'Solicitante';
}

function userTypeLabel(value) {
    return ({ student: 'Estudante', professor: 'Professor', staff: 'Funcionário', technician: 'Técnico', manager: 'Gestor', administrator: 'Administrador' })[value] || '—';
}

function firstName(name) { return (name || 'usuário').trim().split(/\s+/)[0]; }
function initials(name) { return (name || 'CF').trim().split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase(); }
function formatBytes(size = 0) { return size < 1024 * 1024 ? `${Math.max(1, Math.round(size / 1024))} KB` : `${(size / 1024 / 1024).toFixed(1)} MB`; }
function notificationTitle(item) { return String(item.type || '').replace(/([a-z])([A-Z])/g, '$1 $2') || 'Atualização CampusFix'; }
function eventLabel(event) { return ({ created: 'Solicitação criada', status_changed: 'Status atualizado', details_updated: 'Detalhes atualizados', team_assigned: 'Equipe atribuída', technician_assigned: 'Técnico atribuído', comment_added: 'Atualização na conversa', priority_changed: 'Prioridade atualizada', location_changed: 'Localização atualizada', resolved: 'Solicitação resolvida', closed: 'Solicitação encerrada', reopened: 'Solicitação reaberta', cancelled: 'Solicitação cancelada', sla_paused: 'Prazo pausado', sla_resumed: 'Prazo retomado', sla_breached: 'Prazo excedido', attachment_added: 'Anexo adicionado', rated: 'Atendimento avaliado' })[event] || 'Atualização da solicitação'; }

function renderSlaBanner(ticket) {
    const sla = ticket.sla?.presentation;
    if (!sla) return '';
    const tone = ['overdue', 'late'].includes(sla.state) ? 'danger' : sla.state === 'paused' ? 'paused' : ['on_time', 'success'].includes(sla.state) ? 'success' : '';
    return `<div class="sla-banner ${tone}">${icon('clock')}<span><small>Prazo de atendimento · ${sla.deadline ? formatDate(ticket.sla?.due_at) : 'sem prazo definido'}</small><strong>${escapeHtml(sla.message)}</strong>${sla.detail ? `<small class="sla-detail">${escapeHtml(sla.detail)}</small>` : ''}</span></div>`;
}

function icon(name) {
    const shapes = {
        home: '<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
        ticket: '<path d="M3 8V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v3a3 3 0 0 0 0 6v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-3a3 3 0 0 0 0-6z"/><path d="M13 5v2m0 4v2m0 4v2"/>',
        bell: '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/>',
        user: '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        plus: '<path d="M12 5v14m-7-7h14"/>',
        clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        check: '<path d="m5 12 4 4L19 6"/>',
        alert: '<path d="M12 9v4m0 4h.01"/><path d="M10.3 3.8 1.9 18.3A2 2 0 0 0 3.6 21h16.8a2 2 0 0 0 1.7-2.7L13.7 3.8a2 2 0 0 0-3.4 0z"/>',
        search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
        file: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8m-8 4h8"/>',
    };
    return `<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">${shapes[name] || shapes.ticket}</svg>`;
}
