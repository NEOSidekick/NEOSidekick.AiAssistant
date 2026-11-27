import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {test} from 'vitest';
import {transformSync} from 'esbuild';

const serviceCode = transformSync(readFileSync(new URL('./ContentCanvasService.ts', import.meta.url), 'utf8'), {
    loader: 'ts', format: 'cjs', target: 'es2020'
}).code;
const identifier = 'cfd828ae-4540-4ad1-852f-36a2a2324fde';
const currentPath = '/sites/example@user-editor;language=de,en&country=at';
const page = {
    identifier,
    nodeType: 'Example:Page',
    contextPath: '/sites/example/contact@user-editor;language=de,en&country=at'
};

function setup({cached = [], response = [page], blocked = false, documentPath = currentPath, failure = false} = {}) {
    const events = [];
    const queries = [];
    const destinations = [];
    const opened = [];
    const listeners = [];
    const popup = {opener: {}, closed: false, close() { this.closed = true; }, location: {replace: url => destinations.push(url)}};
    const fakeWindow = {
        location: {href: 'https://backend.example/cms/neos/content?node=current', origin: 'https://backend.example'},
        open: (...args) => {
            opened.push(args);
            if (blocked) return null;
            if (args[0] !== 'about:blank') destinations.push(args[0]);
            return popup;
        },
        addEventListener: (...args) => listeners.push(args)
    };
    const backend = {get: () => ({endpoints: {getWorkspaceInfo: async () => ({})}, q: context => ({find: selector => ({get: async () => {
        queries.push({context, selector});
        if (failure) throw new Error('lookup failed');
        return response;
    }})})})};
    const module = {exports: {}};
    new Function('require', 'module', 'exports', 'window', serviceCode)(name => {
        if (name === '@neos-project/neos-ui-redux-store') return {
            actions: {
                UI: {FlashMessages: {add: (...args) => args}},
                CR: {Workspaces: {update: info => ({type: 'workspaces-update', info})}, Nodes: {reloadState: options => ({type: 'nodes-reload', options})}},
            },
            actionTypes: {UI: {ContentCanvas: {RELOAD: 'canvas-reload'}}},
        };
        if (name === '@neos-project/neos-ui-backend-connector') return backend;
        throw new Error(`Unexpected runtime dependency: ${name}`);
    }, module, module.exports, fakeWindow);
    let handler;
    const forwarded = [];
    let activePath = documentPath;
    new module.exports.ContentCanvasService(
        {get: name => name === 'i18n'
            ? {translate: (_key, fallback) => fallback}
            : {getRole: () => 'Neos.Neos:Document', getSubTypesOf: () => ['Example:Page']}},
        {getState: () => ({cr: {nodes: {documentNode: activePath, byContextPath: Object.fromEntries(cached.map(n => [n.contextPath, n]))}}}), dispatch: event => events.push(event)},
        {listenToMessages: fn => {handler = fn;}, sendReviewResult: (...args) => forwarded.push(args)}
    );
    return {
        popup, opened, events, queries, destinations, listeners, forwarded,
        postFromPopup(data, {source = popup, origin = 'https://backend.example'} = {}) {
            listeners.find(([type]) => type === 'message')[1]({data, source, origin});
        },
        changePage: path => {activePath = path;},
        async click(id = identifier, reviewSessionId) {
            handler({data: {eventName: 'review-document-node', data: {documentNodeId: id, reviewSessionId}}});
            await new Promise(resolve => setImmediate(resolve));
        },
        async clickDocuments(documents, reviewSessionId) {
            handler({data: {eventName: 'review-document-node', data: {documents, reviewSessionId}}});
            await new Promise(resolve => setImmediate(resolve));
        }
    };
}

