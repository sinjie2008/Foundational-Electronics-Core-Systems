import { createHash } from 'node:crypto';
import { cp, mkdir, readdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import * as sass from 'sass';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const scssDirectory = path.join(projectRoot, 'assets', 'scss');
const cssOutputDirectory = path.join(projectRoot, 'public', 'assets', 'css');
const javascriptSourceDirectory = path.join(projectRoot, 'assets', 'js');
const javascriptOutputDirectory = path.join(projectRoot, 'public', 'assets', 'js');
const pageSourceDirectory = path.join(projectRoot, 'views');
const publicDirectory = path.join(projectRoot, 'public');
const seriesScript = await readFile(
    path.join(javascriptSourceDirectory, 'series-typst-templating.js')
);
const seriesScriptHash = createHash('sha256').update(seriesScript).digest('hex').slice(0, 12);

/** Compile each top-level SCSS source to its matching public CSS path. */
async function buildStylesheets() {
    await mkdir(cssOutputDirectory, { recursive: true });
    const entries = await readdir(scssDirectory, { withFileTypes: true });
    const scssFiles = entries
        .filter((entry) => entry.isFile() && entry.name.endsWith('.scss') && !entry.name.startsWith('_'))
        .map((entry) => entry.name)
        .sort();

    for (const fileName of scssFiles) {
        const sourcePath = path.join(scssDirectory, fileName);
        const outputPath = path.join(cssOutputDirectory, `${path.parse(fileName).name}.css`);
        const result = sass.compile(sourcePath, {
            style: 'expanded',
            sourceMap: false,
        });
        await writeFile(outputPath, result.css, 'utf8');
    }
}

/** Copy class-based browser code to the stable public asset URLs. */
async function buildJavaScript() {
    await cp(javascriptSourceDirectory, javascriptOutputDirectory, {
        recursive: true,
        force: true,
    });
}

/** Copy static page templates to their existing URLs without embedding PHP. */
async function buildPages() {
    const entries = await readdir(pageSourceDirectory, { withFileTypes: true });
    const pages = entries.filter((entry) => entry.isFile() && entry.name.endsWith('.html'));

    for (const page of pages) {
        const sourcePath = path.join(pageSourceDirectory, page.name);
        let contents = await readFile(sourcePath, 'utf8');
        if (contents.includes('<?php')) {
            throw new Error(`PHP code must stay out of HTML templates: ${sourcePath}`);
        }
        if (page.name === 'series_typst_template.html') {
            contents = contents.replaceAll('__SERIES_TYPST_ASSET_HASH__', seriesScriptHash);
        }
        await writeFile(path.join(publicDirectory, page.name), contents, 'utf8');
    }
}

await buildStylesheets();
await buildJavaScript();
await buildPages();
console.log(`Built ${await readdir(cssOutputDirectory).then((items) => items.length)} CSS files, JavaScript assets, and HTML pages.`);
