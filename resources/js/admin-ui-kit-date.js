/**
 * UI Kit date input (`ux-date` + `ux-calendar`). Turns `<input type="date" data-ui-kit-date>` into the kit design: a
 * read-only dd/mm/yyyy field with a calendar button and a popover calendar (month and year selects, today marked,
 * "Clear" / "Today" in the footer). The browser's own date picker is not used. The value the server receives stays
 * ISO (yyyy-mm-dd): the field's `name` moves to a hidden input that holds it, the visible text has no name.
 * Texts come from data attributes on the input (the `x-ui-date-input` component fills them from the language files).
 *
 * `<input type="datetime-local" data-ui-kit-date>` is the same field with a time: dd/mm/yyyy hh:mm, hour and minute
 * selects under the days, "Clear" / "Now" / "Done" in the footer; picking a day keeps the calendar open. The server still
 * gets yyyy-mm-ddThh:mm. Here the original input stays the value holder (it keeps its id, name and any page bindings such
 * as Alpine `x-model` / `x-ref`, and is hidden); the visible text is a companion `<id>-ui-kit` the label points at (the
 * convention of the kit select, so server errors for the name mark the companion). A read-only field cannot show the
 * browser's validity bubble, so a custom validity set on the holder is reported inline under the field instead.
 */
const isoPattern = /^(\d{4})-(\d{2})-(\d{2})$/;
const pad = value => String(value).padStart(2, '0');

export const toIso = date => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
export const isoToDisplay = iso => (isoPattern.test(iso ?? '') ? iso.split('-').reverse().join('/') : '');
export const parseIso = iso => {
    const match = isoPattern.exec(iso ?? '');
    if (!match) return null;
    const [year, month, day] = [Number(match[1]), Number(match[2]), Number(match[3])];
    const date = new Date(year, month - 1, day);
    return date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day ? { year, month: month - 1, day } : null;
};

/** The weeks start on Monday: `offset` empty cells, then `days` days. */
export function monthCells(year, month) {
    return { offset: (new Date(year, month, 1).getDay() + 6) % 7, days: new Date(year, month + 1, 0).getDate() };
}

/** A day is selectable unless it lies outside the input's min / max (both ISO or empty; a time part is ignored). */
export const isDayAllowed = (iso, min, max) => !((min && iso < min.slice(0, 10)) || (max && iso > max.slice(0, 10)));

const dateTimePattern = /^(\d{4}-\d{2}-\d{2})T(\d{2}):(\d{2})(?::\d{2}(?:\.\d+)?)?$/;

/** `yyyy-mm-ddThh:mm` (seconds tolerated) into its parts, or null for anything that is not a real date and time. */
export const parseIsoDateTime = value => {
    const match = dateTimePattern.exec(value ?? '');
    if (!match || !parseIso(match[1])) return null;
    const [hour, minute] = [Number(match[2]), Number(match[3])];
    return hour <= 23 && minute <= 59 ? { date: match[1], hour, minute } : null;
};
export const joinDateTime = (date, hour, minute) => `${date}T${pad(hour)}:${pad(minute)}`;
export const isoDateTimeToDisplay = value => {
    const parts = parseIsoDateTime(value);
    return parts ? `${isoToDisplay(parts.date)} ${pad(parts.hour)}:${pad(parts.minute)}` : '';
};

const text = (input, name, fallback) => input.getAttribute(`data-${name}`) || fallback;

const calendarIcon = doc => {
    const svg = doc.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class', 'ui-icon');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '2');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    svg.setAttribute('aria-hidden', 'true');
    for (const [tag, attrs] of [
        ['path', { d: 'M8 2v4' }], ['path', { d: 'M16 2v4' }],
        ['rect', { width: '18', height: '18', x: '3', y: '4', rx: '2' }], ['path', { d: 'M3 10h18' }],
    ]) {
        const node = doc.createElementNS('http://www.w3.org/2000/svg', tag);
        for (const [key, value] of Object.entries(attrs)) node.setAttribute(key, value);
        svg.append(node);
    }
    return svg;
};

let sequence = 0;

