import { STORAGE_KEY, afterLastMat, prefersReducedMotion } from '../services/tatami';

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
export function rollInOnLogin(
    form: HTMLFormElement,
    root: Document = document,
    win: Window = window,
    deps?: Partial<LoginDeps>,
): () => void {
    if (null === root.querySelector('.tatami')) {
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

export default class TatamiLogin {
    initialize(el: HTMLFormElement): void {
        rollInOnLogin(el);
    }
}
