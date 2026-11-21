import { isPlainLeftClick } from '../services/isPlainLeftClick';

const FORMS = 'form[name][method="post"]';

function isChanged(field: Element): boolean {
    if (field instanceof HTMLInputElement) {
        // Password managers fill these without any edit, so they would always look changed.
        if (['hidden', 'password', 'file', 'submit', 'button'].includes(field.type)) {
            return false;
        }

        // A revealed password field has type "text" (see services/passwordToggle.ts).
        if ('passwordField' in field.dataset) {
            return false;
        }

        return 'checkbox' === field.type || 'radio' === field.type
            ? field.checked !== field.defaultChecked
            : field.value !== field.defaultValue;
    }

    if (field instanceof HTMLTextAreaElement) {
        return field.value !== field.defaultValue;
    }

    if (field instanceof HTMLSelectElement) {
        const options = Array.from(field.options);
        // A single select without a preselected option shows its first one.
        const firstIsDefault = !field.multiple && !options.some((option) => option.defaultSelected);

        return options.some(
            (option, index) => option.selected !== (option.defaultSelected || (firstIsDefault && 0 === index)),
        );
    }

    return false;
}

/**
 * Warns before unsaved form changes are lost. A click on an in-app link opens the dialog (Stay or Discard);
 * reload, closing the tab, back and other forms get the browser's own prompt. Submitting a form never warns about its
 * own fields. Returns a function that removes the listeners.
 */
export function warnOnUnsavedChanges(
    dialog: HTMLDialogElement,
    root: Document = document,
    win: Window = window,
): () => void {
    let submitting: HTMLFormElement | null = null;
    let leaving = false;
    let pending: HTMLAnchorElement | null = null;

    const isDirty = (): boolean =>
        Array.from(root.querySelectorAll<HTMLFormElement>(FORMS))
            .filter((form) => form !== submitting)
            .some((form) => Array.from(form.elements).some(isChanged));

    const isInApp = (link: HTMLAnchorElement): boolean => {
        const samePage = link.pathname === win.location.pathname && link.search === win.location.search;

        return (
            link.origin === win.location.origin &&
            ['', '_self'].includes(link.target) &&
            !link.hasAttribute('download') &&
            !(samePage && '' !== link.hash)
        );
    };

    const onClick = (event: MouseEvent): void => {
        if (leaving || event.defaultPrevented || !isPlainLeftClick(event)) {
            return;
        }

        const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
        if (!(link instanceof HTMLAnchorElement) || !isInApp(link) || !isDirty()) {
            return;
        }

        // Capture phase and stopImmediatePropagation, so the logout brick does not start before the user decided.
        event.preventDefault();
        event.stopImmediatePropagation();
        pending = link;
        dialog.showModal();
    };

    const onDialogClick = (event: Event): void => {
        const target = event.target;
        if (!(target instanceof Element) || null === target.closest('[data-unsaved-leave]')) {
            return;
        }

        const link = pending;
        pending = null;
        leaving = true;
        dialog.close();
        link?.click();
    };

    const onSubmit = (event: Event): void => {
        if (event.target instanceof HTMLFormElement && !event.defaultPrevented) {
            submitting = event.target;
        }
    };

    const onBeforeUnload = (event: BeforeUnloadEvent): void => {
        if (!leaving && isDirty()) {
            event.preventDefault();
        }
    };

    const onPageShow = (event: Event): void => {
        if (!(event as PageTransitionEvent).persisted) {
            return;
        }

        submitting = null;
        leaving = false;
        pending = null;
        dialog.close();
    };

    root.addEventListener('click', onClick, true);
    root.addEventListener('submit', onSubmit);
    dialog.addEventListener('click', onDialogClick);
    win.addEventListener('beforeunload', onBeforeUnload);
    win.addEventListener('pageshow', onPageShow);

    return () => {
        root.removeEventListener('click', onClick, true);
        root.removeEventListener('submit', onSubmit);
        dialog.removeEventListener('click', onDialogClick);
        win.removeEventListener('beforeunload', onBeforeUnload);
        win.removeEventListener('pageshow', onPageShow);
    };
}

export default class UnsavedChanges {
    initialize(el: HTMLDialogElement): void {
        warnOnUnsavedChanges(el);
    }
}
