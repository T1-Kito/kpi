(function () {
    const TOKEN_KEY = 'vk_token';
    const pendingRequests = new Map();
    const responseCache = new Map();
    const GET_CACHE_TTL = 15000;
    const REQUEST_TIMEOUT = 12000;

    async function request(path, options = {}) {
        const token = localStorage.getItem(TOKEN_KEY);
        const method = String(options.method || 'GET').toUpperCase();
        const useCache = method === 'GET' && options.cache !== false;

        if (useCache) {
            const cached = responseCache.get(path);
            if (cached && cached.expiresAt > Date.now()) return cached.body;
            if (cached) responseCache.delete(path);
        }

        if (method === 'GET' && pendingRequests.has(path)) {
            return pendingRequests.get(path);
        }

        const pending = performRequest(path, options, token);
        if (method === 'GET') {
            pendingRequests.set(path, pending);
            pending.then(
                (body) => {
                    if (pendingRequests.get(path) === pending) pendingRequests.delete(path);
                    if (useCache) {
                        responseCache.set(path, {
                            body,
                            expiresAt: Date.now() + GET_CACHE_TTL,
                        });
                    }
                },
                () => {
                    if (pendingRequests.get(path) === pending) pendingRequests.delete(path);
                },
            );
        } else {
            responseCache.clear();
        }
        return pending;
    }

    async function performRequest(path, options, token) {
        const isForm = options.body instanceof FormData;
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), options.timeout || REQUEST_TIMEOUT);
        let response;

        try {
            response = await fetch('/api/v1' + path, {
                ...options,
                signal: options.signal || controller.signal,
                headers: {
                    ...(isForm ? {} : { 'Content-Type': 'application/json' }),
                    ...(token ? { Authorization: `Bearer ${token}` } : {}),
                    ...(options.headers || {}),
                },
            });
        } catch (error) {
            if (error.name === 'AbortError') {
                throw new Error('Máy chủ phản hồi quá chậm. Vui lòng thử lại.');
            }
            throw error;
        } finally {
            window.clearTimeout(timeout);
        }

        const body = await response.json().catch(() => ({}));
        if (!response.ok) {
            const fields = body.fields ? Object.values(body.fields).flat().join(' ') : '';
            throw new Error([body.message, fields].filter(Boolean).join(' ') || 'Có lỗi xảy ra.');
        }

        return body;
    }

    function token() {
        return localStorage.getItem(TOKEN_KEY);
    }

    function setToken(value) {
        localStorage.setItem(TOKEN_KEY, value);
        responseCache.clear();
    }

    function clearToken() {
        localStorage.removeItem(TOKEN_KEY);
        pendingRequests.clear();
        responseCache.clear();
    }

    function clearCache() {
        responseCache.clear();
    }

    window.VKApi = { request, token, setToken, clearToken, clearCache };
})();
