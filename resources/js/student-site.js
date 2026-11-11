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

    installFavorites(root, site, (message) => {
        status.textContent = message;
        status.classList.add('is-visible');
        clearTimeout(hideStatus);
        hideStatus = setTimeout(() => status.classList.remove('is-visible'), 3000);
    });

    const toTop = site.querySelector('[data-to-top]');
    const update = () => toTop.classList.toggle('is-visible', window.scrollY > 300);
    window.addEventListener('scroll', update, { passive: true });
    toTop.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
    update();
}

/**
 * "Quan tâm" (ADR-0030 D4): the heart forms are sent in the background and every heart of the same Product on the
 * page follows. Without script the form posts and the page comes back.
 */
export function installFavorites(root, site, notify) {
    const csrf = root.querySelector('meta[name="csrf-token"]')?.content;

    const show = (productId, favorite) => {
        site.querySelectorAll(`[data-favorite="${productId}"]`).forEach((button) => {
            const form = button.closest('[data-favorite-form]');
            const label = favorite ? button.dataset.labelRemove : button.dataset.labelAdd;
            button.classList.toggle('is-liked', favorite);
            button.setAttribute('aria-pressed', String(favorite));
            button.setAttribute('aria-label', label);
            button.title = label;
            const text = button.querySelector('[data-favorite-text]');
            if (text) text.textContent = label;
            form.action = favorite ? form.dataset.removeUrl : form.dataset.addUrl;
            form.querySelector('[data-favorite-method]').value = favorite ? 'DELETE' : 'POST';
        });
    };

    site.addEventListener('submit', async (event) => {
        const form = event.target.closest?.('[data-favorite-form]');
        if (!form) return;
        event.preventDefault();
        const button = form.querySelector('[data-favorite]');
        if (button.getAttribute('aria-busy') === 'true') return;
        const favorite = button.getAttribute('aria-pressed') !== 'true';
        button.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(favorite ? form.dataset.addUrl : form.dataset.removeUrl, {
                method: favorite ? 'POST' : 'DELETE',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrf || form.querySelector('input[name="_token"]').value,
                },
            });
            if (!response.ok) throw new Error(String(response.status));
            show(button.dataset.favorite, (await response.json()).favorite === true);
        } catch {
            notify(button.dataset.labelFailed);
        } finally {
            button.removeAttribute('aria-busy');
        }
    });
}
