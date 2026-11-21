import { STORAGE_KEY, afterLastMat } from '../services/tatami';

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

export default class TatamiCover {
    initialize(): void {
        rollAwayAfterLoad();
    }
}
