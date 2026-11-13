import { secureSession } from './secure-session.js';
import { validateApiBaseUrl } from './api-environment.js';

const baseUrl = validateApiBaseUrl(import.meta.env.VITE_API_URL || '', import.meta.env.MODE);
const timeoutMs = 15000;

export class ApiError extends Error {
    constructor(message, status = 0, errors = {}) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.errors = errors;
    }
}

export async function api(path, options = {}) {
    const token = await secureSession.getToken();
    const controller = new AbortController();
    const timer = window.setTimeout(() => controller.abort(), timeoutMs);
    const headers = new Headers(options.headers || {});
    headers.set('Accept', 'application/json');
    if (token) headers.set('Authorization', `Bearer ${token}`);
    if (!(options.body instanceof FormData) && options.body !== undefined) headers.set('Content-Type', 'application/json');

    try {
        const response = await fetch(`${baseUrl}${path}`, {
            ...options,
            headers,
            signal: controller.signal,
            body: options.body && !(options.body instanceof FormData) ? JSON.stringify(options.body) : options.body,
        });
        if (response.status === 401) {
            await secureSession.clearToken();
            window.dispatchEvent(new CustomEvent('campusfix:unauthorized'));
        }
        if (!response.ok) {
            const payload = await response.json().catch(() => ({}));
            throw new ApiError(payload.message || 'Não foi possível concluir a operação.', response.status, payload.errors || {});
        }
        if (response.status === 204) return null;
        const contentType = response.headers.get('content-type') || '';
        return contentType.includes('application/json') ? response.json() : response;
    } catch (error) {
        if (error instanceof ApiError) throw error;
        if (error.name === 'AbortError') throw new ApiError('A conexão demorou demais. Tente novamente.');
        throw new ApiError('Não foi possível conectar à API. Verifique sua rede e o endereço configurado.');
    } finally {
        window.clearTimeout(timer);
    }
}

export const apiUrl = baseUrl;
