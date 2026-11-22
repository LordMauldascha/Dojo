import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
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
        vi.useRealTimers();
    });

    it('lets the first submit through and blocks the second one', () => {
        expect(submit(form).defaultPrevented).toBe(false);
        expect(submit(form).defaultPrevented).toBe(true);
    });

    it('blocks a resubmit inside the window and allows it after the window', () => {
        vi.useFakeTimers();
        submit(form);

        vi.advanceTimersByTime(4999);
        expect(submit(form).defaultPrevented).toBe(true);

        vi.advanceTimersByTime(1);
        expect(submit(form).defaultPrevented).toBe(false);
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

    it('disables the button of a form with a busy label until the page is restored', () => {
        form.dataset.busyLabel = 'Generating…';
        const button = form.querySelector('button') as HTMLButtonElement;

        submit(form);

        expect(button.disabled).toBe(true);
        expect(button.textContent).toBe('Generating…');

        window.dispatchEvent(Object.assign(new Event('pageshow'), { persisted: true }));

        expect(button.disabled).toBe(false);
        expect(button.textContent).toBe('Save');
    });
});
