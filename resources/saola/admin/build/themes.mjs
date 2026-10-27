import fs from 'node:fs';
import path from 'node:path';

/** Independent CSS inputs: changing the PHP view context never loads another theme's skin. */
export function adminThemeCss(projectRoot) {
    const root = path.join(projectRoot, 'resources/saola/admin/themes');
    return fs.readdirSync(root, {withFileTypes: true}).filter(entry => entry.isDirectory() && /^[a-z][a-z0-9-]*$/.test(entry.name))
        .flatMap(entry => {
            const manifest = path.join(root, entry.name, 'theme.manifest.json');
            if (!fs.existsSync(manifest)) return [];
            const theme = JSON.parse(fs.readFileSync(manifest, 'utf8'));
            if (theme.contract !== 1 || theme.name !== entry.name || typeof theme.css !== 'string'
                || !/^assets\/[A-Za-z0-9_./-]+\.css$/.test(theme.css)
                || theme.css.split('/').some(part => part === '..' || part === '.' || part === '')) throw new Error('Invalid admin theme manifest: ' + entry.name);
            const css = `resources/saola/admin/themes/${entry.name}/${theme.css}`;
            if (!fs.existsSync(path.join(projectRoot, css))) throw new Error('Missing admin theme CSS: ' + css);
            return [css];
        }).sort();
}

/** Keep catalogue images at their stable public URLs after Vite empties the admin output. */
export function adminThemeAssets(projectRoot) {
    return {
        name: 'saola-admin-theme-assets', apply: 'build',
        generateBundle() {
            for (const css of adminThemeCss(projectRoot)) {
                const themeRoot = path.resolve(projectRoot, path.dirname(css), '../..');
                const inventory = path.join(themeRoot, 'assets/ui.sources.json');
                if (!fs.existsSync(inventory)) continue;
                const data = JSON.parse(fs.readFileSync(inventory, 'utf8'));
                const directory = data.imageDirectory;
                if (typeof directory !== 'string' || !/^[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*$/.test(directory)) throw new Error('Invalid UI image directory.');
                for (const name of Object.keys(data.images || {})) {
                    if (!/^[A-Za-z0-9_-]+\.(?:png|jpe?g|webp|gif|svg)$/.test(name)) throw new Error('Invalid UI image filename.');
                    const file = path.join(themeRoot, 'assets', directory, name);
                    const assetRoot = fs.realpathSync(path.join(themeRoot, 'assets')) + path.sep;
                    if (!fs.realpathSync(file).startsWith(assetRoot)) throw new Error('UI image escapes the theme assets.');
                    this.emitFile({type: 'asset', fileName: `themes/${path.basename(themeRoot)}/assets/${directory}/${name}`, source: fs.readFileSync(file)});
                }
            }
        },
    };
}
