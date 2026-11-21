import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { warnOnUnsavedChanges } from '../../components/unsavedChanges';

const PAGE = `
    <a id="away" href="/projects">Projects</a>
    <a id="hash" href="#content">Skip</a>
    <a id="blank" href="/projects" target="_blank">New tab</a>
    <a id="external" href="https://example.org/">Elsewhere</a>
    <form name="project" method="post" id="main">
        <input type="hidden" name="_token" value="abc">
        <input type="text" name="title" value="Security">
        <input type="password" name="secret" value="">
        <textarea name="description">Text</textarea>
        <input type="checkbox" name="active" checked>
        <select name="kind"><option value="a">A</option><option value="b">B</option></select>
        <button type="submit">Save</button>
    </form>
    <form name="other" method="post" id="other"><input type="text" name="note" value=""></form>
    <form method="get" id="search"><input type="search" name="q" value=""></form>
    <dialog id="unsaved-dialog">
        <button type="button" id="stay" command="close" commandfor="unsaved-dialog">Stay</button>
        <button type="button" id="leave" data-unsaved-leave>Discard changes</button>
    </dialog>`;

function field<T extends Element>(selector: string): T {
    return document.querySelector(selector) as T;
}

function click(selector: string, init: MouseEventInit = {}): MouseEvent {
    const event = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ...init });
    field(selector).dispatchEvent(event);

    return event;
}

function leave(): Event {
    const event = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(event);

    return event;
}

describe('warnOnUnsavedChanges', () => {
    let dialog: HTMLDialogElement;
    let stop: () => void;

    beforeEach(() => {
        document.body.innerHTML = PAGE;
        dialog = field<HTMLDialogElement>('dialog');
        dialog.showModal = vi.fn();
        dialog.close = vi.fn();
        stop = warnOnUnsavedChanges(dialog, document, window);
    });

    afterEach(() => {
        stop();
    });

    it('lets a clean page be left', () => {
        expect(click('#away').defaultPrevented).toBe(false);
        expect(leave().defaultPrevented).toBe(false);
        expect(dialog.showModal).not.toHaveBeenCalled();
    });

    it.each([
        ['a text field', () => (field<HTMLInputElement>('[name=title]').value = 'Other')],
        ['a textarea', () => (field<HTMLTextAreaElement>('textarea').value = 'Changed')],
        ['a checkbox', () => (field<HTMLInputElement>('[type=checkbox]').checked = false)],
        ['a select', () => (field<HTMLSelectElement>('select').value = 'b')],
    ])('opens the dialog instead of following a link after %s changed', (_label, change) => {
        change();

        expect(click('#away').defaultPrevented).toBe(true);
        expect(dialog.showModal).toHaveBeenCalledOnce();
    });

    it('ignores password, hidden and the untouched first option of a select', () => {
        field<HTMLInputElement>('[name=secret]').value = 'autofilled';
        field<HTMLInputElement>('[name=_token]').value = 'rotated';

        expect(click('#away').defaultPrevented).toBe(false);
    });

    it('ignores a revealed password field', () => {
        const secret = field<HTMLInputElement>('[name=secret]');
        secret.type = 'text';
        secret.dataset.passwordField = '';
        secret.value = 'typed';

        expect(click('#away').defaultPrevented).toBe(false);
    });

    it('ignores forms that are not named POST forms', () => {
        field<HTMLInputElement>('[name=q]').value = 'typed';

        expect(click('#away').defaultPrevented).toBe(false);
    });

    it('only guards links that leave the page inside the app', () => {
        field<HTMLInputElement>('[name=title]').value = 'Other';

        for (const id of ['#hash', '#blank', '#external']) {
            expect(click(id).defaultPrevented).toBe(false);
        }
        expect(click('#away', { ctrlKey: true }).defaultPrevented).toBe(false);
        expect(dialog.showModal).not.toHaveBeenCalled();
    });

    it('follows the link after Discard changes and stops warning', () => {
        field<HTMLInputElement>('[name=title]').value = 'Other';
        click('#away');
        const followed = vi.fn((event: Event) => event.preventDefault());
        field('#away').addEventListener('click', followed);

        field<HTMLButtonElement>('#leave').click();

        expect(dialog.close).toHaveBeenCalledOnce();
        expect(followed).toHaveBeenCalledOnce();
        expect(leave().defaultPrevented).toBe(false);
    });

    it('asks the browser to confirm reload, close and back', () => {
        field<HTMLInputElement>('[name=title]').value = 'Other';

        expect(leave().defaultPrevented).toBe(true);
    });

    it('does not warn about the form that is being submitted, but about another one', () => {
        field<HTMLInputElement>('[name=title]').value = 'Other';
        field<HTMLFormElement>('#main').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        expect(leave().defaultPrevented).toBe(false);

        field<HTMLInputElement>('[name=note]').value = 'typed';
        expect(leave().defaultPrevented).toBe(true);
    });

    it('does not count a submit that another handler cancelled', () => {
        field<HTMLInputElement>('[name=title]').value = 'Other';
        field<HTMLFormElement>('#main').addEventListener('submit', (event) => event.preventDefault(), {
            capture: true,
        });
        field<HTMLFormElement>('#main').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

        expect(leave().defaultPrevented).toBe(true);
    });
});
