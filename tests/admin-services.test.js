const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const { test } = require('node:test');
const vm = require('node:vm');

const source = readFileSync(join(__dirname, '..', 'public', 'ui', '_assets', 'js', 'admin-services.js'), 'utf8');
const settle = () => new Promise(resolve => setImmediate(resolve));

async function setup({ only = true, status = 200, saveStatus = 200, previewStatus = 200, previewHtml = '<h2>Preview</h2>', previewResponder, initialProjectId = null } = {}) {
    const calls = [];
    const previewCalls = [];
    const timers = new Map();
    let timerId = 0;
    const notifications = [];
    const paths = [];
    const listeners = {};
    const location = { pathname: initialProjectId ? '/referenced-projects/' + initialProjectId : (only ? '/referenced-projects' : '/services') };
    const elements = {};
    let modal;
    let closed = false;
    const project = {
        id: 7, title: 'Test project', slug: 'test', project_slug: 'project-test',
        structure: [{ type: 'hero', options: {} }],
        data: { title: '</textarea><script>alert(1)</script>', nested: {}, items: [] },
        is_active: true,
    };
    const row = { getAttribute: () => '7', addEventListener: (_, handler) => { row.click = handler; } };
    const create = { addEventListener: (_, handler) => { create.click = handler; } };
    const root = {
        innerHTML: '',
        querySelectorAll: selector => selector === '[data-referenced-project-row]' ? [row] : [],
        querySelector: selector => selector === '[data-referenced-project-create]' ? create : null,
    };
    vm.runInNewContext(source, {
        setTimeout: fn => { timers.set(++timerId, fn); return timerId; },
        clearTimeout: id => timers.delete(id),
        document: { getElementById: id => id === 'adminServicesRoot' ? root : elements[id] },
        window: {
            location,
            addEventListener: (name, handler) => { listeners[name] = handler; },
            __ADMIN_SERVICES_CONFIG: {
                can_manage_services: true, referenced_projects_only: only,
                initial_referenced_project_id: initialProjectId,
                api: {
                    services: { list: '/services/data' },
                    referenced_projects: {
                        list: '/referenced-projects/data', create: '/referenced-projects/data',
                        update: '/referenced-projects/data/{id}',
                        preview: '/referenced-projects/preview',
                    },
                },
            },
            history: {
                pushState: (_, __, path) => { paths.push(path); location.pathname = path; },
                replaceState: (_, __, path) => { location.pathname = path; },
            },
            adminShowNotification: (type, message) => notifications.push({ type, message }),
            adminCloseModal: () => {
                closed = true;
                delete elements.referencedProjectPreview;
                delete elements.referencedProjectPreviewStatus;
                if (modal && modal.options.onClose) {
                    modal.options.onClose();
                }
            },
            adminOpenModal: (title, body, options) => {
                modal = { title, body, options };
                for (const match of body.matchAll(/<input id="([^"]+)"[^>]*>/g)) {
                    elements[match[1]] = {
                        value: (match[0].match(/value="([^"]*)"/) || [null, ''])[1],
                        checked: match[0].includes(' checked'),
                    };
                }
                for (const match of body.matchAll(/<textarea id="([^"]+)"[^>]*>([\s\S]*?)<\/textarea>/g)) {
                    elements[match[1]] = {
                        value: match[2].replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"')
                            .replace(/&#039;/g, "'").replace(/&amp;/g, '&'),
                    };
                }
                for (const element of Object.values(elements)) {
                    element.addEventListener = (name, handler) => { element[name] = handler; };
                }
                if (body.includes('id="referencedProjectPreview"')) {
                    elements.referencedProjectPreview = { hidden: true };
                    elements.referencedProjectPreviewStatus = { textContent: '' };
                }
            },
        },
        fetch: async (url, options) => {
            if (url === '/referenced-projects/preview') {
                previewCalls.push({ url, options });
                if (previewResponder) {
                    return previewResponder(previewCalls.length);
                }
                return {
                    status: previewStatus, ok: previewStatus < 400,
                    json: async () => ({ data: { html: previewHtml } }),
                };
            }
            calls.push({ url, options });
            const responseStatus = options.method ? saveStatus : status;
            return {
                status: responseStatus, ok: responseStatus < 400,
                json: async () => ({ data: { services: [], referenced_projects: [project] } }),
            };
        },
    });
    await settle();
    return {
        calls, previewCalls, notifications, paths, elements, row, create, root, location, listeners,
        flushTimers: () => {
            const pending = [...timers.values()];
            timers.clear();
            pending.forEach(fn => fn());
        },
        get modal() { return modal; },
        get closed() { return closed; },
    };
}

