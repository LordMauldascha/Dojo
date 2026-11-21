const UMLAUTS: Record<string, string> = { ä: 'ae', ö: 'oe', ü: 'ue', Ä: 'ae', Ö: 'oe', Ü: 'ue', ß: 'ss' };

// A description that needs the block form, else YAML would read it as a mapping, a comment or an indicator.
const NEEDS_BLOCK = /: | #|:$|^[[\]{}&*!|>'"%@`#?,:-]/;

// A plain scalar that YAML 1.1 reads as a boolean, null or number; only the block form keeps it a string.
const READ_AS_NON_STRING =
    /^(?:true|false|yes|no|on|off|null|~|[-+]?(?:0x[0-9a-f_]+|0b[01_]+|0o[0-7_]+|(?:[0-9][0-9_]*(?:\.[0-9_]*)?|\.[0-9][0-9_]*)(?:e[-+]?[0-9]+)?|[0-9][0-9_]*(?::[0-5]?[0-9])+(?:\.[0-9_]*)?|\.(?:inf|nan)))$/i;

// Longest name the Agent Skills specification allows (https://agentskills.io/specification).
export const MAX_NAME_LENGTH = 64;

const INVALID_TITLE = 'Use only letters, digits, spaces and hyphens (ä, ö, ü, ß become ae, oe, ue, ss).';
const NAME_TOO_LONG = `The skill name made from the title may be at most ${MAX_NAME_LENGTH} characters.`;

/** Mirrors App\Skill\SkillHeader::name(). Null when the title contains anything else than letters, digits, spaces and hyphens. */
export function skillName(title: string): string | null {
    const name = title
        .replace(/[äöüÄÖÜß]/g, (character) => UMLAUTS[character])
        .toLowerCase()
        .split(/\s+/)
        .filter((word) => '' !== word)
        .join('-');

    return /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(name) ? name : null;
}

/** Mirrors the title validation of App\Form\Model\SkillData. Empty string when the title is fine. */
export function titleProblem(title: string): string {
    if ('' === title.trim()) {
        return '';
    }

    const name = skillName(title);
    if (null === name) {
        return INVALID_TITLE;
    }

    return MAX_NAME_LENGTH < name.length ? NAME_TOO_LONG : '';
}

/** Mirrors App\Skill\SkillHeader::render(). */
export function renderSkillHeader(title: string, description: string): string {
    const lines = description
        .split(/\r\n|\r|\n/)
        .map((line) => line.trim())
        .filter((line) => '' !== line);

    let entry = 'description: ""';
    if (0 < lines.length) {
        const text = lines.join(' ');
        entry =
            NEEDS_BLOCK.test(text) || READ_AS_NON_STRING.test(text)
                ? `description: >-\n  ${lines.join('\n  ')}`
                : `description: ${lines.join('\n  ')}`;
    }

    return `---\nname: ${skillName(title) ?? ''}\n${entry}\n---`;
}

/**
 * Keeps every readonly skill header textarea (data-skill-header) in step with the title and description of its form.
 * defaultValue is set too, so the unsaved-changes guard does not count the generated text as an edit. A title that
 * cannot become a skill name blocks the submit with a browser message. Returns a function that removes the listeners.
 */
export function enableSkillHeaders(root: Document = document): () => void {
    const stops: (() => void)[] = [];

    root.querySelectorAll<HTMLTextAreaElement>('textarea[data-skill-header]').forEach((header) => {
        const title = header.form?.querySelector<HTMLInputElement>('input[name$="[title]"]');
        const description = header.form?.querySelector<HTMLTextAreaElement>('textarea[name$="[description]"]');
        if (!title || !description) {
            return;
        }

        const update = (): void => {
            title.setCustomValidity(titleProblem(title.value));
            header.defaultValue = renderSkillHeader(title.value, description.value);
            header.value = header.defaultValue;
        };

        update();
        title.addEventListener('input', update);
        description.addEventListener('input', update);
        stops.push(() => {
            title.removeEventListener('input', update);
            description.removeEventListener('input', update);
        });
    });

    return () => stops.forEach((stop) => stop());
}

export default {
    enable(): void {
        enableSkillHeaders();
    },
};
