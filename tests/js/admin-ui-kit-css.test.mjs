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

test('row action icon buttons share one size and hover standard', () => {
    // Standard: 30x30, 4px radius; hover is --ui-color-eef0fb (danger: --ui-danger-soft). No other size may be declared.
    const sizes = new Map();
    const hovers = new Map();
    ast.walkRules(rule => {
        for (const selector of rule.selectors) {
            const match = selector.trim().match(/\.(ux-row-edit|ux-row-delete|ui-row-action)(--danger)?(:hover(?::not\([^)]*\))?)?$/);
            if (!match || selector.includes('[data-tooltip')) continue;
            rule.walkDecls(decl => {
                if (!match[3] && ['width', 'height', 'min-width', 'min-height'].includes(decl.prop) && !selector.includes(':disabled')) {
                    sizes.set(`${match[1]}${match[2] || ''} ${decl.prop}`, decl.value.trim());
                }
                if (match[3] && decl.prop === 'background') hovers.set(`${match[1]}${match[2] || ''}`, decl.value.trim());
            });
        }
    });
    assert.ok(sizes.size >= 4, 'row action sizes are declared');
    for (const [key, value] of sizes) assert.equal(value, '30px', key);
    for (const [key, value] of hovers) {
        const danger = key === 'ux-row-delete' || key.endsWith('--danger');
        assert.equal(value, danger ? 'var(--ui-danger-soft)' : 'var(--ui-color-eef0fb)', key);
    }
});

test('Tabs share one type size across forms', () => {
    const read = name => fs.readFileSync(new URL(`../../resources/css/admin/${name}`, import.meta.url), 'utf8');
    const declared = (source, selector, prop) => {
        const values = [];
        postcss.parse(source).walkRules(rule => {
            if (rule.selectors.includes(selector)) rule.walkDecls(prop, decl => values.push(decl.value));
        });
        return values;
    };
    assert.match(declared(css, '.lf-admin-ui.ui-structure .course-template-structure-tabs button', 'font')[0], /^400 14px\//);
    assert.deepEqual(declared(css, '.lf-admin-ui.ui-structure .course-template-activity-tabs', 'font-size'), ['14px']);
    assert.deepEqual(declared(read('admin-components.css'), '.course-cohort-detail-tabs .btn', 'font-size'), []);
});

test('List titles and detail titles share one size', () => {
    const declared = (selector, prop) => {
        const values = [];
        ast.walkRules(rule => {
            if (rule.selectors.includes(selector)) rule.walkDecls(prop, decl => values.push(decl.value));
        });
        return values;
    };
    assert.match(declared('.lf-admin-ui .ui-page-head__title', 'font')[0], /^600 20px\//);
    assert.deepEqual(declared('.lf-admin-ui .ui-version__identity h2', 'font-size'), ['20px']);
});
