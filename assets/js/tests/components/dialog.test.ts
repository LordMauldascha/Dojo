import { describe, expect, it, vi } from 'vitest';
import Dialog from '../../components/dialog';

function dialog(): HTMLDialogElement {
    const el = document.createElement('dialog');
    el.showModal = vi.fn();

    return el;
}

describe('Dialog', () => {
    it('opens the dialog as a modal when showOnLoad is set', () => {
        const el = dialog();

        new Dialog().initialize(el, { showOnLoad: true });

        expect(el.showModal).toHaveBeenCalledOnce();
    });

    it('leaves the dialog closed without showOnLoad', () => {
        const el = dialog();

        new Dialog().initialize(el, { showOnLoad: false });
        new Dialog().initialize(el, {});

        expect(el.showModal).not.toHaveBeenCalled();
    });

    it('does not open a dialog that is already open', () => {
        const el = dialog();
        el.open = true;

        new Dialog().initialize(el, { showOnLoad: true });

        expect(el.showModal).not.toHaveBeenCalled();
    });
});
