/**
 * Phase 9 — اختبار التنقّل الجزئي لترقيم صفحات مخزون الصيدلية، بدون متصفح.
 *
 * يُحمَّل `resources/js/pharmacy/inventory-nav.js` الحقيقي (لا نسخة) داخل vm sandbox
 * مع بدائل لـwindow/document/history/fetch/DOMParser، ثم نتحقق من:
 *   1) اعتراض click على رابط ترقيم داخل النطاق (منع التنقّل الكامل).
 *   2) pushState يحصل **فورًا** قبل وصول الاستجابة (URL يتغيّر مبكرًا).
 *   3) loading state يظهر فورًا (قبل الاستجابة).
 *   4) استبدال المحتوى فقط عند وصول HTML (لا reload كامل).
 *   5) popstate ⇒ fetch بلا pushState.
 *   6) فشل fetch ⇒ fallback حقيقي (window.location.href = url).
 *   7) الأمان: credentials:'same-origin' + GET + Accept:text/html دائمًا.
 *   8) النطاق: روابط/مسارات خارج /pharmacy/inventory لا تُعترَض.
 *
 * التشغيل:  node tests/js/inventory-partial-nav.test.mjs
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import vm from 'node:vm';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const SRC = join(ROOT, 'resources/js/pharmacy/inventory-nav.js');

let passed = 0;
const failures = [];

function ok(name, cond, extra = '') {
    if (cond) {
        passed++;
        console.log(`  \u2713 ${name}`);
    } else {
        failures.push(name);
        console.log(`  \u2717 ${name}${extra ? '  \u2014 ' + extra : ''}`);
    }
}

/* ---------------- minimal DOM ---------------- */

    class FakeEl {
        constructor(tag = 'div') {
        this.tagName = tag.toUpperCase();
        this.children = [];
        this.parentNode = null;
        this.style = {};
        this.dataset = {};
        this.attributes = {};
        this._listeners = {};
        this.textContent = '';
        this.id = '';
        this._html = '';
    }
    setAttribute(k, v) { this.attributes[k] = v; }
    getAttribute(k) { return Object.prototype.hasOwnProperty.call(this.attributes, k) ? this.attributes[k] : null; }
    removeAttribute(k) { delete this.attributes[k]; }
    addEventListener(t, fn) { (this._listeners[t] = this._listeners[t] || []).push(fn); }
    dispatch(t, ev) { (this._listeners[t] || []).forEach((fn) => fn(ev || {})); }
    appendChild(c) { this.children.push(c); c.parentNode = this; return c; }
    insertBefore(c) { this.children.unshift(c); c.parentNode = this; return c; }
    removeChild(c) { this.children = this.children.filter((x) => x !== c); if (typeof this.__onRemove === 'function') this.__onRemove(c); return c; }
    replaceWith(c) {
        if (this.parentNode) {
            const i = this.parentNode.children.indexOf(this);
            if (i !== -1) this.parentNode.children[i] = c;
            c.parentNode = this.parentNode;
        }
    }
    getBoundingClientRect() { return { top: 100 }; }
    querySelectorAll() { return []; }
    set innerHTML(v) { this._html = v; }
    get innerHTML() { return this._html; }
}

/* ---------------- harness ---------------- */

