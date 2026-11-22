import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const created: {
    config: { data: unknown };
    data: unknown;
    update: ReturnType<typeof vi.fn>;
    destroy: ReturnType<typeof vi.fn>;
}[] = [];

vi.mock('chart.js', () => {
    class Chart {
        static register = vi.fn();
        data: unknown;
        update = vi.fn();
        destroy = vi.fn();
        constructor(
            public canvas: HTMLCanvasElement,
            public config: { data: unknown },
        ) {
            this.data = config.data;
            created.push(this as never);
        }
    }

    return { Chart, BarController: {}, BarElement: {}, CategoryScale: {}, LinearScale: {}, Tooltip: {}, Legend: {} };
});

import { METRICS, format, startRunComparison, toChartData } from '../../components/runComparison';
import type { MetricsRow } from '../../components/runComparison';
import { RUN_PROGRESS_EVENT } from '../../components/runProgress';

const side = (from: number | null, to: number | null) => ({ from, to });
const row = (label: string, score = side(50, 70)): MetricsRow => ({
    label,
    score,
    durationMs: side(1500, 500),
    inputTokens: side(100, 200),
    outputTokens: side(null, 20),
    failureRate: side(0, 50),
});

function root(rows: MetricsRow[]): HTMLElement {
    const el = document.createElement('div');
    el.dataset.comparison = JSON.stringify(rows);
    el.dataset.fromLabel = 'Version 1';
    el.dataset.toLabel = 'Version 2';
    el.innerHTML =
        METRICS.map((metric) => `<canvas data-metric="${metric.key}"></canvas>`).join('') +
        '<table data-comparison-table></table>';

    return el;
}

describe('toChartData', () => {
    const colors = { from: '#111', to: '#222' };
    const labels = { from: 'Version 1', to: 'Version 2' };

    it('makes one bar pair per row with the labels and colors given', () => {
        const data = toChartData([row('A'), row('Total')], METRICS[0], labels, colors);

        expect(data.labels).toEqual(['A', 'Total']);
        expect(data.datasets.map((set) => set.label)).toEqual(['Version 1', 'Version 2']);
        expect(data.datasets.map((set) => set.data)).toEqual([
            [50, 50],
            [70, 70],
        ]);
        expect(data.datasets.map((set) => set.backgroundColor)).toEqual(['#111', '#222']);
    });

    it('shows durations in seconds and keeps a missing value null', () => {
        const duration = METRICS.find((metric) => 'durationMs' === metric.key)!;
        const output = METRICS.find((metric) => 'outputTokens' === metric.key)!;

        expect(toChartData([row('A')], duration, labels, colors).datasets.map((set) => set.data)).toEqual([
            [1.5],
            [0.5],
        ]);
        expect(toChartData([row('A')], output, labels, colors).datasets[0].data).toEqual([null]);
    });
});

describe('format', () => {
    it('uses the decimals of the metric and a dash for a missing value', () => {
        expect(format(METRICS[0], 72.5)).toBe('72.5');
        expect(format(METRICS[1], 1234)).toBe('1.2');
        expect(format(METRICS[2], 1234)).toBe('1,234');
        expect(format(METRICS[3], null)).toBe('–');
    });
});

describe('startRunComparison', () => {
    beforeEach(() => {
        created.length = 0;
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('creates one chart per metric and fills the text alternative', () => {
        const el = root([row('A'), row('Total')]);
        startRunComparison(el);

        expect(created).toHaveLength(METRICS.length);
        const cells = [...el.querySelectorAll('tbody td, tr:nth-child(2) td')].map((td) => td.textContent);
        expect(el.querySelectorAll('tr')).toHaveLength(3);
        expect(cells[0]).toBe('50.0 → 70.0');
        expect(el.querySelector('tr:nth-child(2) th')?.textContent).toBe('A');
    });

    it('updates the charts and the table when the progress poll reports new numbers, and stops after the stop function', () => {
        const el = root([row('A')]);
        const stop = startRunComparison(el);

        document.dispatchEvent(
            new CustomEvent(RUN_PROGRESS_EVENT, { detail: { comparison: [row('A', side(50, 90))] } }),
        );

        expect(created[0].update).toHaveBeenCalledWith('none');
        expect((created[0].data as { datasets: { data: unknown }[] }).datasets[1].data).toEqual([90]);
        expect(el.querySelector('tr:nth-child(2) td')?.textContent).toBe('50.0 → 90.0');

        stop();
        document.dispatchEvent(new CustomEvent(RUN_PROGRESS_EVENT, { detail: { comparison: [row('A', side(1, 2))] } }));

        expect(created[0].destroy).toHaveBeenCalled();
        expect(created[0].update).toHaveBeenCalledTimes(1);
    });

    it('ignores an update without a comparison', () => {
        startRunComparison(root([row('A')]));

        document.dispatchEvent(new CustomEvent(RUN_PROGRESS_EVENT, { detail: { status: 'running' } }));
        document.dispatchEvent(new CustomEvent(RUN_PROGRESS_EVENT, { detail: { comparison: [] } }));

        expect(created[0].update).not.toHaveBeenCalled();
    });
});
