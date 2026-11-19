import { describe, expect, it, vi } from 'vitest';
import Hello from '../components/hello';

describe('Hello', () => {
    it('alerts the configured text on click', () => {
        const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
        const el = document.createElement('button');

        new Hello().initialize(el, { text: 'Hi' });
        el.click();

        expect(alertSpy).toHaveBeenCalledWith('Hi');
    });
});
