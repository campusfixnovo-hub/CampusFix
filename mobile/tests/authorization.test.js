import test from 'node:test';
import assert from 'node:assert/strict';
import { ticketPermissions } from '../src/ui/authorization.js';

test('requester profiles do not receive staff-only ticket controls', () => {
    for (const role of ['student', 'professor', 'staff']) {
        assert.deepEqual(ticketPermissions({ roles: [role] }), {
            canAssign: false,
            canManage: false,
            canAddInternalNote: false,
        });
    }
});

test('technicians can manage tickets and add internal notes but cannot assign', () => {
    assert.deepEqual(ticketPermissions({ roles: ['technician'] }), {
        canAssign: false,
        canManage: true,
        canAddInternalNote: true,
    });
});

test('managers can manage, assign, and add internal notes', () => {
    assert.deepEqual(ticketPermissions({ roles: ['manager'] }), {
        canAssign: true,
        canManage: true,
        canAddInternalNote: true,
    });
});

test('administrators can manage, assign, and add internal notes', () => {
    assert.deepEqual(ticketPermissions({ roles: ['administrator'] }), {
        canAssign: true,
        canManage: true,
        canAddInternalNote: true,
    });
});

test('missing or malformed roles fail closed', () => {
    for (const user of [null, {}, { roles: null }, { roles: 'administrator' }]) {
        assert.deepEqual(ticketPermissions(user), {
            canAssign: false,
            canManage: false,
            canAddInternalNote: false,
        });
    }
});
