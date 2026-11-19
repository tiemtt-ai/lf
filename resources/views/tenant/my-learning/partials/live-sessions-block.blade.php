{{-- The learner's sessions to act on now, kept current without a reload (MK 3.1): while the tab is visible the block
     asks the server once a minute and swaps itself only when something changed, so a video playing on the page goes
     on. `$liveCohortId` narrows it to one class, as the page itself does. --}}
@php
    $liveBlockUrl = route('student.live-sessions.now', array_filter(['cohort' => $liveCohortId ?? null]));
@endphp
<div data-live-sessions-block data-url="{{ $liveBlockUrl }}">
    @if ($liveSessions->isNotEmpty())
        @include('tenant.my-learning.partials.live-sessions')
    @endif
</div>
@once
    <script>
        (() => {
            const refresh = async (block) => {
                if (document.visibilityState !== 'visible') return;
                try {
                    const response = await fetch(block.dataset.url, { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' }, credentials: 'same-origin' });
                    if (! response.ok) return;
                    const html = (await response.text()).trim();
                    // The first answer only sets the baseline: the page was just rendered with the same sessions.
                    if (block.dataset.liveSnapshot === undefined) { block.dataset.liveSnapshot = html; return; }
                    if (html === block.dataset.liveSnapshot) return;
                    block.dataset.liveSnapshot = html;
                    block.innerHTML = html;
                } catch (error) {
                    // Offline or signed out: the block stays as it is until the next minute.
                }
            };
            const tick = () => document.querySelectorAll('[data-live-sessions-block]').forEach(refresh);
            tick();
            window.setInterval(tick, 60000);
            document.addEventListener('visibilitychange', tick);
        })();
    </script>
@endonce
