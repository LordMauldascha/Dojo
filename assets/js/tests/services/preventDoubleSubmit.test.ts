import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { preventDoubleSubmit } from '../../services/preventDoubleSubmit';

function submit(form: HTMLFormElement): Event {
    const event = new Event('submit', { bubbles: true, cancelable: true });
    form.dispatchEvent(event);

    return event;
}

describe('preventDoubleSubmit', () => {
    let form: HTMLFormElement;
    let stop: () => void;

    beforeEach(() => {
        document.body.innerHTML = '<form><button type="submit">Save</button></form>';
        form = document.querySelector('form') as HTMLFormElement;
        stop = preventDoubleSubmit(document, window);
    });

    afterEach(() => {
        stop();
    });

    it('lets the first submit through and blocks the second one', () => {
        expect(submit(form).defaultPrevented).toBe(false);
        expect(submit(form).defaultPrevented).toBe(true);
    });

    it('locks each form on its own', () => {
        document.body.insertAdjacentHTML('beforeend', '<form id="other"></form>');
        const other = document.querySelector('#other') as HTMLFormElement;

        submit(form);

        expect(submit(other).defaultPrevented).toBe(false);
    });

    it('does not lock a form whose submit was cancelled by another handler', () => {
        const cancel = (event: Event): void => event.preventDefault();
        form.addEventListener('submit', cancel, { once: true });
        submit(form);

        expect(submit(form).defaultPrevented).toBe(false);
    });

    it('unlocks forms when the page is restored from the back/forward cache', () => {
        submit(form);

        const restored = new Event('pageshow');
        Object.defineProperty(restored, 'persisted', { value: true });
        window.dispatchEvent(restored);

        expect(submit(form).defaultPrevented).toBe(false);
    });

    it('keeps the lock on a normal pageshow', () => {
        submit(form);

        const shown = new Event('pageshow');
        Object.defineProperty(shown, 'persisted', { value: false });
        window.dispatchEvent(shown);

        expect(submit(form).defaultPrevented).toBe(true);
    });

    it('stops listening after cleanup', () => {
        stop();
        submit(form);

        expect(submit(form).defaultPrevented).toBe(false);
    });
});
