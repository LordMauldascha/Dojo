import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { rollAwayAfterLoad } from '../../components/tatamiCover';

const MATS = `
    <div class="tatami">
        <span class="tatami-mat"><span class="tatami-sheet"></span></span>
        <span class="tatami-mat"><span class="tatami-sheet"></span></span>
        <span class="tatami-mat"><span class="tatami-sheet"></span></span>
    </div>`;

function lastSheet(): Element {
    return document.querySelector('.tatami-mat:last-child .tatami-sheet') as Element;
}

function endAnimation(target: Element, animationName: string): void {
    const event = new Event('animationend', { bubbles: true });
    Object.defineProperty(event, 'animationName', { value: animationName });
    target.dispatchEvent(event);
}

describe('rollAwayAfterLoad', () => {
    beforeEach(() => {
        document.documentElement.className = '';
        sessionStorage.setItem('dojo-tatami', '1');
        document.body.innerHTML = MATS;
        vi.stubGlobal('requestAnimationFrame', (callback: FrameRequestCallback) => {
            callback(0);

            return 0;
        });
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('does nothing without the cover class', () => {
        rollAwayAfterLoad(document, window);

        expect(document.documentElement.classList.contains('tatami-away')).toBe(false);
        expect(sessionStorage.getItem('dojo-tatami')).toBe('1');
    });

    it('rolls the mats away once the page has loaded and removes the cover afterwards', () => {
        document.documentElement.classList.add('tatami-cover');
        Object.defineProperty(document, 'readyState', { value: 'loading', configurable: true });

        rollAwayAfterLoad(document, window);
        expect(document.documentElement.classList.contains('tatami-away')).toBe(false);
        expect(sessionStorage.getItem('dojo-tatami')).toBeNull();

        window.dispatchEvent(new Event('load'));
        expect(document.documentElement.classList.contains('tatami-away')).toBe(true);

        endAnimation(lastSheet(), 'tatami-sheet-away');
        expect(document.documentElement.classList.contains('tatami-cover')).toBe(false);
        expect(document.documentElement.classList.contains('tatami-away')).toBe(false);

        Object.defineProperty(document, 'readyState', { value: 'complete', configurable: true });
    });

    it('starts at once when the page is already loaded', () => {
        document.documentElement.classList.add('tatami-cover');
        Object.defineProperty(document, 'readyState', { value: 'complete', configurable: true });

        rollAwayAfterLoad(document, window);

        expect(document.documentElement.classList.contains('tatami-away')).toBe(true);
    });
});
