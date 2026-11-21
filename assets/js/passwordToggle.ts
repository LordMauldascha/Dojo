const SVG_NS = 'http://www.w3.org/2000/svg';
const EYE = 'M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z';

function icon(revealed: boolean): SVGSVGElement {
    const svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');

    const shapes: [string, Record<string, string>][] = [
        ['path', { d: EYE }],
        ['circle', { cx: '12', cy: '12', r: '3' }],
    ];
    if (revealed) {
        shapes.push(['path', { d: 'M3 3l18 18' }]);
    }
    for (const [name, attributes] of shapes) {
        const shape = document.createElementNS(SVG_NS, name);
        Object.entries(attributes).forEach(([key, value]) => shape.setAttribute(key, value));
        svg.append(shape);
    }

    return svg;
}

/**
 * Puts an eye button next to every password field that shows or hides the typed text. A revealed field is hidden again
 * when its dialog closes and when the page comes back from the back/forward cache. The fields get data-password-field,
 * so the unsaved-changes guard keeps ignoring them while their type is "text". Returns a function that removes the
 * listeners.
 */
export function enablePasswordToggles(root: Document = document, view: Window = window): () => void {
    const setters = new Map<HTMLInputElement, (revealed: boolean) => void>();

    root.querySelectorAll<HTMLInputElement>('input[type="password"]').forEach((input) => {
        const wrapper = root.createElement('span');
        wrapper.className = 'password-field';
        const button = root.createElement('button');
        button.type = 'button';
        button.className = 'password-toggle';
        if ('' !== input.id) {
            button.setAttribute('aria-controls', input.id);
        }

        const set = (revealed: boolean): void => {
            input.type = revealed ? 'text' : 'password';
            button.setAttribute('aria-pressed', String(revealed));
            button.setAttribute('aria-label', revealed ? 'Hide password' : 'Show password');
            button.replaceChildren(icon(revealed));
        };

        input.dataset.passwordField = '';
        input.before(wrapper);
        wrapper.append(input, button);
        set(false);
        button.addEventListener('click', () => set('password' === input.type));
        setters.set(input, set);
    });

    const hide = (scope: ParentNode): void => {
        scope
            .querySelectorAll<HTMLInputElement>('input[data-password-field]')
            .forEach((input) => setters.get(input)?.(false));
    };

    // "close" does not bubble, so listen in the capture phase.
    const onClose = (event: Event): void => {
        if (event.target instanceof HTMLDialogElement) {
            hide(event.target);
        }
    };
    const onPageShow = (event: Event): void => {
        if ((event as PageTransitionEvent).persisted) {
            hide(root);
        }
    };

    root.addEventListener('close', onClose, true);
    view.addEventListener('pageshow', onPageShow);

    return () => {
        root.removeEventListener('close', onClose, true);
        view.removeEventListener('pageshow', onPageShow);
    };
}
