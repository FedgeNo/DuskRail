// A small DOM harness: executes the real script and asserts rendered results.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

class Element {
    constructor(tag) {
        this.tagName = tag.toUpperCase();
        this.children = [];
        this.parentElement = null;
        this.attributes = {};
        this.listeners = {};
        this.style = {};
        this.clientWidth = 900;
        this.classList = {toggle() {}};
    }

    get firstChild() {
        return this.children[0] || null;
    }

    setAttribute(name, value) {
        this.attributes[name] = value;
        if (name === 'class') {
            this.className = value;
        }
    }

    appendChild(child) {
        if (child.parentElement) {
            child.parentElement.removeChild(child);
        }
        this.children.push(child);
        child.parentElement = this;
        return child;
    }

    removeChild(child) {
        this.children.splice(this.children.indexOf(child), 1);
        child.parentElement = null;
    }

    querySelector(selector) {
        return this.descendants().find(element => element.tagName === selector.toUpperCase()) || null;
    }

    descendants() {
        return this.children.flatMap(child => [child, ...child.descendants()]);
    }

    closest(selector) {
        if (selector === '.' + this.className) {
            return this;
        }
        return this.parentElement ? this.parentElement.closest(selector) : null;
    }

    addEventListener(name, callback) {
        this.listeners[name] = callback;
    }
}

async function test() {
    const ids = Object.fromEntries(['search-form', 'query-input', 'status', 'results', 'preview', 'index-stats'].map(id => [id, new Element('div')]));
    ids['query-input'].value = 'widgets';
    const document = new Element('document');
    document.getElementById = id => ids[id];
    document.getElementsByName = () => [{checked: true, value: 'image'}];
    document.createElement = tag => new Element(tag);
    document.documentElement = {scrollHeight: 10000};
    const frames = [];
    const requests = [];
    const window = {
        innerHeight: 600,
        scrollY: 0,
        location: {pathname: '/', search: ''},
        history: {pushState() {}},
        requestAnimationFrame(callback) { frames.push(callback); return frames.length; },
        addEventListener() {}
    };
    vm.runInNewContext(fs.readFileSync(process.argv[2] || __dirname + '/../search.js', 'utf8'), {
        document, window, URLSearchParams, setTimeout, clearTimeout,
        Image: class {},
        fetch(url) {
            return new Promise(resolve => requests.push({url, resolve}));
        }
    });

    const flush = () => new Promise(resolve => setImmediate(resolve));
    const tiles = () => ids.results.descendants().filter(element => element.className === 'ImageTile');
    const result = id => ({itemId: id, url: 'https://example.org/' + id, title: 'Image ' + id, thumbnailURL: '/thumbnail-' + id});
    requests[0].resolve({json: async () => ({results: [result(1), result(2), result(3)], hasMore: false})});
    await flush();
    assert.equal(tiles().length, 3, 'all results render before any image loads');
    const originals = tiles();
    const thumb = originals[0].querySelector('img');
    thumb.naturalWidth = 300;
    thumb.naturalHeight = 150;
    document.listeners.load({target: thumb});
    while (frames.length) {
        frames.shift()();
    }
    assert.deepEqual(tiles(), originals, 'a loaded thumbnail preserves result order and nodes');
    assert.equal(parseFloat(thumb.style.width) / parseFloat(thumb.style.height), 2, 'a loaded thumbnail acquires its true aspect ratio');
    assert.equal(tiles().length, 3, 'a stalled thumbnail cannot hide the other results');
    assert.equal(ids.status.textContent, '3 results.');

    ids['query-input'].value = 'replacement';
    ids['search-form'].listeners.submit({preventDefault() {}});
    requests[1].resolve({json: async () => ({results: [result(4)], hasMore: false})});
    await flush();
    const replacement = tiles()[0];
    const late = originals[1].querySelector('img');
    late.naturalWidth = 150;
    late.naturalHeight = 300;
    document.listeners.load({target: late});
    while (frames.length) {
        frames.shift()();
    }
    assert.deepEqual(tiles(), [replacement], 'late images from an old search cannot alter current results');
    console.log('7 image-grid checks passed.');
}

test().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
