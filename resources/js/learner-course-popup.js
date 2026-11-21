import { canEnterFullscreen, clock, enterFullscreen, leaveFullscreen, stamp } from './learner-lesson.js';

/**
 * The self-paced course page (`tenant/my-learning/course.blade.php`, ADR-0030): the lesson search, and the MK popup that
 * plays a lesson's first video (else audio) on its stage with the other content below.
 */

/** The player while it shows (not audio), else the stage; and the video, which Safari on iPhone takes alone. */
export function fullscreenFor(media, stage) {
    return [media && media.tagName !== 'AUDIO' && media.offsetParent ? media : stage, media?.tagName === 'VIDEO' ? media : null];
}

/** Off on a locked lesson, and where it can do nothing: an embedded YouTube/Vimeo player or audio on iPhone. */
export function fullscreenOff(main, media, stage, doc = globalThis.document) {
    return !main || main.item.classList.contains('is-locked') || !canEnterFullscreen(...fullscreenFor(media, stage), doc);
}

/**
 * The full screen button: leaves full screen first, else takes the frame (or the video) full screen; on Safari on
 * iPhone a video not loaded yet (the "Tiếp tục xem" question) is played first, then taken full screen.
 */
export function toggleFullscreen({ media, stage, state, resume, doc = globalThis.document }) {
    if (leaveFullscreen(doc)) {
        return;
    }
    const [target, video] = fullscreenFor(media, stage);
    if (enterFullscreen(target, video, doc)) {
        return;
    }
    if (state?.playFullscreen?.()) {
        resume.hidden = true;
    }
}

const MEDIA = ['video', 'embedded_video', 'audio'];

