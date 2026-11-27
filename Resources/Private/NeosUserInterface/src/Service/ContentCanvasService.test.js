import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {test} from 'vitest';
import {transformSync} from 'esbuild';

// Neos provides these runtime imports through its host API. Compile the real
// service with the plugin's build tool and supply host adapters at that boundary.
const serviceCode = transformSync(readFileSync(new URL('./ContentCanvasService.ts', import.meta.url), 'utf8'), {
    loader: 'ts', format: 'cjs', target: 'es2020'
}).code;
const identifier = 'cfd828ae-4540-4ad1-852f-36a2a2324fde';
const documentNode = '/sites/example@user-editor;language=es';
const targetNode = {
    identifier,
    contextPath: '/sites/example/references/tunnel@user-editor;language=es',
    uri: '/neos/preview?node=tunnel'
};

const contentDimensions = {
    active: {language: ['es']},
    byName: {language: {presets: {
        es: {label: 'Español', values: ['es']},
        en: {label: 'English', values: ['en']},
        swiss: {label: 'Schweiz', values: ['de_CH', 'de']}
    }}}
};

function setup({cachedNodes = [], response = [targetNode], failure = null, singleNode = null, singleNodeFailure = null} = {}) {
    const dispatched = [];
    const queries = [];
    const singleNodeQueries = [];
    const action = type => (...args) => ({type, args});
    const actions = {
        UI: {
            ContentCanvas: {setSrc: action('setSrc'), requestScrollIntoView: action('scrollIntoView')},
            FlashMessages: {add: action('flash')},
            PageTree: {focus: action('pageTreeFocus')}
        },
        CR: {Nodes: {setDocumentNode: action('setDocumentNode'), merge: action('merge'), focus: action('focus')}}
    };
    const backend = {get: () => ({
        q: context => ({find: selector => ({get: async () => {
            queries.push({context, selector});
            if (failure) throw failure;
            return response;
        }})}),
        endpoints: {getSingleNode: async (identifier, options) => {
            singleNodeQueries.push({identifier, options});
            if (singleNodeFailure) throw singleNodeFailure;
            return singleNode;
        }}
    })};
    const module = {exports: {}};
    new Function('require', 'module', 'exports', serviceCode)(name => {
        if (name === '@neos-project/neos-ui-redux-store') return {actions};
        if (name === '@neos-project/neos-ui-backend-connector') return backend;
        throw new Error(`Unexpected runtime dependency: ${name}`);
    }, module, module.exports);

    let listener;
    let currentDocumentNode = documentNode;
    new module.exports.ContentCanvasService(
        {get: () => ({translate: (key, fallback) => fallback ?? key})},
        {
            getState: () => ({cr: {
                nodes: {
                    documentNode: currentDocumentNode,
                    byContextPath: Object.fromEntries(cachedNodes.map(node => [node.contextPath, node]))
                },
                contentDimensions,
                workspaces: {personalWorkspace: {name: 'user-editor'}}
            }}),
            dispatch: event => dispatched.push(event)
        },
        {listenToMessages: callback => {listener = callback;}}
    );
    return {
        dispatched, queries, singleNodeQueries,
        changeDocument: path => {currentDocumentNode = path;},
        async click(data = {documentNodeId: identifier, href: `show-document-node://${identifier}`}) {
            listener({data: {version: '1.0', eventName: 'show-document-node', data}});
            await new Promise(resolve => setImmediate(resolve));
        },
        focusContent(contentNodeId) {
            listener({data: {version: '1.0', eventName: 'show-content-node', data: {contentNodeId}}});
        }
    };
}

function assertNavigated(dispatched) {
    const error = dispatched.find(event => event.type === 'flash');
    assert.equal(error, undefined, error?.args[1]);
    assert.deepEqual(dispatched.filter(event => event.type !== 'merge'), [
        {type: 'setSrc', args: [targetNode.uri]},
        {type: 'setDocumentNode', args: [targetNode.contextPath]}
    ]);
}

test('opens an already cached document without a backend query', async () => {
    const fixture = setup({cachedNodes: [targetNode]});
    await fixture.click();
    assertNavigated(fixture.dispatched);
    assert.deepEqual(fixture.queries, []);
});

test('resolves a valid UUID absent from the UI cache in the current language and workspace', async () => {
    const fixture = setup();
    await fixture.click();
    assertNavigated(fixture.dispatched);
    assert.deepEqual(fixture.queries, [{context: documentNode, selector: `#${identifier}`}]);
    assert.deepEqual(fixture.dispatched[0], {type: 'merge', args: [{[targetNode.contextPath]: targetNode}]});
});

test('reloads a cached node that has no preview URI', async () => {
    const fixture = setup({cachedNodes: [{...targetNode, uri: undefined}]});
    await fixture.click();
    assertNavigated(fixture.dispatched);
    assert.equal(fixture.queries.length, 1);
});

test('does not navigate to a cached language or workspace variant of the same UUID', async () => {
    const fixture = setup({cachedNodes: [{...targetNode, contextPath: '/sites/example/references/tunnel@live;language=en', uri: '/english'}]});
    await fixture.click();
    assertNavigated(fixture.dispatched);
    assert.equal(fixture.queries.length, 1);
});

test('also resolves legacy document paths that are not cached', async () => {
    const fixture = setup();
    await fixture.click({documentNodeId: 'document-node-path:///sites/example/references/tunnel'});
    assertNavigated(fixture.dispatched);
    assert.deepEqual(fixture.queries, [{context: documentNode, selector: '/sites/example/references/tunnel'}]);
});