test('dedicated section loads only reference projects and opens the JSON editor', async () => {
    const app = await setup();
    assert.deepEqual(app.calls.map(call => call.url), ['/referenced-projects/data']);
    assert.equal(app.notifications.length, 0);
    assert.ok(!app.root.innerHTML.includes('<h2 class="admin-services-section-title">Services</h2>'));
    app.row.click();
    assert.equal(app.paths.at(-1), '/referenced-projects/7');
    assert.equal(app.elements.referencedProjectStructure.value, '[\n  {\n    "type": "hero",\n    "options": {}\n  }\n]');
    assert.ok(app.modal.body.includes('&lt;/textarea&gt;&lt;script&gt;'));
    assert.deepEqual(JSON.parse(app.elements.referencedProjectData.value).nested, {});
});

test('existing services page still loads and renders both sections', async () => {
    const app = await setup({ only: false });
    assert.equal(app.calls.length, 2);
    assert.ok(app.root.innerHTML.includes('<h2 class="admin-services-section-title">Services</h2>'));
    app.row.click();
    assert.equal(app.paths.at(-1), '/referenced-projects/7');
});

test('invalid JSON and root shapes prevent saving without closing the editor', async () => {
    const app = await setup();
    app.row.click();
    for (const [structure, data] of [['{', '{}'], ['{}', '{}'], ['[]', 'null'], ['[]', '42'], ['[]', '']]) {
        app.elements.referencedProjectStructure.value = structure;
        app.elements.referencedProjectData.value = data;
        app.modal.options.buttons[0].onClick();
        assert.equal(app.calls.length, 1);
        assert.equal(app.notifications.at(-1).type, 'error');
        assert.equal(app.closed, false);
    }
});

test('saving sends both JSON fields as strings and reloads the persistent data', async () => {
    const app = await setup();
    app.row.click();
    const structure = '[{"type":"hero","options":{}}]';
    const data = '{"nested":{},"items":[],"title":"Updated"}';
    app.elements.referencedProjectStructure.value = structure;
    app.elements.referencedProjectData.value = data;
    app.modal.options.buttons[0].onClick();
    await settle();
    assert.equal(app.calls[1].url, '/referenced-projects/data/7');
    assert.equal(app.calls[1].options.method, 'PATCH');
    const payload = JSON.parse(app.calls[1].options.body);
    assert.equal(payload.structure, structure);
    assert.equal(payload.data, data);
    assert.equal(payload.title, 'Test project');
    assert.equal(payload.project_slug, 'project-test');
    assert.equal(payload.is_active, true);
    assert.equal(app.calls[2].url, '/referenced-projects/data');
    assert.equal(app.closed, true);
    assert.equal(app.notifications.at(-1).type, 'success');
});

test('new projects start with empty JSON and use POST', async () => {
    const app = await setup();
    app.create.click();
    assert.equal(app.elements.referencedProjectStructure.value, '[]');
    assert.equal(app.elements.referencedProjectData.value, '{}');
    app.modal.options.buttons[0].onClick();
    await settle();
    assert.equal(app.calls[1].options.method, 'POST');
    assert.equal(app.calls[1].url, '/referenced-projects/data');
});

test('load failures are explicitly reported', async () => {
    const app = await setup({ status: 403 });
    assert.equal(app.notifications.length, 1);
    assert.equal(app.notifications[0].type, 'error');
    assert.match(app.notifications[0].message, /Referenzprojekte/);
});

test('save failures retain the editor and display an error', async () => {
    const app = await setup({ saveStatus: 422 });
    app.row.click();
    app.modal.options.buttons[0].onClick();
    await settle();
    assert.equal(app.calls.length, 2);
    assert.equal(app.closed, false);
    assert.equal(app.notifications.at(-1).type, 'error');
    assert.match(app.notifications.at(-1).message, /nicht gespeichert/);
});

test('opening the editor renders unsaved JSON in an isolated preview', async () => {
    const app = await setup();
    app.row.click();
    await settle();
    assert.ok(app.modal.body.includes('sandbox=""'));
    assert.equal(app.previewCalls.length, 1);
    assert.equal(app.previewCalls[0].options.method, 'POST');
    const payload = JSON.parse(app.previewCalls[0].options.body);
    assert.equal(JSON.parse(payload.structure)[0].type, 'hero');
    assert.equal(payload.title, 'Test project');
    assert.equal(app.elements.referencedProjectPreview.srcdoc, '<h2>Preview</h2>');
    assert.equal(app.elements.referencedProjectPreview.hidden, false);
    assert.equal(app.calls.length, 1);
});