/** Wires the page if it is the course page; `win` gives Alpine, fetch, timers and the parser. */
export function installCoursePopup(doc = document, win = window) {
    const root = doc.querySelector('.lf-learn-course');
    if (!root) {
        return;
    }
    const input = root.querySelector('[data-learn-search]');
    const empty = root.querySelector('[data-learn-search-empty]');
    const filter = () => {
        if (!input) {
            return;
        }
        const term = input.value.trim().toLowerCase();
        let shown = 0;
        root.querySelectorAll('[data-learn-section]').forEach((section) => {
            let inSection = 0;
            section.querySelectorAll('[data-learn-lesson]').forEach((row) => {
                const match = term === '' || row.dataset.learnLessonTitle.includes(term);
                row.hidden = !match;
                if (match) {
                    inSection++;
                }
            });
            section.hidden = inSection === 0;
            shown += inSection;
        });
        empty.hidden = shown > 0;
    };
    input?.addEventListener('input', filter);

    const modal = doc.querySelector('[data-learn-modal]');
    if (!modal || typeof modal.showModal !== 'function') {
        return;
    }
    const pick = (name) => modal.querySelector(`[data-learn-modal-${name}]`);
    const stage = pick('stage');
    const more = pick('more');
    const moreList = pick('more-list');
    const progress = pick('progress');
    const restart = pick('restart');
    const resume = pick('resume');
    const fullscreen = pick('fullscreen');
    const previous = pick('previous');
    const next = pick('next');
    const messages = { loading: modal.dataset.learnMessageLoading ?? '', failed: modal.dataset.learnMessageFailed ?? '' };
    let current = null; // the lesson page URL shown
    let request = 0; // only the latest "Vào học" may fill the popup
    let main = null; // the stage's Activity: { item, duration, played }
    const links = () => [...root.querySelectorAll('[data-learn-open]')];
    const showProgress = (viewed, seconds, percent) => {
        const time = pick('viewed');
        time.hidden = !viewed;
        time.textContent = viewed || '';
        time.dateTime = (viewed || '').replace(' ', 'T');
        pick('seconds').textContent = clock(seconds);
        pick('percent').textContent = `${Math.min(100, Math.max(0, Math.floor(percent)))}%`;
    };
    const note = (text, alert = false) => {
        const p = doc.createElement('p');
        p.className = 'lf-cd-modal__note';
        p.setAttribute('role', alert ? 'alert' : 'status');
        p.textContent = text;
        stage.replaceChildren(p);
        stage.className = 'lf-cd-modal__stage';
        stage.hidden = false;
        more.hidden = true;
        moreList.replaceChildren();
    };
    const player = () => main?.item.querySelector('video, audio, iframe') ?? null;
    const state = () => (main && win.Alpine ? win.Alpine.$data(main.item) : null);
    const stop = () => {
        resume.hidden = true;
        // The pause event comes after the stage is emptied for the next lesson, so report the position now.
        const data = state();
        if (data?.started && main.item.querySelector('video, audio')?.paused === false) {
            data.onPause();
        }
        modal.querySelectorAll('video, audio').forEach((media) => media.pause());
    };
    const syncButtons = () => {
        const media = player();
        restart.disabled = !main || main.item.dataset.learnActivityType === 'embedded_video' || main.item.classList.contains('is-locked');
        fullscreen.disabled = fullscreenOff(main, media, stage, doc);
        progress.hidden = !main;
    };

    const open = async (link) => {
        const mine = ++request;
        stop();
        main = null;
        current = link.href;
        pick('no').textContent = link.dataset.learnOpenNo;
        pick('title').textContent = link.dataset.learnOpenTitle;
        const all = links();
        const at = all.findIndex((other) => other.href === current);
        previous.disabled = at <= 0;
        next.disabled = at < 0 || at >= all.length - 1;
        syncButtons();
        note(messages.loading);
        if (!modal.open) {
            modal.showModal();
        }
        try {
            const response = await win.fetch(link.href, { headers: { Accept: 'text/html' }, credentials: 'same-origin' });
            if (!response.ok) {
                throw new Error('lesson');
            }
            const page = new win.DOMParser().parseFromString(await response.text(), 'text/html');
            const content = page.querySelector('.lf-learn-activities');
            const none = page.querySelector('.lf-learn-outline .lf-learn-empty');
            if (mine !== request) {
                return;
            }
            if (!content) {
                throw new Error('lesson');
            }
            // The Quiz has its own button on the row; the first video (else audio) plays on the stage.
            const items = [...content.children].filter((item) => item.dataset.learnActivityType !== 'quiz');
            const first = items.find((item) => ['video', 'embedded_video'].includes(item.dataset.learnActivityType))
                ?? items.find((item) => MEDIA.includes(item.dataset.learnActivityType));
            const rest = items.filter((item) => item !== first);
            // Alpine starts the players of the nodes as they enter the document.
            if (first) {
                const item = doc.importNode(first, true);
                const duration = Number(item.dataset.learnActivityDuration) || 0;
                const percent = Number(item.dataset.learnActivityPercent) || 0;
                main = {
                    item, duration, played: Number(item.dataset.learnActivitySeconds) || 0, percent, viewed: item.dataset.learnActivityViewed,
                    resumeAt: Number(item.dataset.learnActivityResume) || 0,
                };
                stage.className = `lf-cd-modal__stage is-${item.dataset.learnActivityType}`;
                stage.replaceChildren(item);
                showProgress(main.viewed, main.played, percent);
            } else {
                stage.hidden = true;
                stage.replaceChildren();
            }
            const list = doc.createElement('ol');
            list.className = 'lf-learn-activities';
            list.append(...rest.map((item) => doc.importNode(item, true)));
            moreList.replaceChildren(...[none ? doc.importNode(none, true) : null, rest.length ? list : null].filter(Boolean));
            more.hidden = !(rest.length || none);
            syncButtons();
            // "Vào học" means play: start the stage's player once Alpine has picked it up. A video or audio stopped
            // part-way first asks, like MK, whether to go on from there or start over (one near its end starts over).
            if (main && !main.item.classList.contains('is-locked')) {
                const asks = ['video', 'audio'].includes(main.item.dataset.learnActivityType) && main.resumeAt > 0
                    && !(main.duration > 0 && main.resumeAt >= main.duration - 5);
                if (asks) {
                    pick('resume-time').textContent = clock(main.resumeAt);
                    resume.hidden = false;
                    pick('resume-continue').focus();
                } else {
                    win.setTimeout(() => {
                        if (mine === request) {
                            main?.item.querySelector('[data-learn-play]')?.click();
                        }
                    }, 0);
                }
            }
        } catch {
            if (mine === request) {
                note(messages.failed, true);
            }
        }
    };

    // The footer follows the stage's player: played time only grows with normal playback, as the server counts it.
    stage.addEventListener('timeupdate', (event) => {
        if (!main || !event.target.matches('video, audio')) {
            return;
        }
        const time = event.target.currentTime;
        if (time > main.played && time <= main.played + 3) {
            main.played = time;
        }
        const served = Number(state()?.percentage) || 0;
        const local = main.duration > 0 ? (100 * main.played) / main.duration : 0;
        // The server's answer only matters once it says complete; it rounds, the row floors.
        showProgress(main.viewed, served >= 100 ? main.duration : main.played, served >= 100 ? 100 : Math.max(local, main.percent));
    }, true);
    stage.addEventListener('play', () => {
        // Playing answers the "Tiếp tục xem" question, whichever button started it: the stage's own one in full
        // screen (iPad, Chrome) is outside the question's reach, and the question would stay over the video after.
        resume.hidden = true;
        if (!main) {
            return;
        }
        main.viewed = stamp(new Date());
        pick('viewed').hidden = false;
        pick('viewed').textContent = main.viewed;
    }, true);

    const startOver = () => {
        resume.hidden = true;
        const media = player();
        if (!main || !media || media.tagName === 'IFRAME') {
            return;
        }
        if (state()?.started) {
            // Report where playback got to before going back, so the played share is not lost.
            if (media.currentTime > 0) {
                state().send('position', { position_seconds: media.currentTime }).catch(() => {});
            }
            media.currentTime = 0;
            media.play().catch(() => {});

            return;
        }
        media.addEventListener('playing', () => { media.currentTime = 0; }, { once: true });
        state()?.play();
    };
    restart.addEventListener('click', startOver);
    pick('resume-restart').addEventListener('click', startOver);
    pick('resume-continue').addEventListener('click', () => {
        resume.hidden = true;
        main?.item.querySelector('[data-learn-play]')?.click();
    });
    fullscreen.addEventListener('click', () => toggleFullscreen({ media: player(), stage, state: state(), resume, doc }));

    // After learning, the figures and the lesson rows are read again; the page itself stays.
    const refresh = async () => {
        try {
            const response = await win.fetch(win.location.href, { headers: { Accept: 'text/html' }, credentials: 'same-origin' });
            if (!response.ok) {
                return;
            }
            const page = new win.DOMParser().parseFromString(await response.text(), 'text/html');
            ['.lf-cd-stats', '.lf-cd-access', '#learn-pane-lessons .lf-cd-sections'].forEach((selector) => {
                const fresh = page.querySelector(selector);
                const stale = root.querySelector(selector);
                if (fresh && stale) {
                    stale.replaceWith(doc.importNode(fresh, true));
                }
            });
            filter();
        } catch {
            // The next visit shows the new figures.
        }
    };

    root.addEventListener('click', (event) => {
        const link = event.target.closest('[data-learn-open]');
        if (!link || event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) {
            return;
        }
        event.preventDefault();
        open(link);
    });
    const step = (by) => {
        const all = links();
        const target = all[all.findIndex((other) => other.href === current) + by];
        if (target) {
            open(target);
        }
    };
    previous.addEventListener('click', () => step(-1));
    next.addEventListener('click', () => step(1));
    pick('close').addEventListener('click', () => modal.close());
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            modal.close();
        }
    });
    modal.addEventListener('close', () => {
        request++;
        stop();
        leaveFullscreen(doc);
        // Let the players send their last position before they leave the page.
        win.setTimeout(() => {
            if (modal.open) {
                return;
            }
            main = null;
            stage.replaceChildren();
            moreList.replaceChildren();
            refresh();
        }, 400);
    });
}
