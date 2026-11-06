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
    return { body: { question: Number(question.dataset.question), text }, answered: text.trim() !== '' };
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
    if (! question || event.target.matches('[data-record-file]')) return;
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