function assertPageReview(fixture) {
    assert.equal(fixture.destinations.length, 1);
    const url = new URL(fixture.destinations[0]);
    assert.equal(url.origin, 'https://backend.example');
    assert.equal(url.pathname, '/cms/neos/management/workspaces/show');
    assert.deepEqual([...url.searchParams], [
        ['moduleArguments[workspace][__identity]', 'user-editor'],
        ['moduleArguments[presentation]', 'popup'],
        ['moduleArguments[documents][]', page.contextPath]
    ]);
    assert.match(url.search, /%3B/);
    // Kept: the popup reports its results through it.
    assert.notEqual(fixture.popup.opener, null);
    assert.equal(fixture.opened[0][2], 'popup,width=1200,height=900');
    const focusListener = fixture.listeners.find(([type]) => type === 'focus');
    assert.deepEqual(focusListener[2], {once: true});
    assert.deepEqual(fixture.events, []);
}

test('opens the cached page review in a separate window with the exact workspace and dimensions', async () => {
    const fixture = setup({cached: [page]});
    await fixture.click();
    assertPageReview(fixture);
    assert.deepEqual(fixture.queries, []);
});

test('keeps several pages and languages independent of the active language and preserves fallback commas', async () => {
    const fixture = setup({documentPath: '/sites/example@user-editor;language=fr&country=ch'});
    await fixture.clickDocuments([
        '/sites/example/contact@user-author;language=de,en&country=at',
        '/sites/example/contact@user-author;language=en&country=at',
        '/sites/example/about',
        '/sites/example/about',
    ]);
    const url = new URL(fixture.destinations[0]);
    assert.deepEqual(url.searchParams.getAll('moduleArguments[documents][]'), [
        '/sites/example/contact@user-editor;language=de,en&country=at',
        '/sites/example/contact@user-editor;language=en&country=at',
        '/sites/example/about',
    ]);
    assert.equal(url.searchParams.get('moduleArguments[workspace][__identity]'), 'user-editor');
    assert.equal(fixture.opened[0][0], url.toString());
    assert.match(url.search, /%3Blanguage%3Dde%2Cen%26country%3Dat/);
    assert.deepEqual(fixture.queries, []);
});

test('all-dimension review keeps the page path without inserting the current language', async () => {
    const fixture = setup();
    await fixture.clickDocuments(['/sites/example/contact']);
    const url = new URL(fixture.destinations[0]);
    assert.deepEqual(url.searchParams.getAll('moduleArguments[documents][]'), ['/sites/example/contact']);
});

test('rejects malformed lists and incomplete dimension contexts as a whole', async () => {
    for (const documents of [
        [], null, 'not-an-array', [null],
        ['/sites/example', 'https://evil.example'],
        ['/sites/example@user-editor'],
        ['/sites/example@user-editor;'],
        ['/sites/example@user-editor;language='],
        ['/sites/example@user-editor;language=de,'],
        ['/sites/example@user-editor;language=de&language=en'],
        ['/sites/example@user-editor;language=de&country='],
        ['/sites/example/../other'],
    ]) {
        const fixture = setup();
        await fixture.clickDocuments(documents);
        assert.deepEqual(fixture.opened, []);
        assert.equal(fixture.events.length, 1);
    }
});

test('explicit selections cannot open reviews from the live workspace', async () => {
    const fixture = setup({documentPath: '/sites/example@live;language=de'});
    await fixture.clickDocuments(['/sites/example/contact']);
    assert.deepEqual(fixture.opened, []);
});

test('resolves an uncached page using its UUID in the current context', async () => {
    const fixture = setup({cached: [{...page, contextPath: '/sites/example/contact@live;language=en'}]});
    await fixture.click();
    assertPageReview(fixture);
    assert.deepEqual(fixture.queries, [{context: currentPath, selector: '#' + identifier}]);
});

for (const [label, response] of [
    ['missing page', []],
    ['content element', [{...page, nodeType: 'Example:Text'}]],
    ['other language', [{...page, contextPath: '/sites/example/contact@user-editor;language=en'}]],
    ['other workspace', [{...page, contextPath: '/sites/example/contact@live;language=de,en&country=at'}]],
    ['different page', [{...page, identifier: 'other'}]]
]) {
    test(`never opens an unfiltered workspace review for a ${label}`, async () => {
        const fixture = setup({response});
        await fixture.click();
        assert.deepEqual(fixture.destinations, []);
        assert.equal(fixture.popup.closed, true);
        assert.equal(fixture.events.length, 1);
    });
}

