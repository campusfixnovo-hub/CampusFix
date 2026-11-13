import { Capacitor } from '@capacitor/core';
import { SecureStorage } from '@aparajita/capacitor-secure-storage';

const TOKEN_KEY = 'campusfix.access-token';

let browserSessionToken = null;

export const secureSession = {
    async getToken() {
        if (Capacitor.isNativePlatform()) {
            try {
                return await SecureStorage.get(TOKEN_KEY);
            } catch {
                return null;
            }
        }

        return browserSessionToken;
    },

    async saveToken(token) {
        if (Capacitor.isNativePlatform()) {
            await SecureStorage.set(TOKEN_KEY, token);
            return;
        }

        browserSessionToken = token;
    },

    async clearToken() {
        if (Capacitor.isNativePlatform()) {
            try {
                await SecureStorage.remove(TOKEN_KEY);
            } catch {
                // A missing key is already a cleared session.
            }
        }

        browserSessionToken = null;
    },
};
