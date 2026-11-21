/** Opens every dialog marked data-show-on-load as a modal, e.g. a form dialog that must show its errors again. */
export function showDialogsOnLoad(root: Document = document): void {
    root.querySelectorAll<HTMLDialogElement>('dialog[data-show-on-load]').forEach((dialog) => {
        if (!dialog.open) {
            dialog.showModal();
        }
    });
}

/**
 * Fallback for browsers without Invoker Commands: makes button[command][commandfor] open (show-modal) and close
 * (close) a <dialog>. Does nothing where the browser handles these buttons itself. Returns a function that removes
 * the listener.
 */
export function supportCommandButtons(root: Document = document): () => void {
    if ('commandForElement' in HTMLButtonElement.prototype) {
        return () => {};
    }

    const onClick = (event: Event): void => {
        const target = event.target;
        const button = target instanceof Element ? target.closest('button[command][commandfor]') : null;
        const dialog = button ? root.getElementById(button.getAttribute('commandfor') ?? '') : null;
        if (!(dialog instanceof HTMLDialogElement)) {
            return;
        }

        const command = button?.getAttribute('command');
        if ('show-modal' === command && !dialog.open) {
            dialog.showModal();
        } else if ('close' === command) {
            dialog.close();
        }
    };

    root.addEventListener('click', onClick);

    return () => root.removeEventListener('click', onClick);
}
