import {test} from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import {fileURLToPath} from 'node:url';
import {createHash} from 'node:crypto';
import {adminThemeCss, adminThemeAssets} from '../../resources/saola/admin/build/themes.mjs';

const root = fileURLToPath(new URL('../..', import.meta.url));
test('production builds emit the original catalogue photographs at stable URLs', () => {
    const emitted = [];
    adminThemeAssets(root).generateBundle.call({emitFile: item => emitted.push(item)});
    assert.equal(emitted.length, 4);
    for (const item of emitted) {
        const file = path.join(root, 'resources/saola/admin', item.fileName);
        assert.deepEqual(item.source, fs.readFileSync(file));
        assert.match(item.fileName, /^themes\/material\/assets\/vendor\/material\/images\/small\/img-[1-4]\.jpg$/);
    }
});
test('themes bind the same package controllers without duplicating feature scripts', () => {
    const bindings = {
        'components/input': 'input', 'components/manager': 'manager', 'components/resource': 'resource',
        'components/settings-form': 'settings', 'components/languages': 'languages',
        'components/media-library': 'mediaLibrary', 'layouts/shell': 'shell',
        'pages/resource': 'page', 'pages/settings': 'page', 'pages/languages': 'page', 'pages/media': 'page',
    };
    for (const theme of ['classic', 'material']) {
        for (const [file, controller] of Object.entries(bindings)) {
            const source = fs.readFileSync(path.join(root, `resources/saola/admin/themes/${theme}/views/${file}.sao`), 'utf8');
            const script = source.match(/<script setup lang="ts">([\s\S]*?)<\/script>/)?.[1];
            assert.ok(script, `${theme}/${file}`);
            // Theme chỉ gọi API công khai qua App; không import đường dẫn triển khai của gói.
            assert.ok(script.includes(`App.Admin.controllers.${controller}($view, () =>`), `${theme}/${file}`);
            assert.doesNotMatch(script, /@sao\/admin\/(app|_shared)\//, `${theme}/${file} imports package internals`);
            const rest = script.replaceAll('App.Admin.controllers.', '');
            assert.doesNotMatch(rest, /\bfunction\b|\bfetch\s*\(|\bApp\.|addEventListener|\.save\s*\(/);
        }
    }
});

test('packaged themes have separate CSS entries and verified upstream asset hashes', () => {
    const entries = adminThemeCss(root);
    assert.deepEqual(entries, ['classic', 'material'].map(name => `resources/saola/admin/themes/${name}/assets/css/admin.css`));
    for (const entry of entries) {
        const css = fs.readFileSync(path.join(root, entry), 'utf8');
        for (const match of css.matchAll(/@import ['"]([^'"]+)['"]/g)) assert.ok(fs.existsSync(path.resolve(root, path.dirname(entry), match[1])), match[1]);
    }
    const vendor = path.join(root, 'resources/saola/admin/themes/material/assets/vendor/material');
    const source = JSON.parse(fs.readFileSync(path.join(vendor, 'SOURCE.json'), 'utf8'));
    for (const [file, hashes] of Object.entries(source.css)) {
        const bytes = fs.readFileSync(path.join(vendor, 'css', file));
        assert.equal(createHash('sha256').update(bytes).digest('hex'), hashes.packaged_sha256);
        assert.equal(bytes.toString().includes('@import url(https://fonts.googleapis.com'), false);
    }
});

test('a new theme adds its own entry without changing existing ones and rejects traversal', () => {
    const project = fs.mkdtempSync(path.join(os.tmpdir(), 'saola-theme-build-'));
    try {
        const add = name => {
            const directory = path.join(project, 'resources/saola/admin/themes', name);
            fs.mkdirSync(path.join(directory, 'assets/css'), {recursive: true});
            fs.writeFileSync(path.join(directory, 'assets/css/admin.css'), name);
            fs.writeFileSync(path.join(directory, 'theme.manifest.json'), JSON.stringify({name, contract: 1, css: 'assets/css/admin.css'}));
            return directory;
        };
        add('classic'); add('material'); const previous = adminThemeCss(project);
        const third = add('graphite'); const next = adminThemeCss(project);
        assert.equal(next.length, 3); assert.ok(previous.every(entry => next.includes(entry)));
        fs.writeFileSync(path.join(third, 'theme.manifest.json'), JSON.stringify({name: 'graphite', contract: 1, css: 'assets/../../outside.css'}));
        assert.throws(() => adminThemeCss(project), /Invalid admin theme manifest/);
    } finally { fs.rmSync(project, {recursive: true, force: true}); }
});

test('context manifest lists exactly the packaged themes and the shared source moved to app/', () => {
    const manifest = JSON.parse(fs.readFileSync(path.join(root, 'resources/saola/admin/context.manifest.json'), 'utf8'));
    const dirs = fs.readdirSync(path.join(root, 'resources/saola/admin/themes')).sort();
    assert.equal(manifest.context, 'admin');
    assert.deepEqual([...manifest.themes].sort(), dirs);
    assert.ok(dirs.includes(manifest.defaultTheme));
    assert.equal(fs.existsSync(path.join(root, 'resources/saola/admin/admin.manifest.json')), false);
    for (const file of ['bundle.ts', 'contracts/admin.ts', 'views/page.ts']) {
        assert.ok(fs.existsSync(path.join(root, 'resources/saola/admin/app', file)), file);
    }
    assert.equal(fs.existsSync(path.join(root, 'resources/saola/admin/_shared')), false);
});

