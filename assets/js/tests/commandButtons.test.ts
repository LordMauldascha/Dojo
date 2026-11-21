import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { supportCommandButtons } from '../commandButtons';

describe('supportCommandButtons', () => {
    let dialog: HTMLDialogElement;
    let stop: () => void;

    beforeEach(() => {
        document.body.innerHTML = `
            <button id="open" type="button" command="show-modal" commandfor="confirm">Delete</button>
            <dialog id="confirm"><button id="cancel" type="button" command="close" commandfor="confirm">Cancel</button></dialog>
            <button id="plain" type="button">Other</button>`;
        dialog = document.querySelector('dialog') as HTMLDialogElement;
        dialog.showModal = vi.fn(() => dialog.setAttribute('open', ''));
        dialog.close = vi.fn(() => dialog.removeAttribute('open'));
        stop = supportCommandButtons(document);
    });

    afterEach(() => {
        stop();
        vi.unstubAllGlobals();
    });

    const click = (id: string): void => document.getElementById(id)?.click();

    it('opens the dialog as a modal and closes it again', () => {
        click('open');
        expect(dialog.showModal).toHaveBeenCalledOnce();

        click('cancel');
        expect(dialog.close).toHaveBeenCalledOnce();
    });

    it('ignores buttons without a command and unknown targets', () => {
        click('plain');
        document.getElementById('open')?.setAttribute('commandfor', 'missing');
        click('open');

        expect(dialog.showModal).not.toHaveBeenCalled();
    });

    it('does nothing where the browser supports commands itself', () => {
        stop();
        Object.defineProperty(HTMLButtonElement.prototype, 'commandForElement', { value: null, configurable: true });
        stop = supportCommandButtons(document);

        click('open');

        expect(dialog.showModal).not.toHaveBeenCalled();
        Reflect.deleteProperty(HTMLButtonElement.prototype, 'commandForElement');
    });
});
