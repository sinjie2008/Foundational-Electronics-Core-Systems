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
let assetRevision;

/** Keep entrypoints and their shared module imports on the same browser-cache revision. */
async function computeAssetRevision() {
    const hash = createHash('sha256');
    for (const directory of [javascriptSourceDirectory, cssOutputDirectory, pageSourceDirectory]) {
        const entries = (await readdir(directory, { withFileTypes: true }))
            .filter((entry) => entry.isFile())
            .sort((a, b) => a.name.localeCompare(b.name));
        for (const entry of entries) {
            hash.update(entry.name + '\0');
            hash.update(await readFile(path.join(directory, entry.name)));
        }
    }
    return hash.digest('hex').slice(0, 12);
}

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
    const entries = await readdir(javascriptSourceDirectory, { withFileTypes: true });
    for (const entry of entries.filter((item) => item.isFile() && item.name.endsWith('.js'))) {
        const contents = await readFile(path.join(javascriptSourceDirectory, entry.name), 'utf8');
        const versioned = contents.replace(
            /^(\s*(?:import|export)\s+[^;\r\n]*?\bfrom\s*|^\s*import\s*)(['"])(\.{1,2}\/[^'"]+\.js)(?:\?[^'"]*)?\2/gm,
            (_, prefix, quote, modulePath) => prefix + quote + modulePath + '?v=' + assetRevision + quote
        );
        await writeFile(path.join(javascriptOutputDirectory, entry.name), versioned, 'utf8');
    }
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
        contents = contents.replace(
            /((?:src|href)=["'])(assets\/(?:js|css)\/[^"']+\.(?:js|css))(?:\?[^"']*)?(["'])/g,
            (_, prefix, assetPath, quote) => prefix + assetPath + '?v=' + assetRevision + quote
        );
        await writeFile(path.join(publicDirectory, page.name), contents, 'utf8');
    }
}

await buildStylesheets();
assetRevision = await computeAssetRevision();
await buildJavaScript();
await buildPages();
console.log(`Built ${await readdir(cssOutputDirectory).then((items) => items.length)} CSS files, JavaScript assets, and HTML pages.`);