test('preview updates are debounced and invalid JSON hides the stale preview', async () => {
    const app = await setup();
    app.row.click();
    await settle();
    app.elements.referencedProjectData.value = '{"title":"Unsaved update"}';
    app.elements.referencedProjectData.input();
    app.elements.referencedProjectTitle.input();
    assert.equal(app.previewCalls.length, 1);
    app.flushTimers();
    await settle();
    assert.equal(app.previewCalls.length, 2);
    assert.equal(JSON.parse(JSON.parse(app.previewCalls[1].options.body).data).title, 'Unsaved update');
    app.elements.referencedProjectStructure.value = '{}';
    app.elements.referencedProjectStructure.input();
    app.flushTimers();
    await settle();
    assert.equal(app.previewCalls.length, 2);
    assert.equal(app.elements.referencedProjectPreview.hidden, true);
    assert.match(app.elements.referencedProjectPreviewStatus.textContent, /JSON-Array/);
    assert.equal(app.calls.length, 1);
});

test('preview errors and malformed responses are displayed without discarding JSON', async () => {
    for (const options of [{ previewStatus: 500 }, { previewHtml: '' }]) {
        const app = await setup(options);
        app.row.click();
        await settle();
        assert.equal(app.elements.referencedProjectPreview.hidden, true);
        assert.match(app.elements.referencedProjectPreviewStatus.textContent, /Vorschau/);
        assert.ok(app.elements.referencedProjectData.value.includes('nested'));
    }
});

test('out-of-order responses cannot replace a newer preview', async () => {
    const pending = [];
    const app = await setup({
        previewResponder: () => new Promise(resolve => pending.push(resolve)),
    });
    app.row.click();
    app.elements.referencedProjectData.value = '{"title":"New"}';
    app.elements.referencedProjectData.input();
    app.flushTimers();
    const response = html => ({ status: 200, ok: true, json: async () => ({ data: { html } }) });
    pending[1](response('<h2>New</h2>'));
    await settle();
    pending[0](response('<h2>Old</h2>'));
    await settle();
    assert.equal(app.elements.referencedProjectPreview.srcdoc, '<h2>New</h2>');
});

test('closing the modal prevents pending preview requests', async () => {
    const app = await setup();
    app.row.click();
    app.elements.referencedProjectData.input();
    app.modal.options.buttons[1].onClick();
    app.flushTimers();
    await settle();
    assert.equal(app.previewCalls.length, 1);
    assert.equal(app.closed, true);
});

test('a project ID from the deep link opens the editor on initial load', async () => {
    const app = await setup({ initialProjectId: 7 });
    assert.equal(app.modal.title, 'Referenzprojekt #7');
    assert.equal(app.elements.referencedProjectTitle.value, 'Test project');
    assert.equal(app.paths.length, 0);
    assert.equal(app.location.pathname, '/referenced-projects/7');
    assert.equal(app.previewCalls.length, 1);
});

test('unknown deep-link IDs report a clear error without opening a new project', async () => {
    const app = await setup({ initialProjectId: 999 });
    assert.equal(app.modal, undefined);
    assert.equal(app.notifications.at(-1).type, 'error');
    assert.match(app.notifications.at(-1).message, /#999.*nicht gefunden/);
});

test('failed deep-link loads report loading errors rather than missing projects', async () => {
    const app = await setup({ initialProjectId: 7, status: 403 });
    assert.equal(app.modal, undefined);
    assert.equal(app.notifications.length, 1);
    assert.match(app.notifications[0].message, /nicht geladen/);
});

test('closing a project editor restores the list URL', async () => {
    const app = await setup({ initialProjectId: 7 });
    app.modal.options.buttons[1].onClick();
    assert.equal(app.location.pathname, '/referenced-projects');
    assert.equal(app.closed, true);
});

test('browser back and forward synchronize the project editor with the ID', async () => {
    const app = await setup();
    app.row.click();
    assert.equal(app.location.pathname, '/referenced-projects/7');
    app.location.pathname = '/referenced-projects';
    app.listeners.popstate();
    assert.equal(app.elements.referencedProjectPreview, undefined);
    app.location.pathname = '/referenced-projects/7';
    app.listeners.popstate();
    assert.equal(app.modal.title, 'Referenzprojekt #7');
    assert.ok(app.elements.referencedProjectPreview);
    assert.equal(app.paths.length, 1);
});

test('saving a deep-linked project closes the editor without reopening it on reload', async () => {
    const app = await setup({ initialProjectId: 7 });
    app.modal.options.buttons[0].onClick();
    await settle();
    assert.equal(app.calls[1].url, '/referenced-projects/data/7');
    assert.equal(app.calls[1].options.method, 'PATCH');
    assert.equal(app.location.pathname, '/referenced-projects');
    assert.equal(app.elements.referencedProjectPreview, undefined);
    assert.equal(app.previewCalls.length, 1);
});
