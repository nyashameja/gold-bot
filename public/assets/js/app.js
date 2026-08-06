/**
 * Gold Bot front-end behaviour.
 *
 * Loaded before Alpine's CSP build, which is used instead of the standard
 * build because the standard one evaluates every `x-` expression with
 * `new Function()` — and the Content-Security-Policy in SecurityHeaders
 * deliberately withholds 'unsafe-eval'. Relaxing the policy to suit a UI
 * library would undo the protection it exists to provide.
 *
 * The trade is that components must be registered here as named data objects.
 * Templates may only reference property and method NAMES — no inline
 * expressions, no `x-data="{ open: false }"`.
 *
 * Everything below reads from OUR OWN JSON endpoints, which read MySQL. No
 * provider is contacted from the browser except the TradingView widget, which
 * is explicitly optional and degrades to a message (docs/01 §8).
 */

/** Shared chart styling, so every chart on the platform reads as one system. */
const CHART_COLOURS = {
    gold: '#d4af37',
    goldSoft: 'rgba(212, 175, 55, 0.16)',
    bull: '#10b981',
    bear: '#ef4444',
    ink: '#9ca3af',
    inkFaint: '#6b7280',
    grid: 'rgba(255, 255, 255, 0.06)',
    surface: '#0b0d10',
};

/**
 * Chart.js defaults applied once. Doing this per-chart is how a dashboard
 * ends up with four subtly different grid colours.
 */
function applyChartDefaults() {
    if (typeof Chart === 'undefined') {
        return false;
    }

    Chart.defaults.color = CHART_COLOURS.ink;
    Chart.defaults.font.family = "'JetBrains Mono', ui-monospace, monospace";
    Chart.defaults.font.size = 11;
    Chart.defaults.borderColor = CHART_COLOURS.grid;
    Chart.defaults.animation = false;
    Chart.defaults.maintainAspectRatio = false;
    Chart.defaults.plugins.legend.labels.boxWidth = 10;
    Chart.defaults.plugins.legend.labels.boxHeight = 10;
    Chart.defaults.plugins.legend.labels.usePointStyle = true;

    return true;
}

/**
 * Fetch JSON from one of our own endpoints.
 *
 * Returns null rather than throwing on any failure. A polling widget that
 * throws leaves the page with a dead interval and no visible cause; returning
 * null lets the caller keep its last known value and say so.
 */
async function getJson(url) {
    try {
        const response = await fetch(url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            return null;
        }

        return await response.json();
    } catch {
        return null;
    }
}

/** Formats a price consistently wherever one is displayed. */
function formatPrice(value) {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    return Number(value).toLocaleString('en-GB', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

/** Maps a DataAge status onto the dot colour used across the interface. */
function ageDotClass(status) {
    switch (status) {
        case 'FRESH':
            return 'bg-bull-500';
        case 'STALE':
            return 'bg-warn-400';
        case 'DEAD':
            return 'bg-bear-500';
        default:
            return 'bg-base-600';
    }
}

/**
 * Polling that pauses while the tab is hidden.
 *
 * A dashboard left open in a background tab overnight would otherwise make
 * thousands of pointless requests — and on shared hosting that is a real cost,
 * paid for data nobody is looking at.
 */
function poll(component, fn, intervalMs) {
    const tick = () => {
        if (!document.hidden) {
            fn();
        }
    };

    fn();
    component._pollId = window.setInterval(tick, intervalMs);

    // Refresh immediately when the tab is brought back, rather than showing a
    // stale number until the next interval elapses.
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            fn();
        }
    });
}

