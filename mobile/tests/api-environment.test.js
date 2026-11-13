import test from 'node:test';
import assert from 'node:assert/strict';
import { validateApiBaseUrl } from '../src/services/api-environment.js';

test('allows HTTPS API URLs in production', () => {
    assert.equal(validateApiBaseUrl('https://api.example.edu/api/v1/', 'production'), 'https://api.example.edu/api/v1');
});

test('rejects HTTP API URLs in production regardless of the host', () => {
    assert.throws(
        () => validateApiBaseUrl('http://10.0.2.2:8000/api/v1', 'production'),
        { message: 'A API de produção precisa usar HTTPS.' },
    );
});

test('allows HTTP only for authorized local hosts in development', () => {
    for (const host of ['10.0.2.2', 'localhost', '127.0.0.1', '127.20.30.40', '[::1]']) {
        assert.match(validateApiBaseUrl(`http://${host}:8000/api/v1`, 'development'), /^http:/);
    }
});

test('rejects HTTP to non-local hosts and in non-development modes', () => {
    assert.throws(() => validateApiBaseUrl('http://example.com/api/v1', 'development'), /HTTP só é permitido/);
    assert.throws(() => validateApiBaseUrl('http://localhost/api/v1', 'test'), /HTTP só é permitido/);
});

test('rejects missing, malformed, credential-bearing, and unsupported API URLs', () => {
    assert.throws(() => validateApiBaseUrl('', 'development'), /Configure VITE_API_URL/);
    assert.throws(() => validateApiBaseUrl('not a URL', 'development'), /endereço HTTP ou HTTPS válido/);
    assert.throws(() => validateApiBaseUrl('https://user:secret@example.com/api', 'production'), /não pode conter credenciais/);
    assert.throws(() => validateApiBaseUrl('ftp://localhost/api', 'development'), /deve usar HTTPS/);
});
