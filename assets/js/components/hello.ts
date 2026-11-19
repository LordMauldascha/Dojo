export default class Hello {
    initialize(el: HTMLElement, options: { text: string }): void {
        el.addEventListener('click', () => alert(options.text));
    }
}