export function initUiKitDateInput(input, win = globalThis.window) {
    if (!input || input.dataset.uiDateReady === '1') return null;
    const doc = win.document;
    input.dataset.uiDateReady = '1';

    const withTime = input.type === 'datetime-local';
    if (withTime) return initDateTime(input, win);

    const today = new Date();
    const min = input.getAttribute('min') || '';
    const max = input.getAttribute('max') || '';
    const iso = parseIso(input.value) ? input.value : '';
    const monthWord = text(input, 'month-label', 'Month {n}');
    const weekdays = (() => {
        try { const list = JSON.parse(input.getAttribute('data-weekdays') ?? '[]'); return list.length === 7 ? list : null; } catch (error) { return null; }
    })() ?? ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    // The hidden input carries the name and the ISO value the server reads.
    const hidden = doc.createElement('input');
    hidden.type = 'hidden';
    hidden.name = input.name;
    hidden.value = iso;
    input.removeAttribute('name');
    input.type = 'text';
    input.readOnly = true;
    input.autocomplete = 'off';
    input.placeholder = text(input, 'placeholder', 'dd/mm/yyyy');
    input.value = isoToDisplay(iso);
    input.dataset.iso = iso;

    const wrap = doc.createElement('div');
    wrap.className = 'ux-date';
    input.before(wrap);
    wrap.append(input, hidden);

    const trigger = doc.createElement('button');
    trigger.type = 'button';
    trigger.className = 'ux-date__button cursor-interaction';
    trigger.setAttribute('aria-label', text(input, 'open-label', 'Open calendar'));
    trigger.setAttribute('aria-haspopup', 'dialog');
    trigger.setAttribute('aria-expanded', 'false');
    trigger.append(calendarIcon(doc));
    wrap.append(trigger);

    const calendar = doc.createElement('div');
    calendar.className = 'ux-calendar';
    calendar.id = `ux-calendar-${++sequence}`;
    calendar.setAttribute('popover', 'auto');
    calendar.setAttribute('role', 'dialog');
    calendar.setAttribute('aria-label', text(input, 'choose-label', 'Choose a date'));
    wrap.append(calendar);
    input.setAttribute('aria-controls', calendar.id);
    trigger.setAttribute('aria-controls', calendar.id);

    const startFrom = parseIso(iso) ?? { year: today.getFullYear(), month: today.getMonth() };
    let year = startFrom.year;
    let month = startFrom.month;

    const isOpen = () => calendar.matches(':popover-open');
    const position = () => {
        const field = input.getBoundingClientRect();
        const box = calendar.getBoundingClientRect();
        calendar.style.left = `${Math.max(10, Math.min(field.left, win.innerWidth - box.width - 10))}px`;
        calendar.style.top = `${Math.max(10, Math.min(field.bottom + 6, win.innerHeight - box.height - 10))}px`;
    };

    const choose = key => {
        hidden.value = key;
        input.dataset.iso = key;
        input.value = isoToDisplay(key);
        input.dispatchEvent(new win.Event('change', { bubbles: true }));
        calendar.hidePopover();
        input.focus();
    };

    const button = (label, onClick) => {
        const node = doc.createElement('button');
        node.type = 'button';
        node.textContent = label;
        node.addEventListener('click', onClick);
        return node;
    };

    function draw() {
        calendar.replaceChildren();
        const header = doc.createElement('div');
        header.className = 'ux-calendar__header';
        const prev = button('‹', () => { month--; if (month < 0) { month = 11; year--; } draw(); });
        prev.setAttribute('aria-label', text(input, 'prev-label', 'Previous month'));
        const next = button('›', () => { month++; if (month > 11) { month = 0; year++; } draw(); });
        next.setAttribute('aria-label', text(input, 'next-label', 'Next month'));

        const months = doc.createElement('select');
        months.setAttribute('aria-label', text(input, 'month-select-label', 'Month'));
        for (let i = 0; i < 12; i++) months.add(new win.Option(monthWord.replace('{n}', String(i + 1)), String(i), false, i === month));
        months.addEventListener('change', () => { month = Number(months.value); draw(); });

        const years = doc.createElement('select');
        years.setAttribute('aria-label', text(input, 'year-select-label', 'Year'));
        const firstYear = min ? Number(min.slice(0, 4)) : today.getFullYear() - 100;
        const lastYear = max ? Number(max.slice(0, 4)) : today.getFullYear() + 30;
        for (let y = Math.min(firstYear, year); y <= Math.max(lastYear, year); y++) years.add(new win.Option(String(y), String(y), false, y === year));
        years.addEventListener('change', () => { year = Number(years.value); draw(); });
        header.append(prev, months, years, next);

        const grid = doc.createElement('div');
        grid.className = 'ux-calendar__grid';
        for (const day of weekdays) {
            const span = doc.createElement('span');
            span.className = 'ux-calendar__weekday';
            span.textContent = day;
            grid.append(span);
        }
        const { offset, days } = monthCells(year, month);
        for (let i = 0; i < offset; i++) grid.append(doc.createElement('span'));
        const todayKey = toIso(today);
        for (let d = 1; d <= days; d++) {
            const key = toIso(new Date(year, month, d));
            const day = button(String(d), () => choose(key));
            day.className = 'ux-calendar__day cursor-interaction';
            day.setAttribute('aria-label', isoToDisplay(key));
            day.setAttribute('aria-pressed', String(hidden.value === key));
            day.dataset.today = String(key === todayKey);
            if (!isDayAllowed(key, min, max)) day.disabled = true;
            grid.append(day);
        }

        const footer = doc.createElement('div');
        footer.className = 'ux-calendar__footer';
        footer.append(button(text(input, 'clear-label', 'Clear'), () => choose('')));
        const todayButton = button(text(input, 'today-label', 'Today'), () => choose(todayKey));
        if (!isDayAllowed(todayKey, min, max)) todayButton.disabled = true;
        footer.append(todayButton);
        calendar.append(header, grid, footer);
        if (isOpen()) win.requestAnimationFrame(position);
    }

    function open() {
        const parsed = parseIso(hidden.value);
        if (parsed) { year = parsed.year; month = parsed.month; }
        draw();
        calendar.showPopover();
        position();
        calendar.querySelector('[aria-pressed=true]')?.focus();
    }

    input.addEventListener('click', open);
    input.addEventListener('keydown', event => {
        if (['Enter', 'ArrowDown', ' '].includes(event.key)) { event.preventDefault(); open(); }
    });
    trigger.addEventListener('click', open);
    calendar.addEventListener('keydown', event => {
        if (event.key === 'Escape') { event.stopPropagation(); calendar.hidePopover(); input.focus(); }
    });
    calendar.addEventListener('toggle', () => trigger.setAttribute('aria-expanded', String(isOpen())));
    win.addEventListener('resize', () => { if (isOpen()) position(); });
    return { open, hidden, calendar, input };
}

