/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 *
 * Helpers for tests that read JavaScript out of the module's templates.
 */
import {readFileSync} from 'node:fs';
import {dirname, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';

const PACKAGE_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..');

/** File contents relative to the package root. */
const readPackageFile = (path) => readFileSync(resolve(PACKAGE_ROOT, path), 'utf8');

/** Removes the indentation shared by all non-blank lines. */
const dedent = (text) => {
    const lines = text.split('\n');
    const indents = lines.filter((line) => line.trim() !== '').map((line) => line.match(/^ */)[0].length);
    const indent = indents.length ? Math.min(...indents) : 0;
    return lines.map((line) => line.slice(Math.min(indent, line.match(/^ */)[0].length))).join('\n');
};

/** The code between `/* <name>:start *\/` and `/* <name>:end *\/` (markers included), dedented. */
const extractBlock = (text, name) => {
    const start = text.indexOf(`/* ${name}:start */`);
    const endMarker = `/* ${name}:end */`;
    const end = text.indexOf(endMarker);
    if (start === -1 || end === -1 || end < start) {
        throw new Error(`Markers for "${name}" not found.`);
    }
    const lineStart = text.lastIndexOf('\n', start) + 1;
    return dedent(text.slice(lineStart, end + endMarker.length));
};

/** The body of the only inline script of a template. */
const extractScript = (template) => {
    const match = template.match(/<script>\n([\s\S]*?)<\/script>/);
    if (!match || template.split('<script>').length !== 2) {
        throw new Error('Expected exactly one <script> element.');
    }
    return match[1];
};

export {PACKAGE_ROOT, readPackageFile, dedent, extractBlock, extractScript};
