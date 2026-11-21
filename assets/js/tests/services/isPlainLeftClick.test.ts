import { describe, expect, it } from 'vitest';
import { isPlainLeftClick } from '../../services/isPlainLeftClick';

describe('isPlainLeftClick', () => {
    it('accepts a primary click without modifier keys', () => {
        expect(isPlainLeftClick(new MouseEvent('click', { button: 0 }))).toBe(true);
    });

    it.each([['metaKey'], ['ctrlKey'], ['shiftKey'], ['altKey']])('rejects a click with %s', (modifier) => {
        expect(isPlainLeftClick(new MouseEvent('click', { button: 0, [modifier]: true }))).toBe(false);
    });

    it('rejects the middle and the right button', () => {
        expect(isPlainLeftClick(new MouseEvent('click', { button: 1 }))).toBe(false);
        expect(isPlainLeftClick(new MouseEvent('click', { button: 2 }))).toBe(false);
    });
});
