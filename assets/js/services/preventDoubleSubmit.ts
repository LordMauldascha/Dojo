/**
 * Blocks a second submit of the same form (double click, Enter plus click) until the page is left or restored
 * from the back/forward cache. Returns a function that removes the listeners.
 */
export function preventDoubleSubmit(root: Document = document, view: Window = window): () => void {
    const onSubmit = (event: Event): void => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || event.defaultPrevented) {
            return;
        }

        if ('true' === form.dataset.submitting) {
            event.preventDefault();

            return;
        }

        form.dataset.submitting = 'true';
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
