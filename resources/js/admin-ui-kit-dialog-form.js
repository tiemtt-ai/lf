/**
 * Opt-in "form in a dialog" adapter for UI Kit lists. The static dialog adapter (admin-ui-kit-dialog.js) only
 * opens markup that is already on the page; this one loads a form fragment from the server into one empty
 * <dialog data-ui-dialog-form>, submits it with fetch and reports the outcome. Presentation lives in
 * admin-ui-kit.css; the server keeps validation, authorization and tenant scope.
 *
 * Contract: openers carry [data-ui-dialog-form-open][data-url]; the fragment is one <form class="ui-modal__form">
 * (X-Requested-With request); a successful save answers 2xx JSON {message}, a validation failure 422 JSON
 * {errors}. Closing a form that has unsaved changes asks first through the shared LF confirmation dialog (window.LFConfirm). Only the latest open request may touch the page; one submit at a time.
 */
import { initUiKitSelect, initUiKitDateInputs, initUiKitMediaSlots, initUiKitEmbedSlots, showUiToast, hideUiToast, claimOnce, releaseClaim, followRedirect } from './admin-ui-kit-controls.js';

const instances = new WeakMap();
export const UI_DIALOG_FORM_SAVED_EVENT = 'ui-dialog-form-saved';

export function serializeForm(form, FormDataCtor = globalThis.FormData) {
    const parts = [];
    for (const [key, value] of new FormDataCtor(form)) {
        if (key === '_token' || key === '_method') continue;
        const isFile = typeof value === 'object' && value !== null;
        parts.push(`${key}=${isFile ? (value.name ? `${value.name}:${value.size}:${value.lastModified}` : '') : value}`);
    }
    return parts.join('\n');
}