function makeEnv() {
    const mainContent = new FakeEl('main');
    const content = new FakeEl('div');
    content.id = 'inventory-content';
    mainContent.appendChild(content);

    const paginationLink = new FakeEl('a');
    paginationLink.setAttribute('href', '/pharmacy/inventory?page=2');
    content.querySelectorAll = (sel) =>
        (String(sel).indexOf('pagination') !== -1 || String(sel).indexOf('navigation') !== -1)
            ? [paginationLink] : [];

    const doc = {
        readyState: 'complete',
        _byId: { 'inventory-content': content },
        _listeners: {},
        getElementById(id) { return this._byId[id] || null; },
        createElement(tag) {
            const el = new FakeEl(tag);
            // mimic a real DOM: an element with an id becomes findable by id once inserted
            const origInsert = el.insertBefore.bind(el);
            el.insertBefore = (c) => {
                if (c && c.id) this._byId[c.id] = c;
                return origInsert(c);
            };
            const origAppend = el.appendChild.bind(el);
            el.appendChild = (c) => {
                if (c && c.id) this._byId[c.id] = c;
                return origAppend(c);
            };
            return el;
        },
        querySelector() { return null; },
        querySelectorAll() { return []; },
        head: new FakeEl('head'),
        title: 'old title',
        addEventListener(t, fn) { (this._listeners[t] = this._listeners[t] || []).push(fn); },
        dispatch(t, ev) { (this._listeners[t] || []).forEach((fn) => fn(ev || {})); },
    };
    // the inventory container itself must register on insert (the source inserts the overlay into its parent)
    content.parentNode = mainContent;
    mainContent.insertBefore = (c) => { if (c && c.id) doc._byId[c.id] = c; mainContent.children.unshift(c); c.parentNode = mainContent; return c; };
    mainContent.__onRemove = (c) => { if (c && c.id && doc._byId[c.id] === c) delete doc._byId[c.id]; };

    const calls = { fetch: [], pushState: [], locationHref: [] };
    const loc = { pathname: '/pharmacy/inventory', search: '', origin: 'https://daway.test' };
    Object.defineProperty(loc, 'href', {
        set(v) { calls.locationHref.push(v); },
        get() { return calls.locationHref[calls.locationHref.length - 1] || ''; },
    });

    const win = {
        location: loc,
        history: { pushState(state, title, url) { calls.pushState.push(url); } },
        pageYOffset: 0,
        scrollTo() {},
        _listeners: {},
        addEventListener(t, fn) { (win._listeners[t] = win._listeners[t] || []).push(fn); },
        dispatch(t, ev) { (win._listeners[t] || []).forEach((fn) => fn(ev || {})); },
    };

    const pending = [];
    function fetchStub(url, opts) {
        calls.fetch.push({ url, opts });
        return new Promise((resolve, reject) => { pending.push({ url, opts, resolve, reject }); });
    }

    class FakeParser {
        parseFromString(html) {
            const c = new FakeEl('div');
            c.id = 'inventory-content';
            c._html = html;
            c.querySelectorAll = () => [];
            return {
                getElementById(id) { return id === 'inventory-content' ? c : null; },
                querySelector() { return { textContent: 'new title' }; },
            };
        }
    }

    const sandbox = {
        window: win,
        document: doc,
        DOMParser: FakeParser,
        fetch: fetchStub,
        URL,
        AbortController: class {
            constructor() { this.signal = { aborted: false }; }
            abort() { this.signal.aborted = true; }
        },
        Array, Object, Promise, RegExp, Error, console, String, Math, Date,
    };
    vm.createContext(sandbox);
    vm.runInContext(readFileSync(SRC, 'utf8'), sandbox, { filename: 'inventory-nav.js' });

    return { sandbox, win, doc, calls, pending, content, paginationLink, mainContent };
}

const tick = () => new Promise((r) => setTimeout(r, 0));

/* ---------------- tests ---------------- */

console.log('\nPhase 9 \u2014 inventory partial navigation\n');

/* 1) intercept + pushState early + loading state */
{
    const { calls, pending, paginationLink, doc } = makeEnv();
    paginationLink.dispatch('click', { preventDefault() {}, metaKey: false, ctrlKey: false, shiftKey: false, altKey: false, button: 0 });
    ok('1a) click intercepted (fetch started)', calls.fetch.length === 1);
    ok('1b) pushState happened immediately', calls.pushState.length === 1 && calls.pushState[0] === '/pharmacy/inventory?page=2');
    ok('1c) loading state shown immediately (before response)', !!doc.getElementById('inventory-nav-overlay'));
    ok('1d) no immediate full reload', calls.locationHref.length === 0);
}

