// A click without modifier keys; anything else (new tab, new window, download) is left to the browser.
export function isPlainLeftClick(event: MouseEvent): boolean {
    return 0 === event.button && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey;
}
