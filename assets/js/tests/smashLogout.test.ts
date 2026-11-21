import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { smashLogout } from '../smashLogout';

function click(link: HTMLAnchorElement, init: MouseEventInit = {}): MouseEvent {
    const event = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ...init });
    link.dispatchEvent(event);

    return event;
}

describe('smashLogout', () => {
    let link: HTMLAnchorElement;
    let navigate: ReturnType<typeof vi.fn<(url: string) => void>>;
    let stop: () => void;

    beforeEach(() => {
        document.body.innerHTML = '<a class="logout" href="/logout?token=abc"><span>Logout</span></a>';
        link = document.querySelector('a.logout') as HTMLAnchorElement;
        navigate = vi.fn<(url: string) => void>();
        stop = smashLogout(document, window, navigate);
    });

    afterEach(() => {
        stop();
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    it('plays the animation first and follows the link when it ends', () => {
        const event = click(link);

        expect(event.defaultPrevented).toBe(true);
        expect(link.classList.contains('is-smashing')).toBe(true);
        expect(navigate).not.toHaveBeenCalled();

        link.dispatchEvent(new Event('animationend', { bubbles: true }));

        expect(navigate).toHaveBeenCalledOnce();
        expect(navigate).toHaveBeenCalledWith(link.href);
    });

    it('ignores the animation end of the label', () => {
        click(link);

        (link.querySelector('span') as HTMLElement).dispatchEvent(new Event('animationend', { bubbles: true }));

        expect(navigate).not.toHaveBeenCalled();
    });

    it('follows the link after a fallback delay when no animation event arrives', () => {
        vi.useFakeTimers();
        click(link);

        vi.advanceTimersByTime(1200);

        expect(navigate).toHaveBeenCalledOnce();
    });

    it('ignores a second click while the brick is breaking', () => {
        click(link);
        const second = click(link);

        expect(second.defaultPrevented).toBe(true);
        expect(navigate).not.toHaveBeenCalled();
    });

    it('leaves modified clicks to the browser', () => {
        const event = click(link, { ctrlKey: true });

        expect(event.defaultPrevented).toBe(false);
        expect(link.classList.contains('is-smashing')).toBe(false);
    });

    it('skips the animation with reduced motion', () => {
        vi.stubGlobal('matchMedia', () => ({ matches: true }));

        const event = click(link);

        expect(event.defaultPrevented).toBe(false);
        expect(link.classList.contains('is-smashing')).toBe(false);
    });

    it('removes the smashed state when the page is shown again', () => {
        click(link);

        window.dispatchEvent(new Event('pageshow'));

        expect(link.classList.contains('is-smashing')).toBe(false);
    });
});
