import { Controller } from '@hotwired/stimulus';

/*
 * Loads the "today's matches" Turbo Frame using the browser timezone so that
 * "today" matches the user's local day, then polls a lightweight JSON endpoint
 * to refresh live score / status / provisional points without reloading the page.
 */
export default class extends Controller {
    static values = {
        url: String,
        liveUrl: String,
        interval: { type: Number, default: 60 },
    };

    connect() {
        this.onFrameLoad = () => this.startPolling();
        this.element.addEventListener('turbo:frame-load', this.onFrameLoad);
        this.element.src = this.withTz(this.urlValue);
    }

    disconnect() {
        this.element.removeEventListener('turbo:frame-load', this.onFrameLoad);
        this.stopPolling();
    }

    withTz(url) {
        const tz = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
        const offset = -new Date().getTimezoneOffset();
        const sep = url.includes('?') ? '&' : '?';
        return `${url}${sep}tz=${encodeURIComponent(tz)}&offset=${offset}`;
    }

    startPolling() {
        this.stopPolling();
        this.timer = setInterval(() => this.refresh(), this.intervalValue * 1000);
    }

    stopPolling() {
        if (this.timer) {
            clearInterval(this.timer);
            this.timer = null;
        }
    }

    async refresh() {
        try {
            const res = await fetch(this.withTz(this.liveUrlValue), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) return;
            const data = await res.json();
            (data.fixtures || []).forEach((fixture) => this.patch(fixture));
        } catch {
            // ignore transient polling errors
        }
    }

    patch(fixture) {
        const card = this.element.querySelector(`[data-fixture-id="${fixture.id}"]`);
        if (!card) return;

        // Don't disturb a card while the user is typing a score in it.
        const active = document.activeElement;
        if (active && card.contains(active) && active.tagName === 'INPUT') return;

        const scoreboard = card.querySelector('[data-role="scoreboard"]');
        if (scoreboard && fixture.scoreboardText) {
            scoreboard.textContent = fixture.scoreboardText;
        }

        const badge = card.querySelector('[data-role="status-badge"]');
        if (badge) {
            badge.textContent = fixture.statusLabel;
            badge.className = `badge ${fixture.statusClass}`;
        }

        const points = card.querySelector('[data-role="points"]');
        if (points) {
            points.textContent = fixture.pointsText || '';
            points.className = `mt-2 ${fixture.pointsClass || ''}`;
        }
    }
}
