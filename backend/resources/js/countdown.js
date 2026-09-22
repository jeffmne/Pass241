const terminalStatuses = new Set(['COMPLETED', 'CLOSED', 'EXPIRED', 'ARCHIVED']);

function timestamp(value) {
    if (value === null || value === undefined || value === '') return null;
    const parsed = typeof value === 'number' ? value : Date.parse(value);
    return Number.isFinite(parsed) ? parsed : null;
}

/** Les dates absolues evitent la derive des intervalles et des onglets en veille. */
export function countdownState({ startsAt, endsAt, status = '' }, now) {
    const start = timestamp(startsAt);
    const end = timestamp(endsAt);
    let phase;
    let target = null;

    if (status === 'CANCELLED') {
        phase = 'cancelled';
    } else if (terminalStatuses.has(status) || (end !== null && now >= end)) {
        phase = 'ended';
    } else if (start !== null && now < start) {
        phase = 'upcoming';
        target = start;
    } else {
        phase = 'ongoing';
        target = end;
    }

    const seconds = target === null ? 0 : Math.max(0, Math.ceil((target - now) / 1000));
    return {
        phase,
        ticking: target !== null,
        parts: {
            days: Math.floor(seconds / 86400),
            hours: Math.floor((seconds % 86400) / 3600),
            minutes: Math.floor((seconds % 3600) / 60),
            seconds: seconds % 60,
        },
    };
}

/** Corrige le decalage initial du telephone avec l'heure du rendu serveur. */
export function serverClock(serverTime, clientTime) {
    const origin = timestamp(serverTime) ?? clientTime;
    return (currentClientTime) => origin + currentClientTime - clientTime;
}

export function installCountdowns(document, window) {
    const counters = new Map();
    let timer = null;

    function stop() {
        if (timer !== null) window.clearInterval(timer);
        timer = null;
    }

    function paint(element, counter) {
        const state = countdownState(counter.config, counter.now(Date.now()));
        const label = element.querySelector('[data-countdown-label]');
        const text = element.dataset[`label${state.phase[0].toUpperCase()}${state.phase.slice(1)}`];
        // Seuls les changements de phase sont annonces aux lecteurs d'ecran.
        if (label.textContent !== text) label.textContent = text;
        const digits = element.querySelector('[data-countdown-digits]');
        digits.hidden = !state.ticking;
        digits.setAttribute('aria-label', text);
        element.querySelector('[data-countdown-no-end]').hidden = state.phase !== 'ongoing' || state.ticking;
        for (const [unit, value] of Object.entries(state.parts)) {
            const node = element.querySelector(`[data-countdown-${unit}]`);
            const formatted = String(value).padStart(2, '0');
            if (node.textContent !== formatted) node.textContent = formatted;
        }
        element.dataset.countdownPhase = state.phase;
        return state.ticking;
    }

    function tick() {
        let active = false;
        for (const [element, counter] of counters) {
            if (!element.isConnected) {
                counters.delete(element);
                continue;
            }
            active = paint(element, counter) || active;
        }
        if (!active) stop();
        return active;
    }

    function refresh() {
        const clientNow = Date.now();
        for (const element of document.querySelectorAll('[data-countdown]')) {
            const signature = [element.dataset.startsAt, element.dataset.endsAt, element.dataset.status, element.dataset.serverNow].join('|');
            if (counters.get(element)?.signature === signature) continue;
            counters.set(element, {
                signature,
                config: { startsAt: element.dataset.startsAt, endsAt: element.dataset.endsAt, status: element.dataset.status },
                now: serverClock(element.dataset.serverNow, clientNow),
            });
        }
        if (tick() && !document.hidden && timer === null) timer = window.setInterval(tick, 1000);
    }

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) stop();
        else refresh();
    });
    document.addEventListener('livewire:navigating', () => { stop(); counters.clear(); });
    document.addEventListener('livewire:navigated', refresh);
    window.addEventListener('pagehide', stop);
    window.addEventListener('pageshow', refresh);

    const registerLivewire = () => window.Livewire?.hook('morphed', refresh);
    if (window.Livewire) registerLivewire();
    else document.addEventListener('livewire:init', registerLivewire, { once: true });

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', refresh, { once: true });
    else refresh();
}
