import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import {
    enableSkillHeaders,
    MAX_NAME_LENGTH,
    renderSkillHeader,
    skillName,
    titleProblem,
} from '../../services/skillHeader';

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

describe('titleProblem', () => {
    it('accepts a name of exactly the maximum length and refuses a longer one', () => {
        expect(MAX_NAME_LENGTH).toBe(64);
        expect(titleProblem('a'.repeat(64))).toBe('');
        expect(titleProblem('a'.repeat(65))).toContain('at most 64 characters');
    });

    it('refuses invalid characters and ignores a blank title', () => {
        expect(titleProblem('a,b')).toContain('Use only letters');
        expect(titleProblem('   ')).toBe('');
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
        ['a', 'true', '---\nname: a\ndescription: >-\n  true\n---'],
        ['a', 'False', '---\nname: a\ndescription: >-\n  False\n---'],
        ['a', 'YES', '---\nname: a\ndescription: >-\n  YES\n---'],
        ['a', 'no', '---\nname: a\ndescription: >-\n  no\n---'],
        ['a', 'On', '---\nname: a\ndescription: >-\n  On\n---'],
        ['a', 'off', '---\nname: a\ndescription: >-\n  off\n---'],
        ['a', 'null', '---\nname: a\ndescription: >-\n  null\n---'],
        ['a', '~', '---\nname: a\ndescription: >-\n  ~\n---'],
        ['a', '1.5', '---\nname: a\ndescription: >-\n  1.5\n---'],
        ['a', '0x1F', '---\nname: a\ndescription: >-\n  0x1F\n---'],
        ['a', '1e3', '---\nname: a\ndescription: >-\n  1e3\n---'],
        ['a', '.5', '---\nname: a\ndescription: >-\n  .5\n---'],
        ['a', '42', '---\nname: a\ndescription: >-\n  42\n---'],
        ['a', '-7', '---\nname: a\ndescription: >-\n  -7\n---'],
        ['a', '+3', '---\nname: a\ndescription: >-\n  +3\n---'],
        ['a', '1_000', '---\nname: a\ndescription: >-\n  1_000\n---'],
        ['a', '1:30', '---\nname: a\ndescription: >-\n  1:30\n---'],
        ['a', '.inf', '---\nname: a\ndescription: >-\n  .inf\n---'],
        ['a', 'truest', '---\nname: a\ndescription: truest\n---'],
        ['a', 'nothing', '---\nname: a\ndescription: nothing\n---'],
        ['a', '1.5.2', '---\nname: a\ndescription: 1.5.2\n---'],
        ['a', '0x', '---\nname: a\ndescription: 0x\n---'],
        ['a', '1e', '---\nname: a\ndescription: 1e\n---'],
        ['a', 'Version 2', '---\nname: a\ndescription: Version 2\n---'],
        ['a', 'a1', '---\nname: a\ndescription: a1\n---'],
        ['a', 'null pointer', '---\nname: a\ndescription: null pointer\n---'],
        ['a', '12 monkeys', '---\nname: a\ndescription: 12 monkeys\n---'],
        ['a', '3.14 is pi', '---\nname: a\ndescription: 3.14 is pi\n---'],
        ['a', 'true\nfalse', '---\nname: a\ndescription: true\n  false\n---'],
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
