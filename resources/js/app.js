import '@tabler/core/dist/js/tabler.min.js';
document.querySelectorAll('[data-confirm-delete]').forEach(form=>form.addEventListener('submit',event=> {
    if(!window.confirm(form.dataset.confirmMessage)) event.preventDefault();
}));

// Values remain only in the existing field. Submission and page hiding restore masking.
const passwordControls = [];
document.querySelectorAll('[data-password-toggle]').forEach(button => {
    const input = button.parentElement.querySelector('input');
    if (!input) return;
    const setVisible = visible => {
        input.type = visible ? 'text' : 'password';
        const label = visible ? button.dataset.hideLabel : button.dataset.showLabel;
        button.setAttribute('aria-label', label);
        button.title = label;
        button.setAttribute('aria-pressed', String(visible));
        button.querySelector('i').className = visible ? 'bi bi-eye-slash' : 'bi bi-eye';
    };
    button.hidden = false;
    button.addEventListener('click', () => {
        setVisible(input.type === 'password');
        input.focus({preventScroll: true});
    });
    input.form?.addEventListener('submit', () => setVisible(false));
    passwordControls.push(() => setVisible(false));
});
window.addEventListener('pagehide', () => passwordControls.forEach(hide => hide()));
document.addEventListener('visibilitychange', () => {
    if(document.hidden) passwordControls.forEach(hide => hide());
});

