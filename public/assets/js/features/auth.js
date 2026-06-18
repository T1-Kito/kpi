(function () {
document.addEventListener('DOMContentLoaded', () => {
    if (VKApi.token()) {
        window.location.href = '/dashboard';
        return;
    }

    document.getElementById('loginForm').addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = document.getElementById('loginBtn');
        const error = document.getElementById('loginError');
        button.disabled = true;
        error.style.display = 'none';

        try {
            const body = await VKApi.request('/auth/login', {
                method: 'POST',
                body: JSON.stringify({
                    email: document.getElementById('email').value,
                    password: document.getElementById('password').value,
                }),
            });
            VKApi.setToken(body.data.access_token);
            if (body.data.user) {
                localStorage.setItem('vk.currentUser.v3', JSON.stringify(body.data.user));
            }
            window.location.href = '/dashboard';
        } catch (e) {
            error.textContent = e.message;
            error.style.display = 'block';
        } finally {
            button.disabled = false;
        }
    });
});
})();
