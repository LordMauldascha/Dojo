const INTERVAL_MS = 3000;
const MAX_FAILURES = 3;
const STOPPED_NOTICE = 'Live update stopped. Reload the page to see the latest state.';

interface Progress {
    status: string;
    done: number;
    total: number;
    html: string;
}

interface ViewState {
    open: Map<string, number>;
    focused: string | null;
    scrollLeft: number;
}

function cellOf(el: Element | null): HTMLDetailsElement | null {
    const details = el?.closest('details[data-cell]');

    return details instanceof HTMLDetailsElement ? details : null;
}

function captureState(root: HTMLElement): ViewState {
    const open = new Map<string, number>();
    root.querySelectorAll<HTMLDetailsElement>('details[data-cell][open]').forEach((details) => {
        open.set(details.dataset.cell ?? '', details.querySelector('pre')?.scrollTop ?? 0);
    });
    const active = document.activeElement;
    const focused =
        active instanceof HTMLElement && 'SUMMARY' === active.tagName ? (cellOf(active)?.dataset.cell ?? null) : null;

    return { open, focused, scrollLeft: root.querySelector('.result-matrix-scroll')?.scrollLeft ?? 0 };
}

function restoreState(root: HTMLElement, state: ViewState): void {
    root.querySelectorAll<HTMLDetailsElement>('details[data-cell]').forEach((details) => {
        const key = details.dataset.cell ?? '';
        const scrollTop = state.open.get(key);
        if (undefined !== scrollTop) {
            details.open = true;
            const pre = details.querySelector('pre');
            if (null !== pre) {
                pre.scrollTop = scrollTop;
            }
        }
        if (key === state.focused) {
            details.querySelector('summary')?.focus({ preventScroll: true });
        }
    });
    const scroller = root.querySelector('.result-matrix-scroll');
    if (null !== scroller) {
        scroller.scrollLeft = state.scrollLeft;
    }
}

/**
 * Polls the progress URL of a running version and swaps in the server-rendered HTML; returns a stop function.
 * A failed request is retried; after MAX_FAILURES in a row the notice node next to the root tells the user to reload.
 */
export function startRunProgress(root: HTMLElement, fetchFn: typeof fetch = fetch): () => void {
    const url = root.dataset.progressUrl;
    const parent = root.parentElement;
    let stopped = 'running' !== root.dataset.status || undefined === url;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let failures = 0;
    let lastHtml: string | null = null;
    let announced = false;

    const announce = (progress: Progress): void => {
        const status = parent?.querySelector('[data-run-progress-status]');
        if (announced || !status || ('running' === progress.status && progress.done !== progress.total)) {
            return;
        }
        announced = true;
        status.textContent = `Run finished: ${progress.done} of ${progress.total} cells done.`;
    };

    const fail = (): void => {
        if (++failures < MAX_FAILURES) {
            schedule();

            return;
        }
        const notice = parent?.querySelector<HTMLElement>('[data-run-progress-notice]');
        if (notice) {
            notice.textContent = STOPPED_NOTICE;
            notice.hidden = false;
        }
    };

    const poll = async (): Promise<void> => {
        let progress: Progress;
        try {
            const response = await fetchFn(url as string, { headers: { Accept: 'application/json' } });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            progress = (await response.json()) as Progress;
            // a stale bundle or server answer without html must not end up as "undefined" in the page
            if ('string' !== typeof progress.html) {
                throw new Error('Answer without html');
            }
        } catch {
            if (!stopped) {
                fail();
            }

            return;
        }
        if (stopped) {
            return;
        }
        failures = 0;
        if (progress.html !== lastHtml) {
            const state = captureState(root);
            root.innerHTML = progress.html;
            lastHtml = progress.html;
            restoreState(root, state);
        }
        announce(progress);
        if ('running' === progress.status) {
            schedule();
        }
    };

    const schedule = (): void => {
        timer = setTimeout(() => void poll(), INTERVAL_MS);
    };

    if (!stopped) {
        schedule();
    }

    return () => {
        stopped = true;
        clearTimeout(timer);
    };
}

/** Component for the run and version pages: starts polling when the page was rendered while a version runs. */
export default class RunProgress {
    initialize(el: HTMLElement): void {
        startRunProgress(el);
    }
}
