// Tenant website shell (layouts/tenant): the header's <details> menus, "Sắp ra mắt" items and the back-to-top button.

/** Open menus to close when `opened` opens: every other one that does not contain it. */
export function menusToClose(menus, opened) {
    return menus.filter((menu) => menu !== opened && menu.open && !menu.contains(opened));
}

export function installStudentSite(root = document) {
    const site = root.querySelector('[data-student-site]');
    if (!site) {
        return;
    }
    const menus = [...site.querySelectorAll('.sb2b-header details:not(.sb2b-mobile-resources)')];
    const status = site.querySelector('[data-coming-soon-status]');
    let hideStatus = null;

    menus.forEach((menu) => menu.addEventListener('toggle', () => {
        if (menu.open) {
            menusToClose(menus, menu).forEach((other) => { other.open = false; });
        }
    }));

    root.addEventListener('click', (event) => {
        menus.forEach((menu) => {
            if (menu.open && !menu.contains(event.target)) {
                menu.open = false;
            }
        });
        const locked = event.target.closest?.('[data-coming-soon]');
        if (locked && site.contains(locked)) {
            event.preventDefault();
            menus.forEach((menu) => { menu.open = false; });
            status.textContent = site.dataset.comingSoonMessage;
            status.classList.add('is-visible');
            clearTimeout(hideStatus);
            hideStatus = setTimeout(() => status.classList.remove('is-visible'), 3000);
        }
    });

    root.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            const open = menus.find((menu) => menu.open);
            menus.forEach((menu) => { menu.open = false; });
            open?.querySelector('summary')?.focus();
        }
    });

    const toTop = site.querySelector('[data-to-top]');
    const update = () => toTop.classList.toggle('is-visible', window.scrollY > 300);
    window.addEventListener('scroll', update, { passive: true });
    toTop.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
    update();
}