test('closes the placeholder and reports a failed lookup', async () => {
    const fixture = setup({failure: true});
    await fixture.click();
    assert.equal(fixture.popup.closed, true);
    assert.deepEqual(fixture.destinations, []);
});

test('opens the placeholder before waiting and cancels if the active page changes', async () => {
    let resolve;
    const response = new Promise(done => {resolve = done;});
    const fixture = setup({response});
    await fixture.click();
    assert.equal(fixture.opened.length, 1);
    assert.deepEqual(fixture.destinations, []);
    fixture.changePage('/sites/example@other-workspace;language=de');
    resolve([page]);
    await new Promise(done => setImmediate(done));
    assert.equal(fixture.popup.closed, true);
    assert.deepEqual(fixture.destinations, []);
});

test('reports blocked popups without performing a lookup', async () => {
    const fixture = setup({blocked: true});
    await fixture.click();
    assert.equal(fixture.events.length, 1);
    assert.deepEqual(fixture.queries, []);
});

test('rejects non-UUID links and the live workspace before opening a window', async () => {
    for (const id of ['https://evil.example', '/sites/example/contact', '', null]) {
        const fixture = setup();
        await fixture.click(id);
        assert.deepEqual(fixture.opened, []);
    }
    const fixture = setup({documentPath: '/sites/example@live;language=de'});
    await fixture.click();
    assert.deepEqual(fixture.opened, []);
});

const reviewResult = (resultId = 'r1') => ({
    type: 'workspace-review:result',
    version: 1,
    result: {resultId, action: 'published', remainingInScope: 0, scopeResolved: true, changes: []},
});

test('forwards a result of its own review popup to the assistant once and refreshes the editor', async () => {
    const fixture = setup();
    await fixture.clickDocuments(['/sites/example/contact']);
    fixture.postFromPopup(reviewResult());
    fixture.postFromPopup(reviewResult());
    assert.equal(fixture.forwarded.length, 1);
    const [reviewSessionId, result] = fixture.forwarded[0];
    assert.equal(typeof reviewSessionId, 'string');
    assert.equal(result.resultId, 'r1');
    // Workspace info, document tree and canvas are refreshed.
    assert.ok(fixture.events.length > 0);

    fixture.postFromPopup(reviewResult('r2'));
    assert.equal(fixture.forwarded.length, 2);
    assert.equal(fixture.forwarded[1][0], reviewSessionId);
});

test('ignores results from other windows, other origins and unknown messages', async () => {
    const fixture = setup();
    await fixture.clickDocuments(['/sites/example/contact']);
    fixture.postFromPopup(reviewResult(), {source: {}});
    fixture.postFromPopup(reviewResult(), {origin: 'https://evil.example'});
    fixture.postFromPopup({...reviewResult(), version: 2});
    fixture.postFromPopup({type: 'something-else'});
    assert.deepEqual(fixture.forwarded, []);
});

test('returns the chat correlation ID for exact-context and legacy review buttons', async () => {
    const sessionId = 'd5fbb203-84cc-4139-bc22-d3c148c73935';
    for (const legacy of [false, true]) {
        const fixture = setup();
        if (legacy) await fixture.click(identifier, sessionId);
        else await fixture.clickDocuments([page.contextPath], sessionId);
        fixture.postFromPopup(reviewResult());
        assert.equal(fixture.forwarded[0][0], sessionId);
    }
});

test('ignores malformed correlation IDs while keeping the review usable', async () => {
    const fixture = setup();
    await fixture.clickDocuments([page.contextPath], {not: 'a string'});
    fixture.postFromPopup(reviewResult());
    assert.equal(typeof fixture.forwarded[0][0], 'string');
});
