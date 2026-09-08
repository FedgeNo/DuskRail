const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const listeners = {};
const timers = new Map();
let next_timer = 0;
const document = {
    hidden: false,
    addEventListener(name, callback) { listeners[name] = callback; }
};
vm.runInNewContext(fs.readFileSync(__dirname + '/../thumbnail.js', 'utf8'), {
    document, WeakMap, Math,
    setTimeout(callback, delay) {
        timers.set(++next_timer, {callback, delay});
        return next_timer;
    },
    clearTimeout(timer) { timers.delete(timer); }
});

function image(src) {
    return {
        tagName: 'IMG', isConnected: true, src, requests: 0,
        getAttribute() { return this.src; },
        removeAttribute() { this.src = null; },
        setAttribute(name, value) { this.src = value; this.requests++; }
    };
}

function tick() {
    const [id, timer] = timers.entries().next().value;
    timers.delete(id);
    timer.callback();
    return timer.delay;
}

const thumbnail = image('/thumbnails/01/1.jpg');
listeners.error({target: thumbnail});
listeners.error({target: thumbnail});
assert.equal(timers.size, 1, 'duplicate errors cannot pile up retries');
assert.ok(tick() >= 2000, 'retry waits for server capacity');
assert.equal(thumbnail.requests, 1, 'temporary errors re-request the same thumbnail');
assert.equal(thumbnail.src, '/thumbnails/01/1.jpg', 'retry preserves the canonical cache URL');
listeners.error({target: thumbnail});
assert.ok(tick() >= 4000, 'repeated errors back off');
listeners.error({target: thumbnail});
listeners.load({target: thumbnail});
assert.equal(timers.size, 0, 'successful loads cancel pending retries');

listeners.error({target: thumbnail});
document.hidden = true;
tick();
assert.equal(thumbnail.requests, 2, 'hidden pages do not retry');
document.hidden = false;
tick();
assert.equal(thumbnail.requests, 3, 'retry resumes when the page is visible');

listeners.error({target: thumbnail});
thumbnail.isConnected = false;
tick();
assert.equal(thumbnail.requests, 3, 'removed results stop retrying');
assert.equal(timers.size, 0, 'removed results leave no retry timer');
listeners.error({target: image('https://example.org/photo.jpg')});
assert.equal(timers.size, 0, 'external images are outside the thumbnail retry policy');
console.log('11 thumbnail retry checks passed.');
