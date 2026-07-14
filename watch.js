(function () {
    var since = 0;
    var feed = document.getElementById('feed');
    var UNDO_WINDOW_MS = 30000;

    function setTopic(topic) {
        fetch('/api/set-topic.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'topic=' + encodeURIComponent(topic)
        }).catch(function () {});
    }

    document.getElementById('topic-set').addEventListener('click', function () {
        setTopic(document.getElementById('topic-input').value);
    });

    document.getElementById('topic-clear').addEventListener('click', function () {
        document.getElementById('topic-input').value = '';
        setTopic('');
    });

    function actuallyDelete(itemId, row) {
        fetch('/api/delete-item.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'itemId=' + encodeURIComponent(itemId)
        }).then(function (response) {
            if (response.ok) {
                row.remove();
            }
        }).catch(function () {});
    }

    function buildRow(item) {
        var row = document.createElement('div');
        row.className = 'item d-flex align-items-center gap-2';

        if (item.thumbnailUrl) {
            var thumb = document.createElement('img');
            thumb.src = item.thumbnailUrl;
            thumb.className = 'flex-shrink-0';
            thumb.style.maxHeight = '50px';
            thumb.style.maxWidth = '80px';
            thumb.style.objectFit = 'cover';
            row.appendChild(thumb);
        }

        var text = document.createElement('div');
        text.className = 'flex-grow-1';

        // textContent throughout, never innerHTML - a crawled title/
        // description/url is untrusted content from the open web and must
        // never be parsed as markup here.
        var titleLine = document.createElement('div');
        titleLine.appendChild(document.createTextNode('[' + item.type + '] '));

        var titleLink = document.createElement('a');
        titleLink.href = item.url;
        titleLink.target = '_blank';
        titleLink.rel = 'noopener noreferrer';
        titleLink.className = 'link-light';
        titleLink.textContent = item.title || item.url;
        titleLine.appendChild(titleLink);

        text.appendChild(titleLine);

        if (item.description) {
            var descriptionLine = document.createElement('div');
            descriptionLine.className = 'description small';
            descriptionLine.textContent = item.description;
            text.appendChild(descriptionLine);
        }

        row.appendChild(text);

        var deleteButton = document.createElement('button');
        deleteButton.type = 'button';
        deleteButton.className = 'btn btn-danger btn-sm flex-shrink-0';
        deleteButton.textContent = 'Delete';

        var pendingTimer = null;

        deleteButton.addEventListener('click', function () {
            if (pendingTimer === null) {
                // First click - don't delete yet, just start the 30s grace
                // window. Gives a chance to correct a misclick, or a wrong
                // row clicked because the feed shifted right as it was
                // pressed.
                row.classList.add('opacity-50');
                deleteButton.textContent = 'Undo';
                pendingTimer = setTimeout(function () {
                    actuallyDelete(item.itemId, row);
                }, UNDO_WINDOW_MS);
            } else {
                clearTimeout(pendingTimer);
                pendingTimer = null;
                row.classList.remove('opacity-50');
                deleteButton.textContent = 'Delete';
            }
        });

        row.appendChild(deleteButton);

        return row;
    }

    function poll() {
        fetch('/api/recent-items.php?since=' + since)
            .then(function (response) { return response.json(); })
            .then(function (items) {
                items.forEach(function (item) {
                    feed.appendChild(buildRow(item));
                    since = Math.max(since, item.crawledTime);
                });

                if (items.length > 0) {
                    feed.scrollTop = feed.scrollHeight;
                }
            })
            .catch(function () {})
            .finally(function () {
                setTimeout(poll, 1000);
            });
    }

    poll();
})();
