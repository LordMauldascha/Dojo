import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { enableSkillHeaders, renderSkillHeader, skillName } from '../../services/skillHeader';

// The cases are the same as in tests/Skill/SkillHeaderTest.php, so both implementations stay in step.
describe('skillName', () => {
    it.each([
        ['Commit Writer', 'commit-writer'],
        ['Über Größe', 'ueber-groesse'],
        ['ÄÖÜ Straße', 'aeoeue-strasse'],
        ['  a \t b  ', 'a-b'],
        ['v2-b 3', 'v2-b-3'],
        ['', null],
        ['a--b', null],
        ['-a', null],
        ['a-', null],
        ['a,b', null],
        ['a,._<>?!"§$%&/()=`´*+\'#b', null],
        ['café', null],
    ])('%j becomes %j', (title, expected) => {
        expect(skillName(title)).toBe(expected);
    });
});

describe('renderSkillHeader', () => {
    it.each([
        ['Commit Writer', 'Writes commits.', '---\nname: commit-writer\ndescription: Writes commits.\n---'],
        ['a', 'Say "hi" now', '---\nname: a\ndescription: Say "hi" now\n---'],
        [
            'a',
            'Line one\n\n  line two  \r\nline three',
            '---\nname: a\ndescription: Line one\n  line two\n  line three\n---',
        ],
        ['a', 'Use when: asked\nsecond', '---\nname: a\ndescription: >-\n  Use when: asked\n  second\n---'],
        ['a', 'one\n# two', '---\nname: a\ndescription: >-\n  one\n  # two\n---'],
        ['a', '"quoted" start', '---\nname: a\ndescription: >-\n  "quoted" start\n---'],
        ['a', '', '---\nname: a\ndescription: ""\n---'],
        ['a', ' \n ', '---\nname: a\ndescription: ""\n---'],
        ['a,b', 'x', '---\nname: \ndescription: x\n---'],
    ])('%j with %j', (title, description, expected) => {
        expect(renderSkillHeader(title, description)).toBe(expected);
    });
});

describe('enableSkillHeaders', () => {
    let stop: () => void;

    const field = <T extends HTMLElement>(id: string): T => document.getElementById(id) as T;
    const type = (id: string, value: string): void => {
        const element = field<HTMLInputElement | HTMLTextAreaElement>(id);
        element.value = value;
        element.dispatchEvent(new Event('input', { bubbles: true }));
    };

    beforeEach(() => {
        document.body.innerHTML = `
            <form>
                <input id="title" name="skill_content[title]" value="Old title">
                <textarea id="description" name="skill_content[description]">Old description</textarea>
                <textarea id="header" name="skill_content[skillHeader]" data-skill-header readonly>stale</textarea>
            </form>`;
        stop = enableSkillHeaders(document);
    });

    afterEach(() => {
        stop();
    });

    it('fills the header from the stored title and description on load', () => {
        expect(field<HTMLTextAreaElement>('header').value).toBe(
            '---\nname: old-title\ndescription: Old description\n---',
        );
    });

    it('follows the title and the description while typing', () => {
        type('title', 'Über Größe');
        type('description', 'Use when: asked');

        expect(field<HTMLTextAreaElement>('header').value).toBe(
            '---\nname: ueber-groesse\ndescription: >-\n  Use when: asked\n---',
        );
    });

    it('sets defaultValue as well, so the unsaved-changes guard sees no edit of the header', () => {
        type('title', 'New title');

        const header = field<HTMLTextAreaElement>('header');
        expect(header.defaultValue).toBe(header.value);
    });

    it('blocks the submit for a title that cannot become a name', () => {
        type('title', 'a,b');
        expect(field<HTMLInputElement>('title').validity.customError).toBe(true);
        expect(field<HTMLTextAreaElement>('header').value).toContain('name: \n');

        type('title', 'a b');
        expect(field<HTMLInputElement>('title').validity.customError).toBe(false);
    });

    it('stops following after the cleanup', () => {
        stop();
        type('title', 'Changed');

        expect(field<HTMLTextAreaElement>('header').value).toContain('name: old-title');
    });
});
