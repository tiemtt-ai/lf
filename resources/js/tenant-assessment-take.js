// Taking a Kiểm tra attempt (tenant website). The server is the authority: answers are saved per question as the
// student works, the timer counts down to the server deadline, and the form itself carries every answer on submit
// so nothing typed is lost if a background save failed.

const root = document.querySelector('[data-exam-attempt]');
const form = document.getElementById('exam-form');
const labels = root.dataset;
const csrf = document.querySelector('meta[name="csrf-token"]')?.content
    || form.querySelector('input[name="_token"]')?.value || '';
const saveStatus = root.querySelector('[data-exam-save]');
const answeredCount = root.querySelector('[data-exam-answered]');
let submitting = false;
let failedSaves = 0;

const setStatus = (text, isError = false) => {
    saveStatus.textContent = text;
    saveStatus.classList.toggle('is-error', isError);
};

const leaveFor = (url) => {
    submitting = true;
    window.location.assign(url);
};

const markAnswered = (question, answered) => {
    question.dataset.answered = answered ? '1' : '0';
    root.querySelector(`[data-nav="${question.dataset.question}"]`)?.classList.toggle('is-answered', answered);
    answeredCount.textContent = String(root.querySelectorAll('[data-question][data-answered="1"]').length);
};

const payload = (question) => {
    const type = question.dataset.type;
    if (['single_choice', 'true_false', 'multiple_choice'].includes(type)) {
        const keys = [...question.querySelectorAll('input:checked')].map((input) => Number(input.value));
        return { body: { question: Number(question.dataset.question), option_keys: keys }, answered: keys.length > 0 };
    }
    const text = question.querySelector('input[type="text"], textarea')?.value ?? '';
    // An essay may be answered with files only (ADR-0027 D5).
    const files = Number(question.dataset.fileCount || 0);
    return { body: { question: Number(question.dataset.question), text }, answered: text.trim() !== '' || files > 0 };
};

// One save in flight per question; a newer change waits and is sent after it.
const queues = new Map();

const send = async (question) => {
    const state = queues.get(question) || { running: false, again: false };
    queues.set(question, state);
    if (state.running) {
        state.again = true;
        return;
    }
    state.running = true;
    const { body, answered } = payload(question);
    setStatus(labels.labelSaving);

    try {
        const response = await fetch(labels.saveUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(body),
        });
        const data = await response.json().catch(() => ({}));
        if (response.status === 409 && data.redirect) {
            leaveFor(data.redirect);
            return;
        }
        if (! response.ok) throw new Error(String(response.status));
        failedSaves = Math.max(0, failedSaves - 1);
        markAnswered(question, answered);
        setStatus(labels.labelSaved.replace(':time', data.saved_at || ''));
    } catch {
        failedSaves += 1;
        setStatus(labels.labelSaveFailed, true);
    } finally {
        state.running = false;
        if (state.again) {
            state.again = false;
            send(question);
        }
    }
};

const timers = new Map();
const later = (question, delay = 900) => {
    window.clearTimeout(timers.get(question));
    timers.set(question, window.setTimeout(() => send(question), delay));
};

form.addEventListener('change', (event) => {
    const question = event.target.closest('[data-question]');
    if (! question || event.target.matches('[data-record-file], [data-files-input]')) return;
    if (event.target.matches('input[type="radio"], input[type="checkbox"]')) {
        send(question);
    } else {
        later(question, 0);
    }
});

form.addEventListener('input', (event) => {
    const question = event.target.closest('[data-question]');
    if (question && event.target.matches('input[type="text"], textarea')) {
        later(question);
    }
});

// ---- Speaking: record in the browser (or pick a file), uploaded as soon as it is ready. ----

const uploadRecording = async (question, blob, filename) => {
    const recorder = question.querySelector('[data-recorder]');
    const status = recorder.querySelector('[data-record-status]');
    if (blob.size > Number(labels.uploadLimit || 0) && Number(labels.uploadLimit) > 0) {
        status.textContent = labels.labelTooLarge;
        return;
    }
    status.textContent = labels.labelUploading;
    const data = new FormData();
    data.append('recording', blob, filename);

    try {
        const response = await fetch(labels.recordUrl.replace('__QUESTION__', question.dataset.question), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body: data,
        });
        const result = await response.json().catch(() => ({}));
        if (response.status === 409 && result.redirect) {
            leaveFor(result.redirect);
            return;
        }
        if (! response.ok) throw new Error(Object.values(result.errors || {}).flat()[0] || result.message || '');
        status.textContent = labels.labelRecorded;
        markAnswered(question, true);
        setStatus(labels.labelSaved.replace(':time', result.saved_at || ''));
    } catch (error) {
        status.textContent = error.message ? `${labels.labelUploadFailed} ${error.message}` : labels.labelUploadFailed;
    }
};

