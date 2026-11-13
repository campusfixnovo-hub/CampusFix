import test from 'node:test';
import assert from 'node:assert/strict';
import { sessionRecoveryState } from '../src/services/session-recovery.js';

test('clears an invalid or expired session after HTTP 401', () => {
    assert.deepEqual(sessionRecoveryState({ status: 401 }), {
        clearToken: true,
        canRetry: false,
        message: 'Sua sessão expirou ou foi invalidada. Entre novamente.',
    });
});

test('preserves a session and offers retry when the API cannot be reached', () => {
    assert.deepEqual(sessionRecoveryState({ status: 0 }), {
        clearToken: false,
        canRetry: true,
        message: 'Não foi possível conectar à API. Sua sessão foi preservada; verifique a rede e tente novamente.',
    });
});

test('preserves a session for non-authentication HTTP errors', () => {
    const forbidden = sessionRecoveryState({ status: 403 });
    const serverError = sessionRecoveryState({ status: 503 });

    assert.equal(forbidden.clearToken, false);
    assert.equal(forbidden.canRetry, false);
    assert.equal(serverError.clearToken, false);
    assert.equal(serverError.canRetry, true);
});