function initDateTime(holder, win) {
    const doc = win.document;
    const today = new Date();
    const monthWord = text(holder, 'month-label', 'Month {n}');
    const weekdays = (() => {
        try { const list = JSON.parse(holder.getAttribute('data-weekdays') ?? '[]'); return list.length === 7 ? list : null; } catch (error) { return null; }
    })() ?? ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    // The holder keeps id, name and page bindings; reading and writing its value goes through the native accessor, and
    // every write (the page's own included, e.g. Alpine x-model) refreshes the visible text.
    const native = Object.getOwnPropertyDescriptor(win.HTMLInputElement.prototype, 'value');
    const initial = holder.value;
    holder.type = 'text';
    holder.hidden = true;
    holder.tabIndex = -1;
    native.set.call(holder, parseIsoDateTime(initial) ? initial : '');
    const read = () => native.get.call(holder);

    const display = doc.createElement('input');
    display.type = 'text';
    display.id = holder.id ? `${holder.id}-ui-kit` : '';
    display.readOnly = true;
    display.autocomplete = 'off';
    display.placeholder = text(holder, 'placeholder', 'dd/mm/yyyy hh:mm');
    const refresh = () => { display.value = isoDateTimeToDisplay(read()); };
    Object.defineProperty(holder, 'value', {
        configurable: true,
        get: read,
        set(next) { native.set.call(holder, next ?? ''); refresh(); },
    });
    refresh();
    // Same markup as the dialog form's inline errors (`<id>-error`, removed with them on the next submit).
    let validity = '';
    const errorId = `${holder.id || holder.name}-error`;
    const mark = invalid => {
        for (const node of [holder, display]) {
            const others = (node.getAttribute('aria-describedby') || '').split(' ').filter(id => id && id !== errorId);
            node.setAttribute('aria-invalid', String(invalid));
            const ids = invalid ? [...others, errorId] : others;
            if (ids.length) node.setAttribute('aria-describedby', ids.join(' ')); else node.removeAttribute('aria-describedby');
        }
    };
    holder.setCustomValidity = message => {
        validity = String(message ?? '');
        if (validity === '' && doc.getElementById(errorId)) { doc.getElementById(errorId).remove(); mark(false); }
    };
    holder.reportValidity = () => {
        doc.getElementById(errorId)?.remove();
        if (validity === '') { mark(false); return true; }
        const error = doc.createElement('span');
        error.className = 'ui-field__error';
        error.id = errorId;
        error.setAttribute('role', 'alert');
        error.setAttribute('data-ui-dialog-form-error', '');
        error.textContent = validity;
        (holder.closest('.ui-field') ?? wrap).append(error);
        mark(true);
        display.focus();
        return false;
    };
    Object.defineProperty(holder, 'validationMessage', { configurable: true, get: () => validity });
    if (holder.id) holder.closest('form, .ui-modal, body')?.querySelector(`label[for="${holder.id}"]`)?.setAttribute('for', display.id);

    const wrap = doc.createElement('div');
    wrap.className = 'ux-date';
    holder.before(wrap);
    wrap.append(display, holder);

    const trigger = doc.createElement('button');
    trigger.type = 'button';
    trigger.className = 'ux-date__button cursor-interaction';
    trigger.setAttribute('aria-label', text(holder, 'open-label', 'Open calendar'));
    trigger.setAttribute('aria-haspopup', 'dialog');
    trigger.setAttribute('aria-expanded', 'false');
    trigger.append(calendarIcon(doc));
    wrap.append(trigger);

    const calendar = doc.createElement('div');
    calendar.className = 'ux-calendar';
    calendar.id = `ux-calendar-${++sequence}`;
    calendar.setAttribute('popover', 'auto');
    calendar.setAttribute('role', 'dialog');
    calendar.setAttribute('aria-label', text(holder, 'choose-label', 'Choose a date and time'));
    wrap.append(calendar);
    display.setAttribute('aria-controls', calendar.id);
    trigger.setAttribute('aria-controls', calendar.id);

    let year = today.getFullYear();
    let month = today.getMonth();
    let hour = 0;
    let minute = 0;

    const isOpen = () => calendar.matches(':popover-open');
    const position = () => {
        const field = display.getBoundingClientRect();
        const box = calendar.getBoundingClientRect();
        calendar.style.left = `${Math.max(10, Math.min(field.left, win.innerWidth - box.width - 10))}px`;
        calendar.style.top = `${Math.max(10, Math.min(field.bottom + 6, win.innerHeight - box.height - 10))}px`;
    };

    const commit = next => {
        holder.value = next;
        for (const type of ['input', 'change']) holder.dispatchEvent(new win.Event(type, { bubbles: true }));
    };
    const close = () => { calendar.hidePopover(); display.focus(); };

    const button = (label, onClick) => {
        const node = doc.createElement('button');
        node.type = 'button';
        node.textContent = label;
        node.addEventListener('click', onClick);
        return node;
    };
    const numberSelect = (label, count, current, onChange) => {
        const select = doc.createElement('select');
        select.setAttribute('aria-label', label);
        for (let i = 0; i < count; i++) select.add(new win.Option(pad(i), String(i), false, i === current));
        select.addEventListener('change', () => onChange(Number(select.value)));
        return select;
    };

    function draw() {
        const min = holder.getAttribute('min') || '';
        const max = holder.getAttribute('max') || '';
        const chosen = parseIsoDateTime(read())?.date ?? '';
        calendar.replaceChildren();
        const header = doc.createElement('div');
        header.className = 'ux-calendar__header';
        const prev = button('‹', () => { month--; if (month < 0) { month = 11; year--; } draw(); });
        prev.setAttribute('aria-label', text(holder, 'prev-label', 'Previous month'));
        const next = button('›', () => { month++; if (month > 11) { month = 0; year++; } draw(); });
        next.setAttribute('aria-label', text(holder, 'next-label', 'Next month'));

        const months = doc.createElement('select');
        months.setAttribute('aria-label', text(holder, 'month-select-label', 'Month'));
        for (let i = 0; i < 12; i++) months.add(new win.Option(monthWord.replace('{n}', String(i + 1)), String(i), false, i === month));
        months.addEventListener('change', () => { month = Number(months.value); draw(); });

        const years = doc.createElement('select');
        years.setAttribute('aria-label', text(holder, 'year-select-label', 'Year'));
        const firstYear = min ? Number(min.slice(0, 4)) : today.getFullYear() - 100;
        const lastYear = max ? Number(max.slice(0, 4)) : today.getFullYear() + 30;
        for (let y = Math.min(firstYear, year); y <= Math.max(lastYear, year); y++) years.add(new win.Option(String(y), String(y), false, y === year));
        years.addEventListener('change', () => { year = Number(years.value); draw(); });
        header.append(prev, months, years, next);

        const grid = doc.createElement('div');
        grid.className = 'ux-calendar__grid';
        for (const day of weekdays) {
            const span = doc.createElement('span');
            span.className = 'ux-calendar__weekday';
            span.textContent = day;
            grid.append(span);
        }
        const { offset, days } = monthCells(year, month);
        for (let i = 0; i < offset; i++) grid.append(doc.createElement('span'));
        const todayKey = toIso(today);
        for (let d = 1; d <= days; d++) {
            const key = toIso(new Date(year, month, d));
            // Picking a day keeps the time already chosen and leaves the calendar open for the time.
            const day = button(String(d), () => { commit(joinDateTime(key, hour, minute)); draw(); calendar.querySelector('[aria-pressed=true]')?.focus(); });
            day.className = 'ux-calendar__day cursor-interaction';
            day.setAttribute('aria-label', isoToDisplay(key));
            day.setAttribute('aria-pressed', String(chosen === key));
            day.dataset.today = String(key === todayKey);
            if (!isDayAllowed(key, min, max)) day.disabled = true;
            grid.append(day);
        }

        // Hour and minute: changing them updates the value once a day is chosen (before that they are kept for it).
        const time = doc.createElement('div');
        time.className = 'ux-calendar__time';
        const timeLabel = doc.createElement('span');
        timeLabel.textContent = text(holder, 'time-label', 'Time');
        const setTime = () => { const date = parseIsoDateTime(read())?.date; if (date) commit(joinDateTime(date, hour, minute)); };
        const separator = doc.createElement('span');
        separator.textContent = ':';
        separator.setAttribute('aria-hidden', 'true');
        time.append(
            timeLabel,
            numberSelect(text(holder, 'hour-label', 'Hour'), 24, hour, value => { hour = value; setTime(); }),
            separator,
            numberSelect(text(holder, 'minute-label', 'Minute'), 60, minute, value => { minute = value; setTime(); }),
        );

        const footer = doc.createElement('div');
        footer.className = 'ux-calendar__footer';
        footer.append(button(text(holder, 'clear-label', 'Clear'), () => { commit(''); close(); }));
        const nowButton = button(text(holder, 'now-label', 'Now'), () => {
            const now = new Date();
            hour = now.getHours(); minute = now.getMinutes(); year = now.getFullYear(); month = now.getMonth();
            commit(joinDateTime(toIso(now), hour, minute));
            draw();
        });
        if (!isDayAllowed(todayKey, min, max)) nowButton.disabled = true;
        footer.append(nowButton, button(text(holder, 'done-label', 'Done'), close));
        calendar.append(header, grid, time, footer);
        if (isOpen()) win.requestAnimationFrame(position);
    }

    function open() {
        const parsed = parseIsoDateTime(read());
        if (parsed) {
            const day = parseIso(parsed.date);
            year = day.year; month = day.month; hour = parsed.hour; minute = parsed.minute;
        }
        draw();
        calendar.showPopover();
        position();
        (calendar.querySelector('[aria-pressed=true]') ?? calendar.querySelector('.ux-calendar__day:not(:disabled)'))?.focus();
    }

    display.addEventListener('click', open);
    display.addEventListener('keydown', event => {
        if (['Enter', 'ArrowDown', ' '].includes(event.key)) { event.preventDefault(); open(); }
    });
    trigger.addEventListener('click', open);
    calendar.addEventListener('keydown', event => {
        if (event.key === 'Escape') { event.stopPropagation(); close(); }
    });
    calendar.addEventListener('toggle', () => trigger.setAttribute('aria-expanded', String(isOpen())));
    win.addEventListener('resize', () => { if (isOpen()) position(); });
    return { open, hidden: holder, calendar, input: display };
}

export function initUiKitDateInputs(scope = globalThis.document) {
    return [...scope.querySelectorAll('input[data-ui-kit-date]:is([type=date], [type=datetime-local])')].map(input => initUiKitDateInput(input, scope.ownerDocument?.defaultView ?? globalThis.window)).filter(Boolean);
}
