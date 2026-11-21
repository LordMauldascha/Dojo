const SMASH_FALLBACK_MS = 1200;

// Plays the brick-smash animation (CSS class "is-smashing") before the logout link is followed.
// Without script, with reduced motion or with a modified click the link simply works.
export function smashLogout(
    link: HTMLAnchorElement,
    win: Window = window,
    navigate: (url: string) => void = (url) => {
        win.location.href = url;
    },
): () => void {
    let timer: number | undefined;

    let followed = false;

    const go = (): void => {
        if (followed) {
            return;
        }

        followed = true;
        win.clearTimeout(timer);
        navigate(link.href);
    };

    // The brick halves are the ::before and ::after of the link; the label's own animation bubbles up and is ignored.
    const onAnimationEnd = (event: Event): void => {
        if (event.target === link) {
            go();
        }
    };

    const onClick = (event: MouseEvent): void => {
        if (
            event.defaultPrevented ||
            0 !== event.button ||
            event.metaKey ||
            event.ctrlKey ||
            event.shiftKey ||
            event.altKey
        ) {
            return;
        }

        if (link.classList.contains('is-smashing')) {
            event.preventDefault();

            return;
        }

        if (true === win.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
            return;
        }

        event.preventDefault();
        link.classList.add('is-smashing');
        link.addEventListener('animationend', onAnimationEnd);
        timer = win.setTimeout(go, SMASH_FALLBACK_MS);
    };

    // Coming back with the back button must not show a smashed brick.
    const onPageShow = (): void => {
        link.classList.remove('is-smashing');
        link.removeEventListener('animationend', onAnimationEnd);
        followed = false;
    };

    link.addEventListener('click', onClick);
    win.addEventListener('pageshow', onPageShow);

    return () => {
        win.clearTimeout(timer);
        link.removeEventListener('click', onClick);
        link.removeEventListener('animationend', onAnimationEnd);
        win.removeEventListener('pageshow', onPageShow);
    };
}

export default class SmashLogout {
    initialize(el: HTMLAnchorElement): void {
        smashLogout(el);
    }
}