/* 2) content replaced after response; overlay removed; title updated */
{
    const { calls, pending, paginationLink, doc } = makeEnv();
    paginationLink.dispatch('click', { preventDefault() {}, button: 0 });
    pending[0].resolve({ ok: true, text: () => Promise.resolve('<div id="inventory-content">new</div>') });
    await tick(); await tick(); await tick();
    ok('2a) overlay removed after success', !doc.getElementById('inventory-nav-overlay'));
    ok('2b) no full page reload on success', calls.locationHref.length === 0);
    ok('2c) title updated from response', doc.title === 'new title');
}

/* 3) popstate => fetch without pushState */
{
    const { win, calls } = makeEnv();
    win.location.search = '?page=3';
    win.dispatch('popstate', {});
    ok('3a) popstate triggers fetch', calls.fetch.length === 1);
    ok('3b) popstate does NOT pushState', calls.pushState.length === 0);
}

/* 4) failed fetch => real fallback, no stuck loading */
{
    const { calls, pending, paginationLink, doc } = makeEnv();
    paginationLink.dispatch('click', { preventDefault() {}, button: 0 });
    pending[0].resolve({ ok: false, status: 500, text: () => Promise.resolve('') });
    await tick(); await tick(); await tick();
    ok('4a) failed fetch falls back to full navigation', calls.locationHref.length === 1 && calls.locationHref[0] === '/pharmacy/inventory?page=2');
    ok('4b) overlay removed on failure (no stuck loading)', !doc.getElementById('inventory-nav-overlay'));
}

/* 5) security: request shape */
{
    const { calls, paginationLink } = makeEnv();
    paginationLink.dispatch('click', { preventDefault() {}, button: 0 });
    const o = calls.fetch[0].opts || {};
    ok('5a) credentials: same-origin', o.credentials === 'same-origin');
    ok('5b) method GET only', o.method === 'GET');
    ok('5c) Accept: text/html', o.headers && o.headers.Accept === 'text/html');
    ok('5d) X-Requested-With set', o.headers && o.headers['X-Requested-With'] === 'XMLHttpRequest');
    ok('5e) no cache option forced (no shared cache)', o.cache === undefined);
}

/* 6) scope: out-of-scope path not fetched */
{
    const { sandbox, calls } = makeEnv();
    sandbox.window.DawayInventoryNav.navigate('/pharmacy/accounting', true);
    ok('6a) out-of-scope URL not fetched', calls.fetch.length === 0);
}

/* 7) race protection: rapid page2 then page3 */
{
    const { sandbox, calls, pending } = makeEnv();
    sandbox.window.DawayInventoryNav.navigate('/pharmacy/inventory?page=2', true);
    const first = pending[0];
    sandbox.window.DawayInventoryNav.navigate('/pharmacy/inventory?page=3', true);
    const second = pending[1];
    ok('7a) two separate requests issued', calls.fetch.length === 2);
    ok('7b) first request aborted when second starts', first.opts.signal.aborted === true);
    ok('7c) second request not aborted', second.opts.signal.aborted === false);

    // resolve the OLD (page 2) request last — it must NOT overwrite page 3 content
    second.resolve({ ok: true, text: () => Promise.resolve('<div id="inventory-content" data-who="p3">3</div>') });
    await tick();
    first.resolve({ ok: true, text: () => Promise.resolve('<div id="inventory-content" data-who="p2">2</div>') });
    await tick(); await tick();
    ok('7d) stale (page2) response did not clobber newer (page3)', calls.locationHref.length === 0 && calls.pushState.length === 2);
}

/* ---------------- summary ---------------- */
console.log(`\n${passed} passed, ${failures.length} failed`);
if (failures.length) {
    console.log('FAILED: ' + failures.join(', '));
    process.exit(1);
}
