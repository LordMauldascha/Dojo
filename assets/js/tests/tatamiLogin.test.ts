import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { rollAwayAfterLoad, rollInOnLogin } from '../tatamiLogin';

const MATS = `
    <div class="tatami">
        <span class="tatami-mat"><span class="tatami-sheet"></span></span>
        <span class="tatami-mat"><span class="tatami-sheet"></span></span>
        <span class="tatami-mat"><span class="tatami-sheet"></span></span>
    </div>`;

function lastSheet(): Element {
    return document.querySelector('.tatami-mat:last-child .tatami-sheet') as Element;
}

function endAnimation(target: Element, animationName: string): void {
    const event = new Event('animationend', { bubbles: true });
    Object.defineProperty(event, 'animationName', { value: animationName });
    target.dispatchEvent(event);
}

function submit(form: HTMLFormElement): Event {
    const event = new Event('submit', { bubbles: true, cancelable: true });
    form.dispatchEvent(event);

    return event;
}

const FAILED_PAGE =
    '<main class="login-panel"><p class="flash flash-error">Invalid credentials.</p>' +
    '<form><input type="hidden" name="_csrf_token" value="fresh"></form></main>';

function response(url: string, html = ''): { url: string; text: () => Promise<string> } {
    return { url, text: () => Promise.resolve(html) };
}

describe('rollInOnLogin', () => {
    let form: HTMLFormElement;
    let post: ReturnType<
        typeof vi.fn<(form: HTMLFormElement) => Promise<{ url: string; text: () => Promise<string> }>>
    >;
    let send: ReturnType<typeof vi.fn<(form: HTMLFormElement) => void>>;
    let go: ReturnType<typeof vi.fn<(url: string) => void>>;
    let stop: () => void;

    beforeEach(() => {
        document.documentElement.className = '';
        sessionStorage.clear();
        document.body.innerHTML =
            `${MATS}<div class="login-panel"><form class="login-form" action="/login" method="post">` +
            '<input name="u" required value="admin"><input type="password" value="wrong">' +
            '<input type="hidden" name="_csrf_token" value="old"><button type="submit">Sign in</button></form></div>';
        form = document.querySelector('form') as HTMLFormElement;
        post = vi.fn();
        send = vi.fn<(form: HTMLFormElement) => void>();
        go = vi.fn<(url: string) => void>();
        stop = rollInOnLogin(document, window, { post, send, go });
    });

    afterEach(() => {
        stop();
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    it('rolls the mats down only after the server accepted the login, then opens the first page', async () => {
        post.mockResolvedValue(response('http://localhost:3000/projects'));

        const event = submit(form);
        expect(event.defaultPrevented).toBe(true);
        expect(document.documentElement.classList.contains('tatami-in')).toBe(false);

        await vi.waitFor(() => expect(document.documentElement.classList.contains('tatami-in')).toBe(true));
        expect(sessionStorage.getItem('dojo-tatami')).not.toBeNull();
        expect(go).not.toHaveBeenCalled();

        endAnimation(document.querySelector('.tatami-sheet') as Element, 'tatami-sheet-in');
        expect(go).not.toHaveBeenCalled();

        endAnimation(lastSheet(), 'tatami-sheet-in');
        expect(go).toHaveBeenCalledOnce();
        expect(go).toHaveBeenCalledWith('http://localhost:3000/projects');
        expect(send).not.toHaveBeenCalled();
    });

    it('starts no animation for wrong credentials and shows the server error in place', async () => {
        post.mockResolvedValue(response('http://localhost:3000/login', FAILED_PAGE));

        submit(form);
        await vi.waitFor(() => expect(document.querySelector('.login-panel > .flash-error')).not.toBeNull());

        expect(document.documentElement.classList.contains('tatami-in')).toBe(false);
        expect(sessionStorage.getItem('dojo-tatami')).toBeNull();
        expect(document.querySelector('.login-panel > .flash-error')?.textContent).toBe('Invalid credentials.');
        expect((form.querySelector('[name="_csrf_token"]') as HTMLInputElement).value).toBe('fresh');
        expect((form.querySelector('input[type="password"]') as HTMLInputElement).value).toBe('');
        expect((form.querySelector('button') as HTMLButtonElement).disabled).toBe(false);
        expect(go).not.toHaveBeenCalled();
    });

    it('lets the user try again after wrong credentials', async () => {
        post.mockResolvedValue(response('http://localhost:3000/login', FAILED_PAGE));

        submit(form);
        await vi.waitFor(() => expect((form.querySelector('button') as HTMLButtonElement).disabled).toBe(false));
        submit(form);

        expect(post).toHaveBeenCalledTimes(2);
    });

    it('opens the page after a fallback delay when no animation event arrives', async () => {
        post.mockResolvedValue(response('http://localhost:3000/projects'));
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });

        submit(form);
        await vi.waitFor(() => expect(document.documentElement.classList.contains('tatami-in')).toBe(true));
        vi.advanceTimersByTime(2500);

        expect(go).toHaveBeenCalledOnce();
    });

    it('sends the login only once while the server is still answering', () => {
        post.mockReturnValue(new Promise(() => undefined));

        submit(form);
        expect(submit(form).defaultPrevented).toBe(true);

        expect(post).toHaveBeenCalledOnce();
    });

    it('falls back to the normal submit when the background request fails', async () => {
        post.mockRejectedValue(new Error('offline'));

        submit(form);
        await vi.waitFor(() => expect(send).toHaveBeenCalledWith(form));

        expect(document.documentElement.classList.contains('tatami-in')).toBe(false);
    });

    it('leaves an invalid form to the browser', () => {
        (form.querySelector('input') as HTMLInputElement).value = '';

        const event = submit(form);

        expect(event.defaultPrevented).toBe(false);
        expect(post).not.toHaveBeenCalled();
    });

    it('skips everything with reduced motion', () => {
        vi.stubGlobal('matchMedia', () => ({ matches: true }));

        const event = submit(form);

        expect(event.defaultPrevented).toBe(false);
        expect(post).not.toHaveBeenCalled();
    });

    it('removes the cover when the page is restored from the back/forward cache', async () => {
        post.mockResolvedValue(response('http://localhost:3000/projects'));
        submit(form);
        await vi.waitFor(() => expect(document.documentElement.classList.contains('tatami-in')).toBe(true));

        const restored = new Event('pageshow');
        Object.defineProperty(restored, 'persisted', { value: true });
        window.dispatchEvent(restored);

        expect(document.documentElement.classList.contains('tatami-in')).toBe(false);
        expect((form.querySelector('button') as HTMLButtonElement).disabled).toBe(false);
    });
});

