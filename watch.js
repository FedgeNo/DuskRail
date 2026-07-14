(function () {
    var since = 0;
    var feed = document.getElementById('feed');

    function deleteItem(itemId, row) {
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

    function poll() {
        fetch('/api/recent-items.php?since=' + since)
            .then(function (response) { return response.json(); })
            .then(function (items) {
                items.forEach(function (item) {
                    var row = document.createElement('div');
                    row.className = 'item d-flex justify-content-between align-items-center gap-2';

                    var text = document.createElement('span');
                    // textContent, never innerHTML - a crawled title/url is
                    // untrusted content from the open web and must never be
                    // parsed as markup here.
                    text.textContent = '[' + item.type + '] ' + (item.title || item.url);
                    row.appendChild(text);

                    var deleteButton = document.createElement('button');
                    deleteButton.type = 'button';
                    deleteButton.className = 'btn btn-danger btn-sm flex-shrink-0';
                    deleteButton.textContent = 'Delete';
                    deleteButton.addEventListener('click', function () {
                        deleteItem(item.itemId, row);
                    });
                    row.appendChild(deleteButton);

                    feed.appendChild(row);
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
