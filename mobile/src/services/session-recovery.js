export function sessionRecoveryState(error) {
    const status = Number.isInteger(error?.status) ? error.status : 0;

    if (status === 401) {
        return {
            clearToken: true,
            canRetry: false,
            message: 'Sua sessão expirou ou foi invalidada. Entre novamente.',
        };
    }

    if (status === 0) {
        return {
            clearToken: false,
            canRetry: true,
            message: 'Não foi possível conectar à API. Sua sessão foi preservada; verifique a rede e tente novamente.',
        };
    }

    if (status === 403) {
        return {
            clearToken: false,
            canRetry: false,
            message: 'A API recusou o acesso à sessão. Verifique se sua conta está ativa ou entre novamente.',
        };
    }

    return {
        clearToken: false,
        canRetry: status >= 500 || status === 408 || status === 429,
        message: `Não foi possível verificar a sessão (HTTP ${status}). O token foi preservado.`,
    };
}