describe('rollAwayAfterLoad', () => {
    beforeEach(() => {
        document.documentElement.className = '';
        sessionStorage.setItem('dojo-tatami', '1');
        document.body.innerHTML = MATS;
        vi.stubGlobal('requestAnimationFrame', (callback: FrameRequestCallback) => {
            callback(0);

            return 0;
        });
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('does nothing without the cover class', () => {
        rollAwayAfterLoad(document, window);

        expect(document.documentElement.classList.contains('tatami-away')).toBe(false);
        expect(sessionStorage.getItem('dojo-tatami')).toBe('1');
    });

    it('rolls the mats away once the page has loaded and removes the cover afterwards', () => {
        document.documentElement.classList.add('tatami-cover');
        Object.defineProperty(document, 'readyState', { value: 'loading', configurable: true });

        rollAwayAfterLoad(document, window);
        expect(document.documentElement.classList.contains('tatami-away')).toBe(false);
        expect(sessionStorage.getItem('dojo-tatami')).toBeNull();

        window.dispatchEvent(new Event('load'));
        expect(document.documentElement.classList.contains('tatami-away')).toBe(true);

        endAnimation(lastSheet(), 'tatami-sheet-away');
        expect(document.documentElement.classList.contains('tatami-cover')).toBe(false);
        expect(document.documentElement.classList.contains('tatami-away')).toBe(false);

        Object.defineProperty(document, 'readyState', { value: 'complete', configurable: true });
    });

    it('starts at once when the page is already loaded', () => {
        document.documentElement.classList.add('tatami-cover');
        Object.defineProperty(document, 'readyState', { value: 'complete', configurable: true });

        rollAwayAfterLoad(document, window);

        expect(document.documentElement.classList.contains('tatami-away')).toBe(true);
    });
});
