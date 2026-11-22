import { BarController, BarElement, CategoryScale, Chart, Legend, LinearScale, Tooltip } from 'chart.js';
import type { ChartData } from 'chart.js';
import { prefersReducedMotion } from '../services/tatami';
import { RUN_PROGRESS_EVENT } from './runProgress';

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Tooltip, Legend);

export interface Side {
    from: number | null;
    to: number | null;
}

export interface MetricsRow {
    label: string;
    score: Side;
    durationMs: Side;
    inputTokens: Side;
    outputTokens: Side;
    failureRate: Side;
}

export type MetricKey = Exclude<keyof MetricsRow, 'label'>;

interface Metric {
    key: MetricKey;
    title: string;
    scale: number;
    decimals: number;
}

export const METRICS: readonly Metric[] = [
    { key: 'score', title: 'Score (%)', scale: 1, decimals: 1 },
    { key: 'durationMs', title: 'Duration (s)', scale: 1000, decimals: 1 },
    { key: 'inputTokens', title: 'Input tokens', scale: 1, decimals: 0 },
    { key: 'outputTokens', title: 'Output tokens', scale: 1, decimals: 0 },
    { key: 'failureRate', title: 'Failure rate (%)', scale: 1, decimals: 1 },
];

function scaled(metric: Metric, value: number | null): number | null {
    return null === value ? null : value / metric.scale;
}

export function format(metric: Metric, value: number | null): string {
    const shown = scaled(metric, value);

    return null === shown
        ? '–'
        : shown.toLocaleString('en', {
              minimumFractionDigits: metric.decimals,
              maximumFractionDigits: metric.decimals,
          });
}

export function toChartData(
    rows: MetricsRow[],
    metric: Metric,
    labels: { from: string; to: string },
    colors: { from: string; to: string },
): ChartData<'bar'> {
    return {
        labels: rows.map((row) => row.label),
        datasets: [
            {
                label: labels.from,
                data: rows.map((row) => scaled(metric, row[metric.key].from)),
                backgroundColor: colors.from,
            },
            {
                label: labels.to,
                data: rows.map((row) => scaled(metric, row[metric.key].to)),
                backgroundColor: colors.to,
            },
        ],
    };
}

function cssColor(name: string, fallback: string): string {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;
}

/** One row per configuration, one column per metric, "before → after": the text alternative of the charts. */
function renderTable(table: HTMLTableElement, rows: MetricsRow[]): void {
    const head = document.createElement('tr');
    head.append(...['Configuration', ...METRICS.map((metric) => metric.title)].map((text) => cell('th', text)));
    const body = rows.map((row) => {
        const tr = document.createElement('tr');
        tr.append(
            cell('th', row.label),
            ...METRICS.map((metric) =>
                cell('td', `${format(metric, row[metric.key].from)} → ${format(metric, row[metric.key].to)}`),
            ),
        );

        return tr;
    });
    table.replaceChildren(head, ...body);
}

function cell(tag: 'th' | 'td', text: string): HTMLElement {
    const el = document.createElement(tag);
    el.textContent = text;

    return el;
}

/**
 * Draws one grouped bar chart per metric from the data-comparison JSON of the root and redraws them when the progress
 * poll of the version page reports new numbers; returns a stop function.
 */
export function startRunComparison(root: HTMLElement): () => void {
    const labels = { from: root.dataset.fromLabel ?? 'Previous', to: root.dataset.toLabel ?? 'Current' };
    const colors = { from: cssColor('--color-border-strong', '#8f7d5f'), to: cssColor('--color-highlight', '#ffc34d') };
    const text = cssColor('--color-text-muted', '#cabfac');
    const grid = cssColor('--color-border', '#534532');
    const animated = !prefersReducedMotion(window);
    let rows = JSON.parse(root.dataset.comparison ?? '[]') as MetricsRow[];
    const table = root.querySelector<HTMLTableElement>('table[data-comparison-table]');
    if (null !== table) {
        renderTable(table, rows);
    }

    const charts = METRICS.flatMap((metric) => {
        const canvas = root.querySelector<HTMLCanvasElement>(`canvas[data-metric="${metric.key}"]`);
        if (null === canvas) {
            return [];
        }

        return [
            {
                metric,
                chart: new Chart(canvas, {
                    type: 'bar',
                    data: toChartData(rows, metric, labels, colors),
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: animated ? undefined : false,
                        plugins: { legend: { labels: { color: text } } },
                        scales: {
                            x: { ticks: { color: text }, grid: { color: grid } },
                            y: { beginAtZero: true, ticks: { color: text }, grid: { color: grid } },
                        },
                    },
                }),
            },
        ];
    });

    const onUpdate = (event: Event): void => {
        const next = (event as CustomEvent<{ comparison?: MetricsRow[] }>).detail?.comparison;
        if (!Array.isArray(next) || 0 === next.length) {
            return;
        }
        rows = next;
        charts.forEach(({ metric, chart }) => {
            chart.data = toChartData(rows, metric, labels, colors);
            chart.update('none');
        });
        if (null !== table) {
            renderTable(table, rows);
        }
    };
    document.addEventListener(RUN_PROGRESS_EVENT, onUpdate);

    return () => {
        document.removeEventListener(RUN_PROGRESS_EVENT, onUpdate);
        charts.forEach(({ chart }) => chart.destroy());
    };
}

/** Component for the version page: charts of the version against the one before it. */
export default class RunComparison {
    initialize(el: HTMLElement): void {
        startRunComparison(el);
    }
}