const extensionFor = (type) => {
    if (type.includes('mp4') || type.includes('aac')) return 'm4a';
    if (type.includes('ogg')) return 'ogg';
    return 'webm';
};

root.querySelectorAll('[data-recorder]').forEach((recorder) => {
    const question = recorder.closest('[data-question]');
    const start = recorder.querySelector('[data-record-start]');
    const stop = recorder.querySelector('[data-record-stop]');
    const clock = recorder.querySelector('[data-record-time]');
    const preview = recorder.querySelector('[data-record-preview]');
    const status = recorder.querySelector('[data-record-status]');
    const file = recorder.querySelector('[data-record-file]');
    let media = null;
    let tick = null;

    if (! navigator.mediaDevices?.getUserMedia || typeof window.MediaRecorder === 'undefined') {
        start.hidden = true;
    }

    start.addEventListener('click', async () => {
        let stream;
        try {
            stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        } catch {
            status.textContent = labels.labelMicError;
            return;
        }
        const chunks = [];
        media = new MediaRecorder(stream);
        media.addEventListener('dataavailable', (event) => { if (event.data.size) chunks.push(event.data); });
        media.addEventListener('stop', () => {
            stream.getTracks().forEach((track) => track.stop());
            window.clearInterval(tick);
            clock.hidden = true;
            stop.hidden = true;
            start.hidden = false;
            const type = media.mimeType || 'audio/webm';
            const blob = new Blob(chunks, { type });
            preview.src = URL.createObjectURL(blob);
            preview.hidden = false;
            uploadRecording(question, blob, `recording.${extensionFor(type)}`);
        });
        media.start();
        const began = Date.now();
        clock.textContent = '0:00';
        clock.hidden = false;
        tick = window.setInterval(() => {
            const seconds = Math.floor((Date.now() - began) / 1000);
            clock.textContent = `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
        }, 500);
        start.hidden = true;
        stop.hidden = false;
        status.textContent = labels.labelRecording;
        stop.focus();
    });

    stop.addEventListener('click', () => media?.state === 'recording' && media.stop());

    file.addEventListener('change', () => {
        const picked = file.files?.[0];
        if (! picked) return;
        preview.src = URL.createObjectURL(picked);
        preview.hidden = false;
        uploadRecording(question, picked, picked.name);
        file.value = '';
    });
});

// ---- Essay files (ADR-0027 D5): each picked file is uploaded at once; the server's list is what the page shows. ----

const FILE_TYPES = /\.(jpe?g|png|webp|heic|heif|pdf|docx)$/i;
const CHECK_DELAY_MS = 2000;
const CHECK_TRIES = 30;

root.querySelectorAll('[data-files]').forEach((box) => {
    const question = box.closest('[data-question]');
    const list = box.querySelector('[data-files-list]');
    const input = box.querySelector('[data-files-input]');
    const pick = box.querySelector('[data-files-pick]');
    const count = box.querySelector('[data-files-count]');
    const status = box.querySelector('[data-files-status]');
    const max = Number(box.dataset.filesMax || 3);
    const url = labels.filesUrl.replace('__QUESTION__', question.dataset.question);
    let files = [];
    try {
        files = JSON.parse(box.dataset.filesInitial || '[]');
    } catch {
        files = [];
    }

    const say = (text, isError = false) => {
        status.textContent = text;
        status.classList.toggle('is-error', isError);
    };

    const render = () => {
        list.replaceChildren(...files.map((file) => {
            const item = document.createElement('li');
            item.className = 'lf-exam-file';
            const thumb = document.createElement('span');
            thumb.className = 'lf-exam-file__thumb';
            thumb.setAttribute('aria-hidden', 'true');
            if (file.kind === 'image' && file.url) {
                const image = document.createElement('img');
                image.src = file.url;
                image.alt = '';
                thumb.append(image);
            } else {
                thumb.textContent = (file.name.split('.').pop() || '').slice(0, 4);
            }
            const name = document.createElement(file.url ? 'a' : 'span');
            name.className = 'lf-exam-file__name';
            name.textContent = file.name;
            if (file.url) {
                name.href = file.url;
                name.target = '_blank';
                name.rel = 'noopener';
            }
            item.append(thumb, name);
            if (! file.url) {
                const note = document.createElement('span');
                note.className = 'lf-exam-file__note';
                note.textContent = labels.labelFilesChecking;
                item.append(note);
            }
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'lf-exam-file__remove';
            remove.textContent = labels.labelFilesRemove;
            remove.setAttribute('aria-label', labels.labelFilesRemoveLabel.replace(':name', file.name));
            remove.addEventListener('click', () => removeFile(file, remove));
            item.append(remove);
            return item;
        }));
        count.textContent = labels.labelFilesCount.replace(':count', String(files.length));
        const full = files.length >= max;
        input.disabled = full;
        pick.classList.toggle('is-disabled', full);
        question.dataset.fileCount = String(files.length);
        markAnswered(question, payload(question).answered);
        watchChecking();
    };

    // A new upload has no link until its virus scan is done: ask for the list again for a while.
    let checkTimer = null;
    let checkTries = 0;
    const watchChecking = () => {
        clearTimeout(checkTimer);
        if (! files.some((file) => ! file.url)) {
            checkTries = 0;
            return;
        }
        if (checkTries >= CHECK_TRIES) return;
        checkTries += 1;
        checkTimer = setTimeout(async () => {
            try {
                const result = await request(url, 'GET');
                if (! result) return;
                files = result.files || [];
                render();
            } catch {
                // Leave the note; a reload shows the file once it is ready.
            }
        }, CHECK_DELAY_MS);
    };

    const request = async (target, method, body) => {
        const response = await fetch(target, {
            method,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body,
        });
        const result = await response.json().catch(() => ({}));
        if (response.status === 409 && result.redirect) {
            leaveFor(result.redirect);
            return null;
        }
        if (! response.ok) throw new Error(Object.values(result.errors || {}).flat()[0] || result.message || '');
        return result;
    };

    const upload = async (file) => {
        if (! FILE_TYPES.test(file.name)) {
            say(labels.labelFilesWrongType, true);
            return false;
        }
        if (Number(labels.uploadLimit) > 0 && file.size > Number(labels.uploadLimit)) {
            say(labels.labelTooLarge, true);
            return false;
        }
        say(labels.labelUploading);
        const data = new FormData();
        data.append('file', file, file.name);
        const result = await request(url, 'POST', data);
        if (! result) return false;
        files = result.files || [];
        render();
        say(labels.labelFilesSaved);
        setStatus(labels.labelSaved.replace(':time', result.saved_at || ''));
        return true;
    };

    const removeFile = async (file, button) => {
        button.disabled = true;
        try {
            const result = await request(`${url}/${file.id}`, 'DELETE');
            if (! result) return;
            files = result.files || [];
            render();
            say(labels.labelFilesRemoved);
            input.focus();
        } catch (error) {
            button.disabled = false;
            say(error.message ? `${labels.labelUploadFailed} ${error.message}` : labels.labelUploadFailed, true);
        }
    };

    input.addEventListener('change', async () => {
        const picked = [...(input.files || [])];
        input.value = '';
        const room = Math.max(0, max - files.length);
        let ok = true;
        for (const file of picked.slice(0, room)) {
            try {
                ok = await upload(file);
            } catch (error) {
                ok = false;
                say(error.message ? `${labels.labelUploadFailed} ${error.message}` : labels.labelUploadFailed, true);
            }
            if (! ok) break;
        }
        // Files past the limit are not sent; say so last so the message stays.
        if (ok && picked.length > room) {
            say(labels.labelFilesFull, true);
        }
    });

    render();
});

// ---- Timer: counts down to the server deadline; at zero the form is submitted with what is on the page. ----

const timer = root.querySelector('[data-exam-timer]');
if (timer && labels.remaining !== '') {
    const deadline = Date.now() + Number(labels.remaining) * 1000;
    const render = () => {
        const left = Math.max(0, Math.round((deadline - Date.now()) / 1000));
        const hours = Math.floor(left / 3600);
        const minutes = Math.floor((left % 3600) / 60);
        const seconds = String(left % 60).padStart(2, '0');
        timer.textContent = hours ? `${hours}:${String(minutes).padStart(2, '0')}:${seconds}` : `${String(minutes).padStart(2, '0')}:${seconds}`;
        timer.classList.toggle('is-warning', left <= 300);
        if (left === 0 && ! submitting) {
            submitting = true;
            setStatus(labels.labelTimeUp, true);
            form.submit();
        }
    };
    render();
    window.setInterval(render, 1000);
}

// ---- Submit: confirm (with the count of unanswered questions), then post the whole form. ----

const dialog = root.querySelector('[data-exam-confirm]');
const message = root.querySelector('[data-exam-confirm-message]');

form.addEventListener('submit', (event) => {
    if (submitting) return;
    event.preventDefault();
    const missing = root.querySelectorAll('[data-question][data-answered="0"]').length;
    message.textContent = missing > 0 ? labels.labelConfirmMissing.replace(':count', missing) : labels.labelConfirmAll;
    dialog.showModal();
});

root.querySelector('[data-exam-confirm-cancel]').addEventListener('click', () => dialog.close());
root.querySelector('[data-exam-confirm-accept]').addEventListener('click', () => {
    submitting = true;
    root.querySelector('[data-exam-submit]').disabled = true;
    dialog.close();
    form.submit();
});

window.addEventListener('beforeunload', (event) => {
    if (! submitting && (failedSaves > 0 || [...queues.values()].some((state) => state.running))) {
        event.preventDefault();
    }
});
