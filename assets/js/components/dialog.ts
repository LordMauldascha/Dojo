/** Opens the dialog as a modal on load when showOnLoad is set, e.g. a form dialog that must show its errors again. */
export default class Dialog {
    initialize(el: HTMLDialogElement, options: { showOnLoad?: boolean }): void {
        if (true === options.showOnLoad && !el.open) {
            el.showModal();
        }
    }
}
