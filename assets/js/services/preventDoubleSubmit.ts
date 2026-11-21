const RESUBMIT_BLOCK_MS = 5000;

/**
 * Blocks a second submit of the same form (double click, Enter plus click) for a few seconds, so a form stays usable
 * when the navigation was cancelled (e.g. "Stay" in the leave prompt). The lock is also cleared when the page is
 * restored from the back/forward cache. Returns a function that removes the listeners.
 */
export function preventDoubleSubmit(root: Document = document, view: Window = window): () => void {
    const onSubmit = (event: Event): void => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || event.defaultPrevented) {
            return;
        }

        if (RESUBMIT_BLOCK_MS > Date.now() - Number(form.dataset.submitting)) {
            event.preventDefault();

            return;
        }

        form.dataset.submitting = String(Date.now());
    };

    const onPageShow = (event: Event): void => {
        if (!(event as PageTransitionEvent).persisted) {
            return;
        }

        root.querySelectorAll<HTMLFormElement>('form[data-submitting]').forEach((form) => {
            delete form.dataset.submitting;
        });
    };

    root.addEventListener('submit', onSubmit);
    view.addEventListener('pageshow', onPageShow);

    return () => {
        root.removeEventListener('submit', onSubmit);
        view.removeEventListener('pageshow', onPageShow);
    };
}

export default {
    enable(): void {
        preventDoubleSubmit();
    },
};