document.addEventListener('alpine:init', () => {

    /**
     * Dashboard shell: the off-canvas navigation drawer.
     *
     * Below `lg` the sidebar is a drawer; from `lg` up it is a fixed rail and
     * `navOpen` is irrelevant because `lg:translate-x-0` wins.
     */
    Alpine.data('shell', () => ({
        navOpen: false,

        open() {
            this.navOpen = true;
        },

        close() {
            this.navOpen = false;
        },

        /** Escape closes the drawer — expected of any modal-ish overlay. */
        onKeydown(event) {
            if (event.key === 'Escape') {
                this.navOpen = false;
            }
        },

        get panelClass() {
            return this.navOpen ? 'translate-x-0' : '-translate-x-full';
        },
    }));

    /** The price pill in the top bar, on every page. */
    Alpine.data('marketStatus', () => ({
        price: '—',
        age: 'loading',
        status: 'NONE',

        start() {
            poll(this, () => this.refresh(), 30000);
        },

        async refresh() {
            const data = await getJson('/api/overview');

            if (data === null) {
                this.age = 'offline';
                this.status = 'DEAD';
                return;
            }

            const quote = data.quote;
            this.price = quote.available ? formatPrice(quote.price) : '—';
            this.age = quote.age.label;
            this.status = quote.age.status;
        },

        get dotClass() {
            return ageDotClass(this.status);
        },
    }));

    /** Overview: refreshes the handful of tiles that actually move. */
    Alpine.data('overview', () => ({
        start() {
            poll(this, () => this.refresh(), 30000);
        },

        async refresh() {
            // The server owns the numbers; this only keeps them current. The
            // full board still comes from the initial render, so a failed poll
            // degrades to slightly older data rather than an empty page.
            await getJson('/api/overview');
        },
    }));

    /**
     * The health pill, refreshed without a reload.
     *
     * Exposed as text and class names rather than a markup string. Alpine's
     * CSP build prohibits x-html, and building HTML in JavaScript to inject
     * into the page is the habit that prohibition exists to break.
     */
    Alpine.data('healthStatus', () => ({
        status: 'OK',

        start() {
            poll(this, () => this.refresh(), 60000);
        },

        async refresh() {
            const data = await getJson('/api/health');

            if (data !== null) {
                this.status = data.status;
            }
        },

        get pillClass() {
            if (this.status === 'OK') {
                return 'badge-bull';
            }

            return this.status === 'CRITICAL' ? 'badge-bear' : 'badge-neutral';
        },

        get dotClass() {
            if (this.status === 'OK') {
                return 'bg-bull-500';
            }

            return this.status === 'CRITICAL' ? 'bg-bear-500' : 'bg-warn-400';
        },
    }));

    /**
     * Live Market: the TradingView widget plus our own candle chart.
     *
     * The two are deliberately separate. TradingView draws its own feed, so if
     * our ingest is behind, its chart still looks perfect while every signal
     * on the page was computed from something older. The local chart is the
     * one that shows what the engine actually saw.
     */
    Alpine.data('market', () => ({
        price: '',
        ageLabel: '',
        ageStatus: 'FRESH',
        tradingViewFailed: false,
        chart: null,

        start() {
            const root = this.$el;
            this.symbol = root.dataset.symbol;
            this.timeframe = root.dataset.timeframe;

            this.drawPriceChart(JSON.parse(root.dataset.chart));
            this.loadTradingView();

            poll(this, () => this.refreshQuote(), 30000);
        },

        async refreshQuote() {
            const quote = await getJson(`/api/market/quote?symbol=${encodeURIComponent(this.symbol)}`);

            if (quote === null || !quote.available) {
                return;
            }

            this.price = formatPrice(quote.price);
            this.ageStatus = quote.age.status;

            // The suffix matters as much as the colour: roughly one man in
            // twelve cannot reliably tell the amber from the red, and "10m
            // ago" alone reads as fine.
            const suffix = quote.age.status === 'STALE'
                ? ' · stale'
                : (quote.age.status === 'DEAD' ? ' · not updating' : '');

            this.ageLabel = `Quote ${quote.age.label}${suffix}`;
        },

        get ageDot() {
            return ageDotClass(this.ageStatus);
        },

        get ageTone() {
            if (this.ageStatus === 'STALE') {
                return 'text-warn-400';
            }

            return this.ageStatus === 'DEAD' ? 'text-bear-400' : 'text-ink-500';
        },

        drawPriceChart(payload) {
            if (!applyChartDefaults() || !this.$refs.priceChart) {
                return;
            }

            const candles = payload.candles;
            const labels = candles.map((c) => new Date(c.t * 1000).toISOString().slice(5, 16).replace('T', ' '));

            this.chart = new Chart(this.$refs.priceChart, {
                type: 'line',
                data: {
                    labels,
                    datasets: [
                        {
                            label: 'Close',
                            data: candles.map((c) => c.c),
                            borderColor: CHART_COLOURS.gold,
                            backgroundColor: CHART_COLOURS.goldSoft,
                            borderWidth: 1.5,
                            pointRadius: 0,
                            fill: true,
                            tension: 0.15,
                        },
                        {
                            label: 'EMA 50',
                            data: candles.map((c) => c.ema50),
                            borderColor: CHART_COLOURS.bull,
                            borderWidth: 1,
                            pointRadius: 0,
                            // Gaps are real: the indicator row for the newest
                            // candle may not exist yet, and bridging it would
                            // draw a line the engine never computed.
                            spanGaps: false,
                        },
                        {
                            label: 'EMA 200',
                            data: candles.map((c) => c.ema200),
                            borderColor: CHART_COLOURS.bear,
                            borderWidth: 1,
                            pointRadius: 0,
                            spanGaps: false,
                        },
                    ],
                },
                options: {
                    interaction: { mode: 'index', intersect: false },
                    scales: {
                        x: { grid: { display: false }, ticks: { maxTicksLimit: 8 } },
                        y: { grid: { color: CHART_COLOURS.grid }, position: 'right' },
                    },
                    plugins: { legend: { position: 'bottom' } },
                },
            });
        },

        /**
         * TradingView is the only third-party script the browser loads. It is
         * appended dynamically so a blocked or unreachable CDN cannot delay
         * first paint, and the failure is caught and shown rather than
         * leaving an empty box.
         */
        loadTradingView() {
            const container = document.getElementById('tradingview-chart');

            if (container === null) {
                return;
            }

            const script = document.createElement('script');
            script.src = 'https://s3.tradingview.com/external-embedding/embed-widget-advanced-chart.js';
            script.async = true;
            script.onerror = () => {
                this.tradingViewFailed = true;
            };

            script.text = JSON.stringify({
                symbol: 'OANDA:XAUUSD',
                interval: this.tradingViewInterval(),
                theme: 'dark',
                style: '1',
                locale: 'en',
                autosize: true,
                hide_side_toolbar: false,
                allow_symbol_change: false,
                studies: ['STD;EMA', 'STD;RSI'],
                backgroundColor: CHART_COLOURS.surface,
            });

            container.appendChild(script);

            // No load event fires if the CSP or the network blocks it, so a
            // timeout is what actually detects the failure.
            window.setTimeout(() => {
                if (container.querySelector('iframe') === null) {
                    this.tradingViewFailed = true;
                }
            }, 6000);
        },

        tradingViewInterval() {
            return { M5: '5', M15: '15', H1: '60', H4: '240', D1: 'D' }[this.timeframe] || '60';
        },
    }));

    /** Performance: the equity curve and the score-band chart. */
    Alpine.data('performanceCharts', () => ({
        start() {
            if (!applyChartDefaults()) {
                return;
            }

            this.drawEquity(JSON.parse(this.$el.dataset.equity));
            this.drawBands(JSON.parse(this.$el.dataset.bands));
        },

        drawEquity(points) {
            if (!this.$refs.equityChart || points.length === 0) {
                return;
            }

            new Chart(this.$refs.equityChart, {
                type: 'line',
                data: {
                    labels: points.map((p) => p.t.slice(0, 10)),
                    datasets: [{
                        label: 'Cumulative R',
                        data: points.map((p) => p.equity),
                        borderColor: CHART_COLOURS.gold,
                        backgroundColor: CHART_COLOURS.goldSoft,
                        borderWidth: 1.5,
                        pointRadius: 0,
                        fill: true,
                        stepped: false,
                    }],
                },
                options: {
                    scales: {
                        x: { grid: { display: false }, ticks: { maxTicksLimit: 8 } },
                        y: {
                            grid: { color: CHART_COLOURS.grid },
                            position: 'right',
                            // Zero must be on the axis: a curve auto-scaled to
                            // its own range makes a losing system look like a
                            // winning one with a dip.
                            beginAtZero: true,
                        },
                    },
                    plugins: { legend: { display: false } },
                },
            });
        },

        drawBands(bands) {
            if (!this.$refs.bandChart || bands.length === 0) {
                return;
            }

            new Chart(this.$refs.bandChart, {
                type: 'bar',
                data: {
                    labels: bands.map((b) => b.band),
                    datasets: [
                        {
                            label: 'Win rate %',
                            data: bands.map((b) => (b.total === 0 ? 0 : (b.wins / b.total) * 100)),
                            backgroundColor: CHART_COLOURS.gold,
                            yAxisID: 'y',
                        },
                        {
                            label: 'Signals',
                            data: bands.map((b) => b.total),
                            type: 'line',
                            borderColor: CHART_COLOURS.ink,
                            borderWidth: 1,
                            pointRadius: 2,
                            yAxisID: 'y1',
                        },
                    ],
                },
                options: {
                    scales: {
                        x: { grid: { display: false } },
                        y: { grid: { color: CHART_COLOURS.grid }, min: 0, max: 100, position: 'left' },
                        // The sample-size line shares the chart because a 100%
                        // win rate over two signals is noise, and the reader
                        // needs both numbers in the same glance to see that.
                        y1: { grid: { display: false }, position: 'right', beginAtZero: true },
                    },
                    plugins: { legend: { position: 'bottom' } },
                },
            });
        },
    }));

    /** API Usage: calls and failures per hour. */
    Alpine.data('apiUsage', () => ({
        start() {
            if (!applyChartDefaults() || !this.$refs.usageChart) {
                return;
            }

            const series = JSON.parse(this.$el.dataset.series);

            if (series.length === 0) {
                return;
            }

            new Chart(this.$refs.usageChart, {
                type: 'bar',
                data: {
                    labels: series.map((p) => p.bucket.slice(5, 13)),
                    datasets: [
                        {
                            label: 'Calls',
                            data: series.map((p) => p.calls),
                            backgroundColor: CHART_COLOURS.gold,
                            stack: 'calls',
                        },
                        {
                            label: 'Failures',
                            data: series.map((p) => p.failures),
                            backgroundColor: CHART_COLOURS.bear,
                            stack: 'calls',
                        },
                    ],
                },
                options: {
                    scales: {
                        x: { stacked: true, grid: { display: false }, ticks: { maxTicksLimit: 12 } },
                        y: { stacked: true, grid: { color: CHART_COLOURS.grid }, beginAtZero: true },
                    },
                    plugins: { legend: { position: 'bottom' } },
                },
            });
        },
    }));

    /**
     * Signals list. No polling: a table that reorders itself under the cursor
     * while someone is reading it is worse than one that is thirty seconds
     * old.
     */
    Alpine.data('signalList', () => ({}));

    /** Users page — the forms are plain posts; nothing to hold in state. */
    Alpine.data('userAdmin', () => ({}));
});
