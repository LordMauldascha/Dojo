import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { RUN_PROGRESS_EVENT, startRunProgress } from '../../components/runProgress';

function root(status: string): HTMLElement {
    const el = document.createElement('div');
    el.dataset.progressUrl = '/runs/x/versions/1/progress';
    el.dataset.status = status;
    el.innerHTML = '<p>initial</p>';

    return el;
}

/** Root inside the page structure of the version page: live region and notice are siblings, outside the swapped container. */
function page(status: string): { el: HTMLElement; notice: HTMLElement; status: HTMLElement } {
    const parent = document.createElement('div');
    const live = document.createElement('p');
    live.dataset.runProgressStatus = '';
    const notice = document.createElement('p');
    notice.dataset.runProgressNotice = '';
    notice.hidden = true;
    const el = root(status);
    parent.append(live, notice, el);

    return { el, notice, status: live };
}

function matrix(open: boolean): string {
    return `<div class="result-matrix-scroll"><table><tbody><tr><td><details data-cell="1|a:b:-"${open ? ' open' : ''}><summary>80 %</summary><pre>out</pre></details></td></tr></tbody></table></div>`;
}

function reply(status: string, html = `<p>${status}</p>`): Response {
    return new Response(JSON.stringify({ status, done: 1, total: 2, html }), {
        headers: { 'Content-Type': 'application/json' },
    });
}