test('supports the href fallback with the current link protocol', async () => {
    const fixture = setup();
    await fixture.click({href: `show-document-node://${identifier}`});
    assertNavigated(fixture.dispatched);
    assert.deepEqual(fixture.queries, [{context: documentNode, selector: `#${identifier}`}]);
});

test('a slow lookup cannot override a later click', async () => {
    let resolve;
    const response = new Promise(done => {resolve = done;});
    const otherNode = {...targetNode, identifier: '4dbbf4c9-8c83-49f2-b35a-759d96e9b113', contextPath: '/sites/example/other@user-editor;language=es', uri: '/other'};
    const fixture = setup({cachedNodes: [otherNode], response});
    await fixture.click();
    await fixture.click({documentNodeId: otherNode.identifier});
    resolve([targetNode]);
    await new Promise(done => setImmediate(done));
    assert.deepEqual(fixture.dispatched, [
        {type: 'setSrc', args: [otherNode.uri]},
        {type: 'setDocumentNode', args: [otherNode.contextPath]}
    ]);
});

test('a slow lookup cannot override a manual page or dimension change', async () => {
    let resolve;
    const response = new Promise(done => {resolve = done;});
    const fixture = setup({response});
    await fixture.click();
    fixture.changeDocument('/sites/example@user-editor;language=en');
    resolve([targetNode]);
    await new Promise(done => setImmediate(done));
    assert.deepEqual(fixture.dispatched, []);
});

for (const [label, options] of [
    ['missing document', {response: []}],
    ['missing preview URI', {response: [{...targetNode, uri: ''}]}],
    ['backend rejection', {failure: new Error('Request failed')}]
]) {
    test(`reports a resolution error without navigating on ${label}`, async () => {
        const fixture = setup(options);
        await fixture.click();
        assert.equal(fixture.queries.length, 1);
        assert.equal(fixture.dispatched.filter(event => event.type === 'setSrc').length, 0);
        const errors = fixture.dispatched.filter(event => event.type === 'flash');
        assert.equal(errors.length, 1);
        assert.equal(errors[0].args[1], `An error occurred while asking NEOSidekick: Could not resolve document node path: ${identifier}`);
    });
}

const englishNode = {
    nodeFound: true,
    nodeFrontendUri: '/neos/preview?node=tunnel-en',
    nodeContextPath: '/sites/example/references/tunnel@user-editor;language=en'
};

test('opens the page in another language with the preset chain in the personal workspace', async () => {
    const fixture = setup({singleNode: englishNode});
    await fixture.click({documentNodeId: `${identifier}?language=en`});
    assert.deepEqual(fixture.singleNodeQueries, [
        {identifier, options: {dimensions: {language: ['en']}, workspaceName: 'user-editor'}}
    ]);
    assert.deepEqual(fixture.dispatched, [
        {type: 'setSrc', args: [englishNode.nodeFrontendUri]},
        {type: 'pageTreeFocus', args: [englishNode.nodeContextPath]}
    ]);
    assert.deepEqual(fixture.queries, []);
});

test('matches a dimension value when it is not a preset key', async () => {
    const fixture = setup({singleNode: englishNode});
    await fixture.click({documentNodeId: `${identifier}?language=de_CH`});
    assert.deepEqual(fixture.singleNodeQueries[0].options.dimensions, {language: ['de_CH', 'de']});
});

test('uses the regular lookup when the link names the active dimensions', async () => {
    const fixture = setup();
    await fixture.click({documentNodeId: `${identifier}?language=es`});
    assertNavigated(fixture.dispatched);
    assert.deepEqual(fixture.singleNodeQueries, []);
});

test('rejects a language that is not configured without navigating', async () => {
    const fixture = setup({singleNode: englishNode});
    await fixture.click({documentNodeId: `${identifier}?language=fr`});
    assert.deepEqual(fixture.singleNodeQueries, []);
    assert.deepEqual(fixture.dispatched.map(event => event.type), ['flash']);
});

test('reports a page that has no variant in the requested language', async () => {
    const fixture = setup({singleNode: {nodeFound: false}});
    await fixture.click({documentNodeId: `${identifier}?language=en`});
    assert.deepEqual(fixture.dispatched.map(event => event.type), ['flash']);
    assert.equal(fixture.dispatched[0].args[1], 'This page does not exist in English yet.');
});

test('leaves a failed variant lookup to the backend error handler', async () => {
    const fixture = setup({singleNodeFailure: new Error('Request failed')});
    await fixture.click({documentNodeId: `${identifier}?language=en`});
    assert.deepEqual(fixture.dispatched, []);
});

test('a slow variant lookup cannot override a manual page or dimension change', async () => {
    let resolve;
    const fixture = setup({singleNode: new Promise(done => {resolve = done;})});
    const click = fixture.click({documentNodeId: `${identifier}?language=en`});
    fixture.changeDocument('/sites/example@user-editor;language=en');
    resolve(englishNode);
    await click;
    await new Promise(done => setImmediate(done));
    assert.deepEqual(fixture.dispatched, []);
});

test('focuses only content nodes of the active dimensions', () => {
    const contentIdentifier = '0b2f4f8e-0c55-4d57-9c43-3b7c3f0ad0e1';
    const otherLanguage = {identifier: contentIdentifier, contextPath: '/sites/example/main/text@user-editor;language=en'};
    const activeLanguage = {identifier: contentIdentifier, contextPath: '/sites/example/main/text@user-editor;language=es'};
    const fixture = setup({cachedNodes: [otherLanguage, activeLanguage]});
    fixture.focusContent(contentIdentifier);
    assert.deepEqual(fixture.dispatched[0], {type: 'focus', args: [activeLanguage.contextPath]});
});
