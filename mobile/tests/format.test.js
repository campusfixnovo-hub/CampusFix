import test from 'node:test';
import assert from 'node:assert/strict';
import { escapeHtml, formatDate, priorityLabel, statusLabel } from '../src/ui/format.js';

test('escapes user content before rendering it in the interface', () => {
    assert.equal(escapeHtml('<script>alert("x")</script>'), '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;');
});

test('shows localized labels for API ticket status and priority values', () => {
    assert.equal(statusLabel('awaiting_requester'), 'Aguardando você');
    assert.equal(statusLabel('unexpected'), 'Aberta');
    assert.equal(priorityLabel('critical'), 'Crítica');
    assert.equal(priorityLabel('low'), 'Baixa');
});

test('formats timestamps in Brazilian Portuguese and handles missing values', () => {
    assert.equal(formatDate(null), '—');
    assert.match(formatDate('2026-11-09T12:00:00.000Z'), /09 de nov\./);
});
