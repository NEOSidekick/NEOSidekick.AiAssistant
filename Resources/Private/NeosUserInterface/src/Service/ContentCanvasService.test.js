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

function setup({cachedNodes = [], response = [targetNode], failure = null} = {}) {
    const dispatched = [];
    const queries = [];
    const action = type => (...args) => ({type, args});
    const actions = {
        UI: {ContentCanvas: {setSrc: action('setSrc')}, FlashMessages: {add: action('flash')}},
        CR: {Nodes: {setDocumentNode: action('setDocumentNode'), merge: action('merge')}}
    };
    const backend = {get: () => ({q: context => ({find: selector => ({get: async () => {
        queries.push({context, selector});
        if (failure) throw failure;
        return response;
    }})})})};
    const module = {exports: {}};
    new Function('require', 'module', 'exports', serviceCode)(name => {
        if (name === '@neos-project/neos-ui-redux-store') return {actions};
        if (name === '@neos-project/neos-ui-backend-connector') return backend;
        throw new Error(`Unexpected runtime dependency: ${name}`);
    }, module, module.exports);

    let listener;
    let currentDocumentNode = documentNode;
    new module.exports.ContentCanvasService(
        {get: () => ({translate: (_key, fallback) => fallback})},
        {
            getState: () => ({cr: {nodes: {
                documentNode: currentDocumentNode,
                byContextPath: Object.fromEntries(cachedNodes.map(node => [node.contextPath, node]))
            }}}),
            dispatch: event => dispatched.push(event)
        },
        {listenToMessages: callback => {listener = callback;}}
    );
    return {
        dispatched, queries,
        changeDocument: path => {currentDocumentNode = path;},
        async click(data = {documentNodeId: identifier, href: `show-document-node://${identifier}`}) {
            listener({data: {version: '1.0', eventName: 'show-document-node', data}});
            await new Promise(resolve => setImmediate(resolve));
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
