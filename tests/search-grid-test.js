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
        listeners: {},
        addEventListener(name, callback) { this.listeners[name] = callback; }
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
    requests[0].resolve({json: async () => ({results: [result(1), result(2), result(3)], hasMore: true})});
    await flush();
    assert.equal(tiles().length, 3, 'all results render before any image loads');
    const originals = tiles();
    const firstGrid = ids.results.firstChild;
    const firstRows = firstGrid.children.slice();
    const thumb = originals[0].querySelector('img');
    thumb.naturalWidth = 300;
    thumb.naturalHeight = 150;
    if (document.listeners.load) document.listeners.load({target: thumb});
    while (frames.length) {
        frames.shift()();
    }
    assert.deepEqual(tiles(), originals, 'a loaded thumbnail preserves result order and nodes');
    assert.deepEqual(firstGrid.children, firstRows, 'thumbnail loading preserves the batch children');
    assert.equal(parseFloat(thumb.style.width) / parseFloat(thumb.style.height), 2, 'landscape thumbnails retain their aspect ratio');
    const portrait = originals[1].querySelector('img');
    portrait.naturalWidth = 150;
    portrait.naturalHeight = 300;
    if (document.listeners.load) document.listeners.load({target: portrait});
    assert.equal(parseFloat(portrait.style.width) / parseFloat(portrait.style.height), 0.5, 'portrait thumbnails retain their aspect ratio');
    assert.equal(tiles().length, 3, 'a stalled thumbnail cannot hide the other results');
    assert.equal(ids.status.textContent, '3 results+.');

    window.scrollY = 9000;
    window.listeners.scroll();
    assert.match(requests[1].url, /offset=3$/, 'scrolling requests the next batch');
    requests[1].resolve({json: async () => ({results: [result(5), result(6)], hasMore: false})});
    await flush();
    assert.equal(ids.results.children.length, 2, 'each batch has its own grid');
    assert.equal(ids.results.children[0], firstGrid, 'appending preserves the first grid');
    assert.equal(ids.results.children[1].className, 'ImageBatchGrid', 'the appended batch is another image grid');
    assert.deepEqual(firstGrid.children, firstRows, 'appending preserves previous rows');
    assert.deepEqual(tiles().slice(0, 3), originals, 'appending preserves previous tiles');

    const priorSizes = originals.map(tile => ({...tile.querySelector('img').style}));
    const wide = tiles()[3].querySelector('img');
    wide.naturalWidth = 3000;
    wide.naturalHeight = 100;
    if (document.listeners.load) document.listeners.load({target: wide});
    assert.equal(tiles()[3].style.display, 'none', 'extremely wide images are hidden');
    const tall = tiles()[4].querySelector('img');
    tall.naturalWidth = 100;
    tall.naturalHeight = 3000;
    if (document.listeners.load) document.listeners.load({target: tall});
    assert.equal(tiles()[4].style.display, 'none', 'extremely tall images are hidden');
    assert.deepEqual(originals.map(tile => ({...tile.querySelector('img').style})), priorSizes, 'loads in a new batch do not resize prior batches');

    ids['query-input'].value = 'replacement';
    ids['search-form'].listeners.submit({preventDefault() {}});
    requests[2].resolve({json: async () => ({results: [result(4)], hasMore: false})});
    await flush();
    const replacement = tiles()[0];
    const late = originals[1].querySelector('img');
    late.naturalWidth = 150;
    late.naturalHeight = 300;
    if (document.listeners.load) document.listeners.load({target: late});
    while (frames.length) {
        frames.shift()();
    }
    assert.deepEqual(tiles(), [replacement], 'late images from an old search cannot alter current results');
    console.log('Image-grid checks passed.');
}

test().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
