const localHosts = new Set(['10.0.2.2', 'localhost', '::1']);

function isIpv4Loopback(hostname) {
    const octets = hostname.split('.').map(Number);

    return octets.length === 4
        && octets.every((octet, index) => Number.isInteger(octet) && octet >= 0 && octet <= 255 && String(octet) === hostname.split('.')[index])
        && octets[0] === 127;
}

export function validateApiBaseUrl(configuredUrl, mode) {
    if (typeof configuredUrl !== 'string' || configuredUrl.trim() === '') {
        throw new Error('Configure VITE_API_URL para conectar ao CampusFix.');
    }

    let parsedUrl;
    try {
        parsedUrl = new URL(configuredUrl);
    } catch {
        throw new Error('VITE_API_URL precisa ser um endereço HTTP ou HTTPS válido.');
    }

    if (parsedUrl.username || parsedUrl.password) {
        throw new Error('VITE_API_URL não pode conter credenciais.');
    }

    if (parsedUrl.protocol === 'https:') {
        return configuredUrl.replace(/\/+$/, '');
    }

    if (parsedUrl.protocol !== 'http:') {
        throw new Error('A API deve usar HTTPS, exceto para hosts locais no modo de desenvolvimento.');
    }

    if (mode === 'production') {
        throw new Error('A API de produção precisa usar HTTPS.');
    }

    const hostname = parsedUrl.hostname.toLowerCase().replace(/^\[|\]$/g, '');
    const isAuthorizedLocalHost = localHosts.has(hostname) || isIpv4Loopback(hostname);

    if (mode !== 'development' || !isAuthorizedLocalHost) {
        throw new Error('HTTP só é permitido em desenvolvimento para 10.0.2.2, localhost ou endereços de loopback.');
    }

    return configuredUrl.replace(/\/+$/, '');
}
