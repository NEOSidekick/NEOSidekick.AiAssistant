import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {test} from 'vitest';
import {transformSync} from 'esbuild';

const code = transformSync(readFileSync(new URL('./ContentTreeService.ts', import.meta.url), 'utf8'), {
    loader: 'ts', format: 'cjs', target: 'es2020'
}).code;

test('the chat snapshot carries the exact current page context and follows language switches', () => {
    const module = {exports: {}};
    new Function('require', 'module', 'exports', code)(() => ({}), module, module.exports);
    const paths = [
        '/sites/site/contact@user-editor;language=de,en&country=at',
        '/sites/site/contact@user-editor;language=en&country=at',
    ];
    let activePath = paths[0];
    const service = new module.exports.ContentTreeService({getState: () => ({
        cr: {nodes: {documentNode: activePath, byContextPath: {
            [activePath]: {identifier: 'same-document-id', nodeType: 'Neos.Neos:Document', properties: {}, children: []}
        }}}
    })}, {hasRole: () => false});
    for (const path of paths) {
        activePath = path;
        const snapshot = service.getDocumentContentTree();
        assert.equal(snapshot.documentContextPath, path);
        assert.equal(snapshot.rootNode.id, 'same-document-id');
    }
});
