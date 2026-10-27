import {test} from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {build} from 'esbuild';

const root = fileURLToPath(new URL('../..', import.meta.url));

/** Gom bundle của gói với runtime client giả (`defineBundle` là identity) để chạy thật `AdminUI`. */
async function loadBundle() {
    const stub = 'export const defineBundle = bundle => bundle;';
    const result = await build({
        entryPoints: [path.join(root, 'resources/saola/admin/app/bundle.ts')], bundle: true, write: false, format: 'esm', platform: 'node', logLevel: 'silent',
        plugins: [{name: 'client-stub', setup(b) {
            b.onResolve({filter: /^@saolabs\/client$/}, () => ({path: 'client', namespace: 'stub'}));
            b.onLoad({filter: /.*/, namespace: 'stub'}, () => ({contents: stub, loader: 'js'}));
        }}],
    });
    const code = result.outputFiles[0].text;
    return import('data:text/javascript;base64,' + Buffer.from(code).toString('base64'));
}

test('each Application gets its own Admin instance and Admin is the same service as AdminUI', async () => {
    const {default: bundle} = await loadBundle();
    const appA = {}, appB = {};
    const make = (name, app) => bundle.services[name].factory(app);
    assert.equal(make('Admin', appA), make('AdminUI', appA));
    assert.notEqual(make('Admin', appA), make('Admin', appB));

    const a = make('Admin', appA);
    const seen = [];
    a.watchCurrentPage(key => seen.push(key));
    a.setCurrentPage('settings');
    assert.deepEqual(seen, ['settings']);
    assert.equal(make('Admin', appB).canChangeLanguage(), true);
    a.registerLanguageGuard(() => false);
    assert.equal(a.canChangeLanguage(), false);
    assert.equal(make('Admin', appB).canChangeLanguage(), true, 'guards are per Application');
});

test('controllers bind a view without the theme passing App', async () => {
    const {default: bundle} = await loadBundle();
    const app = {};
    const admin = bundle.services.Admin.factory(app);
    app.Admin = admin;
    let config;
    const view = {__ctrl__: {setUserDefinedConfig: value => { config = value; }}};
    admin.controllers.page(view, () => 'media');
    config.started();
    let page;
    admin.watchCurrentPage(key => { page = key; });
    assert.equal(page, 'media');
    assert.deepEqual(Object.keys(admin.controllers).sort(), ['input', 'languages', 'manager', 'mediaLibrary', 'page', 'resource', 'settings', 'shell', 'ui']);
});

const editor = value => ({group: 'general', contentLocale: 'en', endpoint: '/admin/settings/general', csrfToken: 't', canUpdate: true,
    document: {group: 'general', schemaVersion: 1, revision: 1, values: {title: value}, localized: {}, contentLocales: ['en'], defaultLocale: 'en'},
    fields: [{key: 'title', labelKey: 'k', type: 'text', localized: false, value, present: true, maxLength: 100, min: '', max: ''}]});

test('two views never share a draft, and leaving a view cancels its in-flight request', async () => {
    const {default: bundle} = await loadBundle();
    const admin = bundle.services.Admin.factory({});
    const first = admin.createSettingsForm(editor('a'));
    const second = admin.createSettingsForm(editor('a'));

    first.change('title', 'changed');
    assert.equal(first.dirty, true);
    assert.equal(second.dirty, false, 'draft belongs to one form instance');
    assert.equal(second.fields[0].value, 'a');

    let signal;
    const never = (url, init) => new Promise((_, reject) => { signal = init.signal; init.signal.addEventListener('abort', () => reject(new Error('aborted'))); });
    const saving = first.save(never);
    assert.equal(first.busy, true);
    first.suspend();
    await saving;
    assert.equal(signal.aborted, true, 'request is aborted when the view pauses');
    assert.equal(first.busy, false);
    assert.equal(first.notice, 'admin.settings.reload_required', 'unconfirmed write forces a reload, never a silent retry');
});

test('page controller picks up currentPage when @await data arrives after started', async () => {
    const {default: bundle} = await loadBundle();
    const app = {};
    const admin = bundle.services.Admin.factory(app);
    app.Admin = admin;
    let config, listener, stopped = 0, value = '';
    const view = {
        __ctrl__: {setUserDefinedConfig: c => { config = c; }},
        $state: {__: {subscribe: (key, cb) => { assert.equal(key, 'currentPage'); listener = cb; return () => { stopped++; listener = null; }; }}},
    };
    admin.controllers.page(view, () => value);

    config.started();                       // dữ liệu chưa về: currentPage rỗng
    assert.equal(admin.pageKey, '');
    value = 'languages'; listener();        // dữ liệu về → state đổi
    assert.equal(admin.pageKey, 'languages');

    config.paused();                        // rời view: bỏ theo dõi, không ghi page nữa
    assert.equal(listener, null);
    config.resumed(); value = 'settings'; listener();
    assert.equal(admin.pageKey, 'settings');
    config.destroyed();
    assert.equal(stopped, 2, 'each of the two subscriptions was released');
});
