const sidebar = document.querySelector('[data-app-sidebar]');
const backdrop = document.querySelector('[data-sidebar-backdrop]');
const toggles = document.querySelectorAll('[data-sidebar-toggle]');

function setSidebarOpen(isOpen) {
    if (!sidebar || !backdrop) return;

    sidebar.classList.toggle('-translate-x-full', !isOpen);
    backdrop.classList.toggle('hidden', !isOpen);
    document.body.classList.toggle('overflow-hidden', isOpen);
    toggles.forEach((toggle) => toggle.setAttribute('aria-expanded', String(isOpen)));
}

toggles.forEach((toggle) => toggle.addEventListener('click', () => {
    const isOpen = toggles[0]?.getAttribute('aria-expanded') === 'true';
    setSidebarOpen(!isOpen);
}));

backdrop?.addEventListener('click', () => setSidebarOpen(false));

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') setSidebarOpen(false);
});
