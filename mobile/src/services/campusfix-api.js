import { api } from './api.js';
import { secureSession } from './secure-session.js';
import { isStoredAttachmentResponse } from './attachment-utils.js';

export const campusfixApi = {
    login: (email, password) => api('/auth/login', { method: 'POST', body: { email, password, device_name: 'CampusFix Android' } }),
    logout: () => api('/auth/logout', { method: 'POST' }),
    me: () => api('/auth/me'),
    dashboard: () => api('/dashboard'),
    catalogs: (filters = {}) => api(`/catalogs${queryString(filters)}`),
    tickets: (filters = {}, page = 1) => api(`/tickets${queryString({ ...filters, page })}`),
    ticket: (id) => api(`/tickets/${id}`),
    rating: (id) => api(`/tickets/${id}/rating`),
    createTicket: (data) => api('/tickets', { method: 'POST', body: data }),
    updateTicket: (id, data) => api(`/tickets/${id}`, { method: 'PATCH', body: data }),
    changeStatus: (id, status) => api(`/tickets/${id}/status`, { method: 'POST', body: { status } }),
    assignmentOptions: (id) => api(`/tickets/${id}/assignment-options`),
    assign: (id, teamId, technicianId) => api(`/tickets/${id}/assignment`, { method: 'POST', body: { team_id: teamId || null, assigned_user_id: technicianId || null } }),
    history: (id, page = 1) => api(`/tickets/${id}/history?page=${page}`),
    comments: (id, page = 1) => api(`/tickets/${id}/comments?page=${page}`),
    addComment: (id, body, isInternal = false) => api(`/tickets/${id}/comments`, { method: 'POST', body: { body, is_internal: isInternal } }),
    attachments: (id, page = 1) => api(`/tickets/${id}/attachments?page=${page}`),
    uploadAttachment: (id, file, onProgress) => uploadFile(`/tickets/${id}/attachments`, file, onProgress),
    rate: (id, score, comment) => api(`/tickets/${id}/rating`, { method: 'POST', body: { score, comment } }),
    notifications: (page = 1) => api(`/notifications?page=${page}`),
    markNotificationRead: (id) => api(`/notifications/${encodeURIComponent(id)}/read`, { method: 'PATCH' }),
    reports: (filters = {}) => api(`/reports${queryString(filters)}`),
};

function queryString(values) {
    const query = new URLSearchParams();
    for (const [key, value] of Object.entries(values)) {
        if (value !== undefined && value !== null && value !== '') query.set(key, value);
    }
    const result = query.toString();
    return result ? `?${result}` : '';
}

async function uploadFile(path, file, onProgress = () => {}) {
    const base = import.meta.env.VITE_API_URL?.replace(/\/$/, '');
    if (!base) throw new Error('Configure VITE_API_URL para conectar à API.');

    const form = new FormData();
    form.append('file', file);
    const authToken = await secureSession.getToken();
    return new Promise((resolve, reject) => {
        const request = new XMLHttpRequest();
        request.open('POST', `${base}${path}`);
        request.timeout = 30000;
        request.setRequestHeader('Accept', 'application/json');
        if (authToken) request.setRequestHeader('Authorization', `Bearer ${authToken}`);
        request.upload.onprogress = (event) => {
            if (event.lengthComputable) onProgress(Math.round((event.loaded / event.total) * 100));
        };
        request.onload = () => {
            let payload = {};
            try { payload = JSON.parse(request.responseText); } catch { /* non-JSON error handled below */ }
            if (request.status === 401) {
                void secureSession.clearToken();
                window.dispatchEvent(new CustomEvent('campusfix:unauthorized'));
            }
            if (isStoredAttachmentResponse(request.status, payload)) resolve(payload);
            else reject(new Error(payload.message || 'A API não confirmou o armazenamento do anexo. Tente novamente.'));
        };
        request.onerror = () => reject(new Error('Falha de rede durante o envio do arquivo.'));
        request.ontimeout = () => reject(new Error('O envio do arquivo excedeu o tempo limite.'));
        request.send(form);
    });
}
