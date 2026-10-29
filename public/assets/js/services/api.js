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
        const { cache: _appCache, timeout: _appTimeout, ...fetchOptions } = options;
        let response;

        try {
            response = await fetch('/api/v1' + path, {
                ...fetchOptions,
                signal: options.signal || controller.signal,
                headers: {
                    Accept: 'application/json',
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
            const error = new Error([body.message, fields].filter(Boolean).join(' ') || 'Có lỗi xảy ra.');
            error.status = response.status;
            throw error;
        }

        if (String(options.method || 'GET').toUpperCase() !== 'GET') {
            window.dispatchEvent(new CustomEvent('vk:data-mutated', {
                detail: { path, method: String(options.method || 'GET').toUpperCase() },
            }));
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

    function newRequestKey() {
        const cryptoApi = window.crypto;
        if (typeof cryptoApi?.randomUUID === 'function') return cryptoApi.randomUUID();
        const bytes = new Uint8Array(16);
        if (typeof cryptoApi?.getRandomValues === 'function') cryptoApi.getRandomValues(bytes);
        else {
            // Compatibility ID for request deduplication only; never an auth token.
            for (let i=0;i<bytes.length;i++) bytes[i]=Math.floor(Math.random()*256);
        }
        bytes[6]=(bytes[6]&15)|64;bytes[8]=(bytes[8]&63)|128;
        const hex=Array.from(bytes,b=>b.toString(16).padStart(2,'0')).join('');
        return `${hex.slice(0,8)}-${hex.slice(8,12)}-${hex.slice(12,16)}-${hex.slice(16,20)}-${hex.slice(20)}`;
    }
    window.VKApi = { request, token, setToken, clearToken, clearCache, newRequestKey };
})();