export function initUiKitDialogForm(scope, deps = {}) {
    if (instances.has(scope)) return instances.get(scope);
    const dialog = scope.querySelector('dialog[data-ui-dialog-form]');
    if (!dialog) return null;
    if (!claimOnce(scope, 'DialogForm')) return null;
    const win = deps.window ?? window;
    const parse = deps.parse ?? (html => new win.DOMParser().parseFromString(html, 'text/html'));
    const snapshotOf = deps.serialize ?? (form => serializeForm(form));
    const initSelect = deps.initSelect ?? initUiKitSelect;
    const initSlots = deps.initSlots ?? (scopeNode => [...initUiKitDateInputs(scopeNode), ...initUiKitMediaSlots(scopeNode), ...initUiKitEmbedSlots(scopeNode)]);
    const confirmAction = deps.confirm ?? (options => win.LFConfirm.open(options));
    const createEvent = deps.createEvent ?? ((type, detail) => new CustomEvent(type, { bubbles: true, detail }));
    const defer = deps.defer ?? (() => new Promise(resolve => win.setTimeout(resolve, 0)));
    const message = name => dialog.getAttribute(`data-msg-${name}`) || '';
    const escapeId = id => (win.CSS?.escape ? win.CSS.escape(id) : id);
    const part = name => scope.querySelector(`[data-ui-dialog-form-${name}]`);

    let seq = 0;
    let controller = null;
    let form = null;
    let opener = null;
    let openerUrl = '';
    let snapshot = '';
    let submitting = false;
    let controls = [];
    let confirming = false;
    let activeXhr = null;
    // A form replaced by another one (View -> Edit) closes the dialog first; that `close` event must not tear the new form down.
    let replacedCloses = 0;
    let userCancelled = false;
    const Xhr = deps.XMLHttpRequest ?? win.XMLHttpRequest;
    const abortInFlight = () => { controller?.abort(); activeXhr?.abort?.(); };

    const pageError = text => {
        const node = part('page-error');
        if (!node) return;
        if (text === '') hideUiToast(node, win); else showUiToast(node, text, win);
    };
    const feedback = text => {
        const node = form?.querySelector('[data-ui-dialog-form-feedback]');
        if (node) node.textContent = text;
    };
    const isDirty = () => form !== null && snapshotOf(form) !== snapshot;
    const teardown = () => {
        controls.forEach(control => control?.destroy?.());
        controls = [];
        form = null;
        snapshot = '';
        submitting = false;
        dialog.replaceChildren();
    };
    const restoreFocus = () => {
        let target = opener;
        if (!target?.isConnected && openerUrl) {
            target = [...scope.querySelectorAll('[data-ui-dialog-form-open]')].find(node => node.getAttribute('data-url') === openerUrl);
        }
        target?.focus?.();
        opener = null;
    };
    const closeNow = () => {
        if (dialog.open) dialog.close();
    };
    // Unsaved changes: ask through the shared LF confirmation dialog (danger tone, Cancel focused). Cancel keeps editing.
    const askDiscard = () => confirmAction({
        title: message('discard-title'),
        message: message('discard-text'),
        confirmLabel: message('discard'),
        tone: 'danger',
    });
    async function requestClose() {
        if (submitting || !form || confirming) return;
        if (!isDirty()) { closeNow(); return; }
        const current = form;
        confirming = true;
        try {
            const discard = await askDiscard();
            if (discard && form === current && !submitting) closeNow();
        } finally {
            confirming = false;
        }
    }
    // Upload progress (only when a file is chosen): a bar inside the form, a cancel button, and a leave-page warning.
    const progressNode = () => form?.querySelector('[data-ui-dialog-form-progress]') ?? null;
    const progressText = (node, name, percent) => String(node?.getAttribute(`data-text-${name}`) ?? '').replace(':percent', String(percent));
    const showProgress = fraction => {
        const node = progressNode();
        if (!node) return;
        const percent = Math.min(100, Math.floor(fraction * 100));
        node.hidden = false;
        const bar = node.querySelector('[data-ui-dialog-form-progress-bar]');
        if (bar) { bar.style.width = `${percent}%`; bar.setAttribute('aria-valuenow', String(percent)); }
        const text = node.querySelector('[data-ui-dialog-form-progress-text]');
        if (text) text.textContent = progressText(node, percent >= 100 ? 'processing' : 'uploading', percent);
    };
    const hideProgress = () => {
        const node = progressNode();
        if (node) node.hidden = true;
    };
    const hasUpload = candidate => [...(candidate.querySelectorAll?.('input[type="file"]') ?? [])].some(input => (input.files?.length ?? 0) > 0);
    function sendWithProgress(current) {
        return new Promise((resolve, reject) => {
            const xhr = new Xhr();
            const guard = event => { event.preventDefault?.(); event.returnValue = ''; };
            const done = () => { activeXhr = null; win.removeEventListener?.('beforeunload', guard); };
            activeXhr = xhr;
            userCancelled = false;
            win.addEventListener?.('beforeunload', guard);
            xhr.open('POST', current.getAttribute('action'));
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.withCredentials = true;
            showProgress(0);
            xhr.upload.onprogress = event => { if (event.lengthComputable && event.total > 0) showProgress(event.loaded / event.total); };
            xhr.onload = () => {
                done();
                resolve({
                    ok: xhr.status >= 200 && xhr.status < 300,
                    status: xhr.status,
                    json: async () => JSON.parse(xhr.responseText),
                });
            };
            xhr.onerror = () => { done(); reject(new Error('network')); };
            xhr.onabort = () => { done(); reject(Object.assign(new Error('aborted'), { name: 'AbortError' })); };
            xhr.send(new win.FormData(current));
        });
    }
    // The cancel button of the progress bar: stop the upload and let the user edit and try again.
    function cancelUpload() {
        if (!activeXhr) return;
        userCancelled = true;
        activeXhr.abort();
    }
    const setSubmitting = on => {
        submitting = on;
        form?.setAttribute('aria-busy', String(on));
        form?.querySelectorAll('[data-ui-dialog-form-submit], [data-ui-dialog-form-cancel]').forEach(node => { node.disabled = on; });
        if (!on) hideProgress();
    };

    const unmark = node => {
        node.setAttribute('aria-invalid', 'false');
        const kept = (node.getAttribute('aria-describedby') || '').split(' ').filter(id => id && !id.endsWith('-error'));
        if (kept.length) node.setAttribute('aria-describedby', kept.join(' ')); else node.removeAttribute('aria-describedby');
    };
    const clearErrors = () => {
        feedback('');
        form?.querySelectorAll('.ui-field__error[data-ui-dialog-form-error]').forEach(node => node.remove());
        form?.querySelectorAll('[aria-invalid="true"]').forEach(unmark);
    };
    // One field's message goes away as soon as the user supplies a value (typing, or choosing in a select).
    const clearFieldError = control => {
        if (!form || control?.getAttribute?.('aria-invalid') !== 'true') return;
        const select = control.id?.endsWith('-ui-kit') ? form.querySelector(`#${escapeId(control.id.slice(0, -7))}`) : null;
        const owner = select ?? control;
        if (String(owner.value ?? '').trim() === '') return;
        const error = owner.id ? form.querySelector(`#${escapeId(`${owner.id}-error`)}`) : null;
        error?.remove();
        for (const node of new Set([owner, control, owner.id ? form.querySelector(`#${escapeId(`${owner.id}-ui-kit`)}`) : null])) {
            if (node) unmark(node);
        }
    };
    // Empty required fields are reported inline, in the page language, instead of the browser's own bubble.
    const requiredErrors = () => {
        const errors = {};
        for (const control of form.querySelectorAll('[required]')) {
            if (String(control.value ?? '').trim() !== '' || !control.name) continue;
            // The select adapter re-points the label at its combobox input (`<id>-ui-kit`).
            const label = form.querySelector(`label[for="${control.id}"]`) ?? form.querySelector(`label[for="${control.id}-ui-kit"]`);
            const name = String(label?.textContent ?? control.name).replace('*', '').trim().toLocaleLowerCase();
            const template = control.tagName === 'SELECT' ? message('required-select') : message('required-input');
            errors[control.name] = [template.replace(':field', name)];
        }
        return errors;
    };
    const showErrors = errors => {
        let first = null;
        for (const [name, messages] of Object.entries(errors ?? {})) {
            let control = form.querySelector(`[name="${win.CSS?.escape ? win.CSS.escape(name) : name}"]:not([type="hidden"])`);
            let field = control?.closest('.ui-field, .ui-media-slot');
            if (!control) {
                // A field whose value lives in a hidden input (e.g. the embedded-video tile) names its holder.
                field = form.querySelector(`[data-ui-error-for="${name}"]`);
                control = field?.querySelector('button, input:not([type="hidden"])') ?? null;
            }
            if (!control || !field) { feedback(String(messages?.[0] ?? '')); continue; }
            const error = win.document.createElement('span');
            error.className = 'ui-field__error';
            error.id = `${control.id}-error`;
            error.setAttribute('role', 'alert');
            error.setAttribute('data-ui-dialog-form-error', '');
            error.textContent = String(messages?.[0] ?? '');
            field.append(error);
            const visible = (control.id && form.querySelector(`#${win.CSS?.escape ? win.CSS.escape(control.id) : control.id}-ui-kit`)) || control;
            for (const node of new Set([control, visible])) {
                node.setAttribute('aria-invalid', 'true');
                node.setAttribute('aria-describedby', [...(node.getAttribute('aria-describedby') || '').split(' ').filter(Boolean), error.id].join(' '));
            }
            first ??= visible;
        }
        first?.focus?.();
    };

    // Put the cursor in the field a link points at (`#field`), trying the dialog's id prefix and the combobox of a select.
    const focusField = id => {
        const prefix = dialog.getAttribute('data-field-id-prefix') || '';
        // `data-ui-focus-alias` lets a field that replaced another (or has no id of its own) answer to a link's name.
        for (const selector of [`#${escapeId(prefix + id)}-ui-kit`, `#${escapeId(prefix + id)}`, `#${escapeId(id)}`, `[data-ui-focus-alias="${id}"]`]) {
            const field = form.querySelector(selector);
            if (field) {
                field.focus?.();
                field.scrollIntoView?.({ block: 'center' });
                return true;
            }
        }
        return false;
    };

    async function open(trigger, options = {}) {
        const url = trigger.getAttribute('data-url');
        if (!url || submitting) return;
        const mine = ++seq;
        abortInFlight();
        controller = new win.AbortController();
        pageError('');
        trigger.setAttribute('aria-busy', 'true');
        try {
            const response = await win.fetch(url, {
                headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                signal: controller.signal,
            });
            if (mine !== seq) return;
            if (response.redirected) { win.location.assign(response.url); return; }
            if (!response.ok) { pageError(message('load-error')); return; }
            const html = await response.text();
            if (mine !== seq) return;
            const fragment = parse(html).querySelector('form.ui-modal__form');
            if (!fragment) { pageError(message('load-error')); return; }
            if (dialog.open) { replacedCloses += 1; closeNow(); }
            teardown();
            const node = win.document.importNode(fragment, true);
            dialog.replaceChildren(node);
            form = node;
            controls = [
                ...[...node.querySelectorAll('select[data-ui-kit-select]')].map(select => initSelect(select)),
                ...initSlots(node),
            ].filter(Boolean);
            await defer();
            if (mine !== seq || form !== node) return;
            snapshot = snapshotOf(node);
            opener = trigger;
            openerUrl = url;
            dialog.showModal();
            if (!(options.focus && focusField(options.focus))) {
                node.querySelector('select, input:not([type="hidden"]):not([type="file"]), textarea')?.focus?.();
            }
        } catch (error) {
            if (mine === seq && error?.name !== 'AbortError') pageError(message('load-error'));
        } finally {
            trigger.removeAttribute('aria-busy');
        }
    }

    async function submit(event) {
        if (!form || event.target !== form) return;
        event.preventDefault();
        if (submitting) return;
        const mine = ++seq;
        const current = form;
        abortInFlight();
        controller = new win.AbortController();
        clearErrors();
        const missing = requiredErrors();
        if (Object.keys(missing).length > 0) { showErrors(missing); return; }
        setSubmitting(true);
        try {
            const response = Xhr && hasUpload(current)
                ? await sendWithProgress(current)
                : await win.fetch(current.getAttribute('action'), {
                    method: 'POST',
                    body: new win.FormData(current),
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    signal: controller.signal,
                });
            if (mine !== seq || form !== current) return;
            let payload = null;
            try { payload = await response.json(); } catch (error) { payload = null; }
            if (mine !== seq || form !== current) return;
            if (response.ok && payload?.redirect) {
                // e.g. a new record continues on its own page; the server flashed what that page should show.
                followRedirect(win, payload.redirect);
                return;
            }
            if (response.ok) {
                const text = String(payload?.message ?? '');
                const flash = part('flash');
                if (flash) { if (text === '') hideUiToast(flash, win); else showUiToast(flash, text, win); }
                setSubmitting(false);
                closeNow();
                dialog.dispatchEvent(createEvent(UI_DIALOG_FORM_SAVED_EVENT, { message: text }));
                return;
            }
            setSubmitting(false);
            if (response.status === 422 && payload?.errors) showErrors(payload.errors);
            else feedback(response.status === 419 || response.status === 401 ? message('session-expired') : message('save-error'));
        } catch (error) {
            if (mine !== seq || form !== current) return;
            const cancelledByUser = userCancelled;
            userCancelled = false;
            hideProgress();
            setSubmitting(false);
            if (cancelledByUser) feedback(progressText(progressNode(), 'cancelled', 0));
            else if (error?.name !== 'AbortError') feedback(message('save-error'));
        }
    }

    const onClick = event => {
        const target = event.target?.closest?.('[data-ui-dialog-form-open], [data-ui-dialog-form-cancel], [data-ui-dialog-form-upload-cancel]');
        if (!target || !scope.contains(target)) return;
        if (target.hasAttribute('data-ui-dialog-form-upload-cancel')) cancelUpload();
        else if (target.hasAttribute('data-ui-dialog-form-open')) open(target, { focus: target.getAttribute('data-ui-dialog-form-focus') });
        else requestClose();
    };
    // A primary action in the page heading lives outside the scope: it opens this scope's dialog when
    // its data-ui-dialog-form-for names the scope id.
    const onOutsideClick = event => {
        if (!scope.id) return;
        const target = event.target?.closest?.(`[data-ui-dialog-form-open][data-ui-dialog-form-for="${scope.id}"]`);
        if (!target || scope.contains(target)) return;
        open(target, { focus: target.getAttribute('data-ui-dialog-form-focus') });
    };
    // Escape: ask first when something is unsaved (the nested confirm handles its own Escape natively).
    const onCancel = event => {
        if (event.target !== dialog) return;
        event.preventDefault();
        requestClose();
    };
    const onClose = event => {
        if (event.target !== dialog) return;
        if (replacedCloses > 0) { replacedCloses -= 1; return; }
        seq++;
        abortInFlight();
        teardown();
        restoreFocus();
    };

    // A link such as `?tab=information#difficulty_level` opens the form on that field.
    const autoOpen = () => {
        const field = String(win.location?.hash || '').slice(1);
        const trigger = field ? scope.querySelector('[data-ui-dialog-form-hash-open]') : null;
        if (trigger && (!('offsetParent' in trigger) || trigger.offsetParent !== null)) open(trigger, { focus: field });
    };
    scope.addEventListener('click', onClick);
    win.document?.addEventListener?.('click', onOutsideClick);
    const onFieldEdit = event => clearFieldError(event.target);
    scope.addEventListener('submit', submit);
    win.addEventListener?.('hashchange', autoOpen);
    dialog.addEventListener('input', onFieldEdit);
    dialog.addEventListener('change', onFieldEdit);
    dialog.addEventListener('cancel', onCancel);
    dialog.addEventListener('close', onClose);
    const instance = { destroy() {
        seq++;
        abortInFlight();
        win.removeEventListener?.('hashchange', autoOpen);
        scope.removeEventListener('click', onClick);
        win.document?.removeEventListener?.('click', onOutsideClick);
        scope.removeEventListener('submit', submit);
        dialog.removeEventListener('input', onFieldEdit);
        dialog.removeEventListener('change', onFieldEdit);
        dialog.removeEventListener('cancel', onCancel);
        dialog.removeEventListener('close', onClose);
        if (dialog.open) dialog.close();
        teardown();
        releaseClaim(scope, 'DialogForm');
        instances.delete(scope);
    } };
    instances.set(scope, instance);
    autoOpen();
    return instance;
}

if (typeof document !== 'undefined') {
    const initialize = () => document.querySelectorAll('.lf-admin-ui[data-ui-dialog-form-scope]').forEach(scope => initUiKitDialogForm(scope));
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
    else initialize();
}
