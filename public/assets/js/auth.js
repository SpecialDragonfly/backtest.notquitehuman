document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.logout-btn').forEach((el) => {
        el.addEventListener('click', async (e) => {
            e.preventDefault();
            await fetch('/logout', { method: 'POST' });
            window.location.href = '/login';
        });
    });
});
