import { defineConfig, loadEnv } from 'vite';
import { validateApiBaseUrl } from './src/services/api-environment.js';

export default defineConfig(({ mode }) => {
    const fileEnvironment = loadEnv(mode, process.cwd(), 'VITE_');
    const configuredApiUrl = process.env.VITE_API_URL || fileEnvironment.VITE_API_URL || '';

    if (mode === 'production') {
        validateApiBaseUrl(configuredApiUrl, mode);
    }

    return {
        plugins: [{
            name: 'campusfix-build-mode-marker',
            generateBundle() {
                this.emitFile({
                    type: 'asset',
                    fileName: 'campusfix-build-mode.txt',
                    source: `${mode}\n`,
                });
            },
        }],
        server: {
            host: '0.0.0.0',
            port: 5173,
        },
    };
});
