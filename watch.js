(function () {
    var since = 0;

    // itemIds already shown that were stamped at exactly `since`. The poll
    // cursor is a whole-second crawledTime and several items routinely land
    // within one second, so api/recent-items.php has to re-include that whole
    // second on the next poll rather than skip past it - this is what keeps
    // the ones already on screen from being drawn a second time. Only ever
    // holds that one boundary second's ids, emptied as soon as the cursor
    // moves past it.
    var seenAtSince = {};
    var feed = document.getElementById('feed');
    var UNDO_WINDOW_MS = 30000;
    var MAX_FEED_ITEMS = 50;
    var POLL_INTERVAL_MS = 1000;
    var STATUS_POLL_INTERVAL_MS = 5000;

    // How close to the bottom still counts as following the feed. About one
    // row, so sitting on the last item keeps you following it, while
    // deliberately scrolling up to read stops the feed chasing you down.
    var FOLLOW_THRESHOLD_PX = 40;

    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrfToken = csrfMeta !== null ? csrfMeta.content : '';

    // Rows pending deletion, by itemId: each holds the timer that will fire
    // the real delete and the element to remove when it does. Kept here in
    // JS rather than stamped onto the row as state the DOM would have to
    // carry, since nothing outside this file has any use for it.
    var pendingDeletes = {};

    /**
     * One row of the live feed - what a worker just finished crawling.
     */
    function CrawlFeedItem(item) {
        this.item = item;
    }

    CrawlFeedItem.prototype.toDOM = function () {
        var row = document.createElement('div');
        row.className = 'CrawlFeedItem d-flex align-items-center gap-2';
        row.id = 'feed-item-' + this.item.itemId;

        if (this.item.thumbnailURL) {
            var thumb = document.createElement('img');
            thumb.src = this.item.thumbnailURL;
            thumb.alt = '';
            thumb.className = 'CrawlFeedThumbnail flex-shrink-0';
            row.appendChild(thumb);
        }

        var text = document.createElement('div');
        text.className = 'flex-grow-1';

        // textContent throughout, never innerHTML - a crawled title/
        // description/url is untrusted content from the open web and must
        // never be parsed as markup here.
        var titleLine = document.createElement('div');
        titleLine.appendChild(document.createTextNode('[' + this.item.type + '] '));

        var titleLink = document.createElement('a');
        titleLink.href = this.item.url;
        titleLink.target = '_blank';
        titleLink.rel = 'noopener noreferrer';
        titleLink.className = 'link-light';
        titleLink.textContent = this.item.title || this.item.url;
        titleLine.appendChild(titleLink);

        text.appendChild(titleLine);

        if (this.item.description) {
            var descriptionLine = document.createElement('div');
            descriptionLine.className = 'CrawlFeedDescription small';
            descriptionLine.textContent = this.item.description;
            text.appendChild(descriptionLine);
        }

        row.appendChild(text);
        row.appendChild(new ItemDeleteButton(this.item.itemId).toDOM());

        return row;
    };

    /**
     * The per-row delete control. A first click only starts a grace window -
     * it gives a chance to correct a misclick, or a wrong row clicked because
     * the feed shifted right as it was pressed - and a second click within
     * that window calls the whole thing off.
     */
    function ItemDeleteButton(itemId) {
        this.itemId = itemId;
    }

    ItemDeleteButton.prototype.toDOM = function () {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'ItemDeleteButton btn btn-danger btn-sm flex-shrink-0';
        button.textContent = 'Delete';

        return button;
    };

    function rowFor(button) {
        return button.closest('.CrawlFeedItem');
    }

    function itemIdFor(row) {
        return parseInt(row.id.replace('feed-item-', ''), 10);
    }

    function toggleDelete(button) {
        var row = rowFor(button);
        var itemId = itemIdFor(row);

        if (pendingDeletes[itemId]) {
            clearTimeout(pendingDeletes[itemId]);
            delete pendingDeletes[itemId];
            row.classList.remove('opacity-50');
            button.textContent = 'Delete';
            return;
        }

        row.classList.add('opacity-50');
        button.textContent = 'Undo';
        pendingDeletes[itemId] = setTimeout(function () {
            delete pendingDeletes[itemId];
            deleteItem(itemId, row);
        }, UNDO_WINDOW_MS);
    }

    function deleteItem(itemId, row) {
        fetch('/api/delete-item.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfToken
            },
            body: 'itemId=' + encodeURIComponent(itemId)
        }).then(function (response) {
            if (response.ok) {
                row.remove();
            }
        }).catch(function () {});
    }

    function pollStatus() {
        fetch('/api/crawler-status.php')
            .then(function (response) { return response.json(); })
            .then(function (status) {
                var element = document.getElementById('crawler-status');
                element.classList.toggle('Running', status.running);
                element.classList.toggle('Stopped', !status.running);
                element.textContent = status.running
                    ? 'Crawler running — ' + status.crawledLastHour + ' item' + (status.crawledLastHour === 1 ? '' : 's') + ' crawled in the last hour.'
                    : 'Crawler stopped.';
            })
            .catch(function () {})
            .finally(function () {
                setTimeout(pollStatus, STATUS_POLL_INTERVAL_MS);
            });
    }

    function addSeed() {
        var input = document.getElementById('seed-input');
        var url = input.value.trim();

        if (url === '') {
            return;
        }

        fetch('/api/add-seed.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfToken
            },
            body: 'url=' + encodeURIComponent(url)
        }).then(function (response) {
            return response.json().then(function (data) {
                if (response.ok) {
                    input.value = '';
                    input.placeholder = 'Queued as itemId ' + data.itemId + ' — add another…';
                } else {
                    input.placeholder = data.error || 'Could not queue that URL.';
                    input.value = '';
                }
            });
        }).catch(function () {});
    }

    function setTopic(topic) {
        fetch('/api/set-topic.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfToken
            },
            body: 'topic=' + encodeURIComponent(topic)
        }).catch(function () {});
    }

    // Delegated on document rather than bound per row - the feed replaces its
    // rows continuously, and a listener per row would be created and orphaned
    // once a second for as long as this page stays open.
    document.addEventListener('click', function (event) {
        if (event.target.closest('.ItemDeleteButton') !== null) {
            toggleDelete(event.target.closest('.ItemDeleteButton'));
            return;
        }

        if (event.target.closest('#topic-set') !== null) {
            setTopic(document.getElementById('topic-input').value);
            return;
        }

        if (event.target.closest('#topic-clear') !== null) {
            document.getElementById('topic-input').value = '';
            setTopic('');
            return;
        }

        if (event.target.closest('#seed-add') !== null) {
            addSeed();
        }
    });

    /**
     * Whether the feed is scrolled to (or within a row's height of) the
     * bottom. The tolerance matters: scrollTop is fractional on a zoomed or
     * high-DPI display, so an exact comparison against the bottom is never
     * quite true even when the reader is plainly there.
     */
    function isNearBottom() {
        return feed.scrollHeight - feed.scrollTop - feed.clientHeight <= FOLLOW_THRESHOLD_PX;
    }

    function poll() {
        fetch('/api/recent-items.php?since=' + since)
            .then(function (response) { return response.json(); })
            .then(function (items) {
                // Measured before anything is appended, since appending is
                // what changes scrollHeight - asking afterwards would always
                // answer "no", because the new rows have just pushed the
                // bottom away from wherever the viewport is.
                var follow = isNearBottom();

                items.forEach(function (item) {
                    if (item.crawledTime > since) {
                        since = item.crawledTime;
                        seenAtSince = {};
                    } else if (seenAtSince[item.itemId]) {
                        return;
                    }

                    seenAtSince[item.itemId] = true;
                    feed.appendChild(new CrawlFeedItem(item).toDOM());
                });

                // Oldest rows are always at the top (appended in
                // crawledTime order) - trimming from the top as new ones
                // land at the bottom keeps the feed from growing forever. A
                // pending delete's undo timer still fires normally on a
                // trimmed-away row - it just has nothing left to visually
                // remove by the time it does.
                var heightBeforeTrim = feed.scrollHeight;

                while (feed.children.length > MAX_FEED_ITEMS) {
                    feed.removeChild(feed.firstElementChild);
                }

                if (follow) {
                    feed.scrollTop = feed.scrollHeight;
                    return;
                }

                // Reading further up, so the view stays put. Rows removed
                // above the viewport would otherwise drag everything below
                // them upwards by exactly the height they occupied, which
                // reads as the feed lurching every time it trims - the same
                // complaint as being yanked to the bottom, just smaller.
                feed.scrollTop = Math.max(0, feed.scrollTop - (heightBeforeTrim - feed.scrollHeight));
            })
            .catch(function () {})
            .finally(function () {
                setTimeout(poll, POLL_INTERVAL_MS);
            });
    }

    poll();
    pollStatus();
})();
