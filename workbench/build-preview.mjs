/** Local source workbench only. Takes fixtures exported by PanelTest; never reads a host database. */
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';
import fs from 'node:fs';
import path from 'node:path';
import {adminThemeAssets} from '../resources/saola/admin/build/themes.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = fs.realpathSync(path.resolve(process.argv[2] || '/tmp/saola-admin-preview'));
const origin = process.env.SAOLA_ADMIN_PREVIEW_URL || 'http://127.0.0.1:8769';
const require = createRequire(import.meta.url);
const Builder = require('@saolabs/builder');
const {build} = await import('vite');
const config = JSON.parse(fs.readFileSync(path.join(root, 'sao.config.json'), 'utf8'));
const viewsRoot = path.join(root, 'resources/js/saola/admin/views');
const files = fs.readdirSync(viewsRoot, {recursive: true}).filter(file => file.endsWith('.ts'));
const imports = files.map((file, index) => `import View${index} from './views/${file.replaceAll(path.sep, '/')}';`);
const entries = files.map((file, index) => `${JSON.stringify('admin.' + file.replaceAll(path.sep, '.').replace(/\.ts$/, ''))}: View${index}`);
fs.writeFileSync(path.join(root, 'resources/js/saola/admin/registry.js'), `${imports.join('\n')}\nexport default {${entries.join(',\n')}};\n`);
await new Builder().generateAppEntry(config.contexts.admin, root, config.paths, 'admin');
await build({
    root, configFile: false, publicDir: false, base: '/static/saola/admin/', logLevel: 'warn',
    plugins: [adminThemeAssets(root)],
    resolve: {alias: {'@saolabs/client': require.resolve('@saolabs/client'), '@sao': path.join(root, 'resources/saola')}},
    build: {outDir: 'public/static/saola/admin', emptyOutDir: true, manifest: 'manifest.json',
        rollupOptions: {input: [path.join(root, 'resources/js/saola/app.admin.js'), ...['classic', 'material'].map(theme => path.join(root, `resources/saola/admin/themes/${theme}/assets/css/admin.css`))], preserveEntrySignatures: 'exports-only'}},
});
const manifest = JSON.parse(fs.readFileSync(path.join(root, 'public/static/saola/admin/manifest.json'), 'utf8'));
const entry = manifest['resources/js/saola/app.admin.js'];
const themeCss = manifest['resources/saola/admin/themes/classic/assets/css/admin.css'];
if (!entry || !themeCss?.file) throw new Error('Admin build is missing its JavaScript or stylesheet');
for (const locale of ['en', 'vi']) {
    for (const page of ['index', 'categories', 'settings', 'users', 'languages']) {
        const filename = path.join(root, `public/${page}-${locale}.html`);
        let html = fs.readFileSync(filename, 'utf8').replaceAll('http://localhost', origin);
        html = html.replaceAll('/static/saola/admin/js/app.admin.js', '/static/saola/admin/' + entry.file);
        html = html.replace('</head>', [themeCss.file].map(css => `<link rel="stylesheet" href="/static/saola/admin/${css}">`).join('\n') + '</head>');
        fs.writeFileSync(filename, html);
    }
    for (const kind of ['page', 'categories', 'dictionary', 'settings-page', 'users-page', 'languages-page']) {
        const json = path.join(root, `${kind}-${locale}.json`);
        fs.writeFileSync(json, JSON.stringify(JSON.parse(fs.readFileSync(json, 'utf8'))).replaceAll('http://localhost', origin));
    }
}
console.log(`Built the published admin source with installed versioned packages. Preview: ${origin}/control`);
