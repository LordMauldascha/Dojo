import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { enablePasswordToggles } from '../passwordToggle';

describe('enablePasswordToggles', () => {
    let stop: () => void;

    beforeEach(() => {
        document.body.innerHTML = `
            <form id="form">
                <input type="text" id="name" name="name">
                <input type="password" id="first" name="first">
                <input type="password" name="second">
                <dialog id="dialog"><input type="password" id="inside" name="inside"></dialog>
                <button type="submit">Save</button>
            </form>`;
        stop = enablePasswordToggles(document, window);
    });

    afterEach(() => {
        stop();
    });

    const input = (id: string): HTMLInputElement => document.getElementById(id) as HTMLInputElement;
    const toggleOf = (field: Element): HTMLButtonElement =>
        field.parentElement?.querySelector('.password-toggle') as HTMLButtonElement;

    it('adds a button to every password field and leaves other fields alone', () => {
        expect(document.querySelectorAll('.password-toggle')).toHaveLength(3);
        expect(input('name').closest('.password-field')).toBeNull();
        expect(input('first').closest('.password-field')).not.toBeNull();
    });

    it('describes the button for assistive technology', () => {
        const button = toggleOf(input('first'));

        expect(button.type).toBe('button');
        expect(button.getAttribute('aria-label')).toBe('Show password');
        expect(button.getAttribute('aria-pressed')).toBe('false');
        expect(button.getAttribute('aria-controls')).toBe('first');
        expect(button.querySelector('svg')?.getAttribute('aria-hidden')).toBe('true');
    });

    it('shows and hides the password on click', () => {
        const button = toggleOf(input('first'));

        button.click();
        expect(input('first').type).toBe('text');
        expect(button.getAttribute('aria-pressed')).toBe('true');
        expect(button.getAttribute('aria-label')).toBe('Hide password');
        expect(button.querySelectorAll('path')).toHaveLength(2);

        button.click();
        expect(input('first').type).toBe('password');
        expect(button.getAttribute('aria-pressed')).toBe('false');
        expect(button.querySelectorAll('path')).toHaveLength(1);
    });

    it('marks the field so the unsaved-changes guard ignores it', () => {
        expect(input('first').dataset.passwordField).toBe('');
    });

    it('does not submit the form', () => {
        let submitted = false;
        document.getElementById('form')?.addEventListener('submit', (event) => {
            submitted = true;
            event.preventDefault();
        });

        toggleOf(input('first')).click();

        expect(submitted).toBe(false);
    });

    it('hides the field again when its dialog closes', () => {
        toggleOf(input('inside')).click();
        toggleOf(input('first')).click();

        document.getElementById('dialog')?.dispatchEvent(new Event('close'));

        expect(input('inside').type).toBe('password');
        expect(input('first').type).toBe('text');
    });

    it('hides all fields when the page returns from the back/forward cache', () => {
        toggleOf(input('first')).click();

        const event = new Event('pageshow');
        Object.defineProperty(event, 'persisted', { value: true });
        window.dispatchEvent(event);

        expect(input('first').type).toBe('password');
    });

    it('stops listening when cleaned up', () => {
        toggleOf(input('inside')).click();
        stop();

        document.getElementById('dialog')?.dispatchEvent(new Event('close'));

        expect(input('inside').type).toBe('text');
    });
});
