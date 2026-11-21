/**
 * The button of an upcoming Session on the class page (`tenant/my-learning/cohort.blade.php`): on the Session's own day,
 * from 00:00 where it is held, the time left ("Vào học sau: hh:mm:ss"); before that day, "Ngày mai" or "Còn N ngày",
 * counted in calendar days so it agrees with the date written beside it.
 */
export function countdownText(startsAt, dayStart, now, labels) {
    if (now < dayStart) {
        const days = Math.ceil((dayStart - now) / 86400);

        return days <= 1 ? labels.tomorrow : labels.days.replace('__DAYS__', String(days));
    }
    const left = Math.max(0, startsAt - now);
    const time = [Math.floor(left / 3600), Math.floor(left / 60) % 60, left % 60].map((part) => String(part).padStart(2, '0')).join(':');

    return labels.countdown.replace('__TIME__', time);
}

/** Keeps every such button's label current, once a second. */
export function installSessionCountdowns(doc = document, win = window) {
    const buttons = [...doc.querySelectorAll('[data-learn-countdown]')];
    if (!buttons.length) {
        return;
    }
    const tick = () => {
        const now = Math.floor(win.Date.now() / 1000);
        buttons.forEach((button) => {
            const { learnCountdown, learnCountdownDay, countdownLabel, countdownTomorrow, countdownDays } = button.dataset;
            button.querySelector('.lesson-countdown-label').textContent = countdownText(Number(learnCountdown), Number(learnCountdownDay), now,
                { countdown: countdownLabel, tomorrow: countdownTomorrow, days: countdownDays });
        });
    };
    tick();
    win.setInterval(tick, 1000);
}
