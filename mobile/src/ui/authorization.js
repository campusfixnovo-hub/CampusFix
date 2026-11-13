const STAFF_ROLES = ['technician', 'manager', 'administrator'];

/**
 * UI capability hints derived from the authenticated user's API roles.
 * The backend Policies remain authoritative for every protected operation.
 */
export function ticketPermissions(user) {
    const roles = Array.isArray(user?.roles) ? user.roles : [];

    return {
        canAssign: roles.some((role) => ['manager', 'administrator'].includes(role)),
        canManage: roles.some((role) => STAFF_ROLES.includes(role)),
        canAddInternalNote: roles.some((role) => STAFF_ROLES.includes(role)),
    };
}
