import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import postcss from 'postcss';

const css = fs.readFileSync(new URL('../../resources/css/admin/admin-ui-kit.css', import.meta.url), 'utf8');
const ast = postcss.parse(css);

test('UI Kit rules cannot style non-opt-in pages', () => {
    ast.walkRules(rule => {
        assert.ok(rule.selectors.every(s => s.trim().startsWith('.lf-admin-ui')), rule.selector);
        assert.ok(!rule.selector.includes('#ui-catalog-soft'), 'Root must support multiple instances');
    });
});

test('UI Kit presentation colors use root-scoped tokens', () => {
    ast.walkDecls(decl => {
        if (!decl.prop.startsWith('--')) {
            assert.doesNotMatch(decl.value.replace(/var\([^)]*\)/g, ''), /#[a-f\d]{3,8}\b|\bwhite\b/i);
        }
    });
});

test('UI Kit stays out of existing production style entries', () => {
    for (const name of ['backend.css', 'auth.css', 'tenant-site.css', 'app.css']) {
        const entry = fs.readFileSync(new URL(`../../resources/css/${name}`, import.meta.url), 'utf8');
        assert.ok(!entry.includes('admin-ui-kit'), name);
    }
    const vite = fs.readFileSync(new URL('../../vite.config.js', import.meta.url), 'utf8');
    assert.ok(vite.includes("'resources/css/admin/admin-ui-kit.css'"));
});
