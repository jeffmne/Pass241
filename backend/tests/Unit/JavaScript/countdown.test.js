import test from 'node:test';
import assert from 'node:assert/strict';
import { countdownState, installCountdowns, serverClock } from '../../../resources/js/countdown.js';

const start = Date.parse('2026-10-01T12:00:00Z');
const end = start + 2 * 86400_000 + 3 * 3600_000 + 4 * 60_000 + 5000;
const schedule = { startsAt: start, endsAt: end, status: 'PUBLISHED' };

test('decompose les jours, heures, minutes et secondes sans limiter les jours a deux chiffres', () => {
    const now = start - (123 * 86400 + 4 * 3600 + 5 * 60 + 6) * 1000;
    assert.deepEqual(countdownState(schedule, now), {
        phase: 'upcoming', ticking: true, parts: { days: 123, hours: 4, minutes: 5, seconds: 6 },
    });
});

test('bascule exactement au debut vers le compteur de fin', () => {
    assert.equal(countdownState(schedule, start - 1).parts.seconds, 1);
    assert.deepEqual(countdownState(schedule, start), {
        phase: 'ongoing', ticking: true, parts: { days: 2, hours: 3, minutes: 4, seconds: 5 },
    });
});

test('arrete le compteur exactement a la fin et ne produit jamais de valeurs negatives', () => {
    for (const now of [end, end + 86400_000]) {
        assert.deepEqual(countdownState(schedule, now), {
            phase: 'ended', ticking: false, parts: { days: 0, hours: 0, minutes: 0, seconds: 0 },
        });
    }
});

test('interprete les dates avec leur fuseau horaire', () => {
    const state = countdownState({ startsAt: '2026-10-01T13:00:00+01:00', endsAt: end }, start - 1000);
    assert.equal(state.phase, 'upcoming');
    assert.equal(state.parts.seconds, 1);
});

test('une annulation ou cloture anticipee prime sur les dates futures', () => {
    assert.equal(countdownState({ ...schedule, status: 'CANCELLED' }, start - 1000).phase, 'cancelled');
    for (const status of ['COMPLETED', 'CLOSED', 'EXPIRED', 'ARCHIVED']) {
        assert.equal(countdownState({ ...schedule, status }, start - 1000).phase, 'ended');
    }
});

test('une collecte sans debut explicite compte jusqu a sa fin', () => {
    assert.equal(countdownState({ endsAt: end }, start).phase, 'ongoing');
    assert.equal(countdownState({ endsAt: end }, start).ticking, true);
});

test('une activite sans fin cesse de compter apres son ouverture', () => {
    assert.equal(countdownState({ startsAt: start }, start - 1000).ticking, true);
    assert.equal(countdownState({ startsAt: start }, start).ticking, false);
    assert.equal(countdownState({ startsAt: '', endsAt: '' }, start).ticking, false);
    assert.equal(countdownState({ startsAt: 'invalid', endsAt: null }, start).ticking, false);
});

test('corrige une horloge de telephone decalee et rattrape un onglet en veille', () => {
    const clientTime = start - 7 * 86400_000;
    const now = serverClock('2026-10-01T11:59:59Z', clientTime);
    assert.equal(countdownState(schedule, now(clientTime)).parts.seconds, 1);
    assert.equal(countdownState(schedule, now(clientTime + 1500)).phase, 'ongoing');
    assert.equal(countdownState(schedule, now(clientTime + 4 * 86400_000)).phase, 'ended');
});

test('partage un seul intervalle, le suspend en veille et nettoie les navigations Livewire', (t) => {
    let now = start - 1000;
    t.mock.method(Date, 'now', () => now);
    const listeners = new Map();
    const intervals = new Map();
    const nodes = new Map();
    for (const name of ['label', 'digits', 'no-end', 'days', 'hours', 'minutes', 'seconds']) {
        nodes.set(`[data-countdown-${name}]`, { textContent: '', hidden: false, setAttribute() {} });
    }
    const counter = {
        isConnected: true,
        dataset: { startsAt: new Date(start).toISOString(), endsAt: new Date(end).toISOString(), serverNow: new Date(now).toISOString(), status: 'PUBLISHED', labelUpcoming: 'Debut', labelOngoing: 'Fin', labelEnded: 'Termine' },
        querySelector: (selector) => nodes.get(selector),
    };
    const document = {
        hidden: false, readyState: 'complete',
        querySelectorAll: () => counter.isConnected ? [counter] : [],
        addEventListener: (event, callback) => listeners.set(event, callback),
    };
    const window = {
        setInterval(callback) { intervals.set(1, callback); return 1; },
        clearInterval: (id) => intervals.delete(id),
        addEventListener: (event, callback) => listeners.set(event, callback),
    };
    installCountdowns(document, window);
    listeners.get('livewire:navigated')();
    assert.equal(intervals.size, 1);
    assert.equal(nodes.get('[data-countdown-label]').textContent, 'Debut');
    now = start;
    intervals.get(1)();
    assert.equal(nodes.get('[data-countdown-label]').textContent, 'Fin');
    document.hidden = true;
    listeners.get('visibilitychange')();
    assert.equal(intervals.size, 0);
    now = end;
    document.hidden = false;
    listeners.get('visibilitychange')();
    assert.equal(nodes.get('[data-countdown-label]').textContent, 'Termine');
    assert.equal(nodes.get('[data-countdown-digits]').hidden, true);
    assert.equal(intervals.size, 0);
    listeners.get('livewire:navigating')();
    counter.isConnected = false;
    listeners.get('livewire:navigated')();
    assert.equal(intervals.size, 0);
});
