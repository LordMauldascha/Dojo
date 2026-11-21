const STORAGE_KEY = 'dojo-tatami';
const FALLBACK_MS = 2500;

function prefersReducedMotion(win: Window): boolean {
    return true === win.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
}

// Calls done once the last mat has finished the named animation, or after a fallback delay.
function afterLastMat(root: Document, win: Window, animationName: string, done: () => void): void {
    const lastSheet = root.querySelector('.tatami-mat:last-child .tatami-sheet');
    let finished = false;
    let timer: number | undefined;

    const finish = (): void => {
        if (finished) {
            return;
        }

        finished = true;
        win.clearTimeout(timer);
        root.removeEventListener('animationend', onEnd);
        done();
    };

    const onEnd = (event: Event): void => {
        if (event.target === lastSheet && animationName === (event as AnimationEvent).animationName) {
            finish();
        }
    };

    root.addEventListener('animationend', onEnd);
    timer = win.setTimeout(finish, FALLBACK_MS);
}

export interface LoginResponse {
    url: string;
    text(): Promise<string>;
}

interface LoginDeps {
    // sends the login in the background; the final URL after the server's redirects tells success from failure
    post: (form: HTMLFormElement) => Promise<LoginResponse>;
    // the normal, full-page submit (fallback when the background request fails)
    send: (form: HTMLFormElement) => void;
    // opens the page the login led to
    go: (url: string) => void;
}

/**
 * On the login form: the credentials are sent in the background first. Only when they are accepted do three tatami
 * mats roll down over the page (class "tatami-in" on <html>) before the first page is opened; the next page picks the
 * cover up (inline script in base.html.twig) and calls rollAwayAfterLoad. Wrong credentials show the server's error
 * message in place and start no animation. Invalid forms and reduced motion keep the normal submit.
 */
export function rollInOnLogin(root: Document = document, win: Window = window, deps?: Partial<LoginDeps>): () => void {
    const form = root.querySelector<HTMLFormElement>('form.login-form');
    if (null === form || null === root.querySelector('.tatami')) {
        return () => undefined;
    }

    const post =
        deps?.post ??
        ((target: HTMLFormElement) =>
            win.fetch(target.action, {
                method: 'POST',
                body: new FormData(target),
                credentials: 'same-origin',
                redirect: 'follow',
            }));
    const send = deps?.send ?? ((target: HTMLFormElement) => HTMLFormElement.prototype.submit.call(target));
    const go =
        deps?.go ??
        ((url: string) => {
            win.location.assign(url);
        });

    let busy = false;
    const button = form.querySelector<HTMLButtonElement>('button[type="submit"]');

    const setBusy = (value: boolean): void => {
        busy = value;
        form.toggleAttribute('aria-busy', value);
        if (null !== button) {
            button.disabled = value;
        }
    };

    // Shows the error message of the server's login page and takes over its fresh CSRF token.
    const showError = async (response: LoginResponse): Promise<void> => {
        const page = new DOMParser().parseFromString(await response.text(), 'text/html');
        const message = page.querySelector('.login-panel .flash-error')?.textContent?.trim() || 'Invalid credentials.';
        let error = root.querySelector('.login-panel .flash-error');
        if (null === error) {
            error = root.createElement('p');
            error.className = 'flash flash-error';
            error.setAttribute('role', 'alert');
            form.before(error);
        }

        error.textContent = message;

        const token = page.querySelector<HTMLInputElement>('input[name="_csrf_token"]');
        const field = form.querySelector<HTMLInputElement>('input[name="_csrf_token"]');
        if (null !== token && null !== field) {
            field.value = token.value;
        }

        const password = form.querySelector<HTMLInputElement>('input[type="password"]');
        if (null !== password) {
            password.value = '';
            password.focus();
        }
    };

    const login = async (): Promise<void> => {
        let response: LoginResponse;
        try {
            response = await post(form);
        } catch {
            // the background request failed (network, blocked): let the browser do the normal submit
            send(form);

            return;
        }

        if (new URL(response.url).pathname === new URL(form.action).pathname) {
            await showError(response);
            setBusy(false);

            return;
        }

        try {
            win.sessionStorage.setItem(STORAGE_KEY, String(Date.now()));
        } catch {
            // without storage the next page simply shows no cover
        }

        root.documentElement.classList.add('tatami-in');
        afterLastMat(root, win, 'tatami-sheet-in', () => go(response.url));
    };

    const onSubmit = (event: Event): void => {
        if (event.defaultPrevented || !form.checkValidity() || prefersReducedMotion(win)) {
            return;
        }

        event.preventDefault();
        if (busy) {
            return;
        }

        setBusy(true);
        void login();
    };

    // A page restored from the back/forward cache must not stay covered.
    const onPageShow = (event: Event): void => {
        if ((event as PageTransitionEvent).persisted) {
            setBusy(false);
            root.documentElement.classList.remove('tatami-in', 'tatami-cover', 'tatami-away');
        }
    };

    form.addEventListener('submit', onSubmit);
    win.addEventListener('pageshow', onPageShow);

    return () => {
        form.removeEventListener('submit', onSubmit);
        win.removeEventListener('pageshow', onPageShow);
    };
}

/**
 * On the page after the login: when it has loaded, the covering mats roll away downward. The cover class is set early
 * by an inline script in base.html.twig; without it nothing happens.
 */
export function rollAwayAfterLoad(root: Document = document, win: Window = window): void {
    const html = root.documentElement;
    if (!html.classList.contains('tatami-cover')) {
        return;
    }

    try {
        win.sessionStorage.removeItem(STORAGE_KEY);
    } catch {
        // nothing to clean up
    }

    const start = (): void => {
        // two frames, so the covered page has been painted before the mats move
        win.requestAnimationFrame(() =>
            win.requestAnimationFrame(() => {
                html.classList.add('tatami-away');
                afterLastMat(root, win, 'tatami-sheet-away', () => {
                    html.classList.remove('tatami-cover', 'tatami-away');
                });
            }),
        );
    };

    if ('complete' === root.readyState) {
        start();
    } else {
        win.addEventListener('load', start, { once: true });
    }
}
