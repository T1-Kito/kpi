document.addEventListener('DOMContentLoaded', () => {
    if (VKApi.token()) {
        window.location.href = '/dashboard';
        return;
    }

    document.querySelectorAll('[data-demo]').forEach((button) => {
        button.addEventListener('click', () => {
            document.getElementById('email').value = button.dataset.demo;
            document.getElementById('password').value = 'Admin@123';
        });
    });

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
            window.location.href = '/dashboard';
        } catch (e) {
            error.textContent = e.message;
            error.style.display = 'block';
        } finally {
            button.disabled = false;
        }
    });
});
