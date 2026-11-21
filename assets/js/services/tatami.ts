export const STORAGE_KEY = 'dojo-tatami';
const FALLBACK_MS = 2500;

export function prefersReducedMotion(win: Window): boolean {
    return true === win.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
}

// Calls done once the last mat has finished the named animation, or after a fallback delay.
export function afterLastMat(root: Document, win: Window, animationName: string, done: () => void): void {
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