describe('startRunProgress', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('does not poll when the page loads with a finished status', async () => {
        const fetchFn = vi.fn();

        startRunProgress(root('completed'), fetchFn);
        await vi.advanceTimersByTimeAsync(10000);

        expect(fetchFn).not.toHaveBeenCalled();
    });

    it('announces every answer on the document so the comparison charts can follow', async () => {
        const seen: unknown[] = [];
        const listener = (event: Event): void => void seen.push((event as CustomEvent).detail.status);
        document.addEventListener(RUN_PROGRESS_EVENT, listener);
        const fetchFn = vi
            .fn<typeof fetch>()
            .mockResolvedValueOnce(reply('running'))
            .mockResolvedValueOnce(reply('completed'));

        startRunProgress(root('running'), fetchFn);
        await vi.advanceTimersByTimeAsync(6000);
        document.removeEventListener(RUN_PROGRESS_EVENT, listener);

        expect(seen).toEqual(['running', 'completed']);
    });

    it('polls every 3000 ms while running, replaces the content and stops on completed', async () => {
        const el = root('running');
        const fetchFn = vi
            .fn<typeof fetch>()
            .mockResolvedValueOnce(reply('running', '<p>one</p>'))
            .mockResolvedValueOnce(reply('completed', '<p>two</p>'));

        startRunProgress(el, fetchFn);
        await vi.advanceTimersByTimeAsync(2999);
        expect(fetchFn).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(1);
        expect(fetchFn).toHaveBeenCalledTimes(1);
        expect(fetchFn).toHaveBeenCalledWith('/runs/x/versions/1/progress', {
            headers: { Accept: 'application/json' },
        });
        expect(el.innerHTML).toBe('<p>one</p>');

        await vi.advanceTimersByTimeAsync(3000);
        expect(fetchFn).toHaveBeenCalledTimes(2);
        expect(el.innerHTML).toBe('<p>two</p>');

        await vi.advanceTimersByTimeAsync(30000);
        expect(fetchFn).toHaveBeenCalledTimes(2);
    });

    it('does not start a request while the previous one is pending', async () => {
        let resolve: (response: Response) => void = () => {};
        const fetchFn = vi.fn<typeof fetch>().mockReturnValue(new Promise<Response>((r) => (resolve = r)));

        startRunProgress(root('running'), fetchFn);
        await vi.advanceTimersByTimeAsync(12000);
        expect(fetchFn).toHaveBeenCalledTimes(1);

        resolve(reply('completed'));
        await vi.advanceTimersByTimeAsync(0);
        await vi.advanceTimersByTimeAsync(12000);
        expect(fetchFn).toHaveBeenCalledTimes(1);
    });

    it('retries a failed request and stops after the third failure in a row with a notice outside the container', async () => {
        const { el, notice } = page('running');
        const fetchFn = vi.fn<typeof fetch>().mockRejectedValue(new Error('offline'));

        startRunProgress(el, fetchFn);
        await vi.advanceTimersByTimeAsync(3000);
        expect(fetchFn).toHaveBeenCalledTimes(1);
        expect(notice.hidden).toBe(true);
        await vi.advanceTimersByTimeAsync(3000);
        expect(fetchFn).toHaveBeenCalledTimes(2);
        expect(notice.hidden).toBe(true);
        await vi.advanceTimersByTimeAsync(3000);
        expect(fetchFn).toHaveBeenCalledTimes(3);

        expect(notice.hidden).toBe(false);
        expect(notice.textContent).toBe('Live update stopped. Reload the page to see the latest state.');
        expect(el.innerHTML).toBe('<p>initial</p>');
        await vi.advanceTimersByTimeAsync(30000);
        expect(fetchFn).toHaveBeenCalledTimes(3);
    });

    it('counts non-ok and non-JSON answers as failures', async () => {
        const { el, notice } = page('running');
        const fetchFn = vi
            .fn<typeof fetch>()
            .mockResolvedValueOnce(new Response('nope', { status: 500 }))
            .mockResolvedValueOnce(new Response('<html>', { status: 200 }))
            .mockResolvedValueOnce(new Response('nope', { status: 502 }));

        startRunProgress(el, fetchFn);
        await vi.advanceTimersByTimeAsync(30000);

        expect(fetchFn).toHaveBeenCalledTimes(3);
        expect(notice.hidden).toBe(false);
        expect(el.innerHTML).toBe('<p>initial</p>');
    });

    it('never writes "undefined": an answer without an html string counts as a failure', async () => {
        const { el, notice } = page('running');
        const fetchFn = vi
            .fn<typeof fetch>()
            .mockImplementation(async () =>
                Response.json({ status: 'completed', done: 1, total: 1, matrixHtml: '<p>old key</p>' }),
            );

        startRunProgress(el, fetchFn);
        await vi.advanceTimersByTimeAsync(30000);

        expect(fetchFn).toHaveBeenCalledTimes(3);
        expect(el.innerHTML).toBe('<p>initial</p>');
        expect(notice.hidden).toBe(false);
    });

    it('resets the failure counter on a success', async () => {
        const { el, notice } = page('running');
        const fetchFn = vi
            .fn<typeof fetch>()
            .mockRejectedValueOnce(new Error('offline'))
            .mockRejectedValueOnce(new Error('offline'))
            .mockResolvedValueOnce(reply('running'))
            .mockRejectedValueOnce(new Error('offline'))
            .mockRejectedValueOnce(new Error('offline'))
            .mockResolvedValueOnce(reply('completed', '<p>done</p>'));

        startRunProgress(el, fetchFn);
        await vi.advanceTimersByTimeAsync(30000);

        expect(fetchFn).toHaveBeenCalledTimes(6);
        expect(notice.hidden).toBe(true);
        expect(el.innerHTML).toBe('<p>done</p>');
    });

    it('keeps open details, the focused summary and the scroll position across a swap', async () => {
        const { el } = page('running');
        document.body.append(el.parentElement as HTMLElement);
        el.innerHTML = matrix(true);
        const scroller = el.querySelector('.result-matrix-scroll') as HTMLElement;
        scroller.scrollLeft = 40;
        (el.querySelector('summary') as HTMLElement).focus();
        const fetchFn = vi.fn<typeof fetch>().mockResolvedValue(reply('running', matrix(false)));

        startRunProgress(el, fetchFn);
        await vi.advanceTimersByTimeAsync(3000);

        const details = el.querySelector('details[data-cell]') as HTMLDetailsElement;
        expect(details.open).toBe(true);
        expect(document.activeElement).toBe(details.querySelector('summary'));
        expect(el.querySelectorAll('details[open]')).toHaveLength(1);
        expect((el.querySelector('.result-matrix-scroll') as HTMLElement).scrollLeft).toBe(40);
        el.parentElement?.remove();
    });

    it('does not replace the DOM when the HTML is identical to the last applied one', async () => {
        const { el } = page('running');
        const fetchFn = vi.fn<typeof fetch>().mockResolvedValue(reply('running', matrix(false)));

        startRunProgress(el, fetchFn);
        await vi.advanceTimersByTimeAsync(3000);
        const first = el.querySelector('details');
        await vi.advanceTimersByTimeAsync(3000);

        expect(fetchFn).toHaveBeenCalledTimes(2);
        expect(el.querySelector('details')).toBe(first);
    });

    it('announces the finished run once, not on every poll', async () => {
        const { el, status } = page('running');
        const fetchFn = vi
            .fn<typeof fetch>()
            .mockResolvedValueOnce(reply('running'))
            .mockResolvedValueOnce(
                new Response(JSON.stringify({ status: 'completed', done: 4, total: 4, html: '<p>x</p>' })),
            );

        startRunProgress(el, fetchFn);
        await vi.advanceTimersByTimeAsync(3000);
        expect(status.textContent).toBe('');
        await vi.advanceTimersByTimeAsync(3000);

        expect(status.textContent).toBe('Run finished: 4 of 4 cells done.');
    });

    it('honors the returned stop function', async () => {
        const fetchFn = vi.fn<typeof fetch>().mockResolvedValue(reply('running'));

        const stop = startRunProgress(root('running'), fetchFn);
        await vi.advanceTimersByTimeAsync(3000);
        expect(fetchFn).toHaveBeenCalledTimes(1);

        stop();
        await vi.advanceTimersByTimeAsync(30000);
        expect(fetchFn).toHaveBeenCalledTimes(1);
    });

    it('does not touch the content when stopped while a request is pending', async () => {
        const el = root('running');
        let resolve: (response: Response) => void = () => {};
        const fetchFn = vi.fn<typeof fetch>().mockReturnValue(new Promise<Response>((r) => (resolve = r)));

        const stop = startRunProgress(el, fetchFn);
        await vi.advanceTimersByTimeAsync(3000);
        stop();
        resolve(reply('running', '<p>late</p>'));
        await vi.advanceTimersByTimeAsync(0);

        expect(el.innerHTML).toBe('<p>initial</p>');
    });
});
