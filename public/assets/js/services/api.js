(function () {
    const TOKEN_KEY = 'vk_token';

    async function request(path, options = {}) {
        const token = localStorage.getItem(TOKEN_KEY);
        const isForm = options.body instanceof FormData;
        const response = await fetch('/api/v1' + path, {
            ...options,
            headers: {
                ...(isForm ? {} : { 'Content-Type': 'application/json' }),
                ...(token ? { Authorization: `Bearer ${token}` } : {}),
                ...(options.headers || {}),
            },
        });
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
    }

    function clearToken() {
        localStorage.removeItem(TOKEN_KEY);
    }

    window.VKApi = { request, token, setToken, clearToken };
})();
