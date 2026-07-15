(function () {
    var input = document.getElementById('query-input');
    var button = document.getElementById('search-button');
    var status = document.getElementById('status');
    var results = document.getElementById('results');
    var typeInputs = document.getElementsByName('result-type');

    // Infinite-scroll state for the in-progress search - reset at the start
    // of every new search() call so a fresh query/type never appends onto a
    // previous one's results. tileAspectRatios stays parallel to loadedItems
    // (same indices) since the image grid re-lays-out from scratch on every
    // page - the packing algorithm needs every tile's aspect ratio together,
    // not just the newly-loaded page's.
    var currentQuery = '';
    var currentType = 'html';
    var loadedItems = [];
    var tileAspectRatios = [];
    var hasMore = false;
    var loadingMore = false;

    function selectedType() {
        for (var i = 0; i < typeInputs.length; i++) {
            if (typeInputs[i].checked) {
                return typeInputs[i].value;
            }
        }
        return 'html';
    }

    function clearResults() {
        while (results.firstChild) {
            results.removeChild(results.firstChild);
        }
    }

    function buildResultRow(result) {
        var row = document.createElement('a');
        row.className = 'result';
        row.href = result.url;
        row.target = '_blank';
        row.rel = 'noopener noreferrer';

        if (result.thumbnailUrl) {
            var thumb = document.createElement('img');
            thumb.src = result.thumbnailUrl;
            row.appendChild(thumb);
        }

        var text = document.createElement('div');

        // textContent throughout, never innerHTML - a crawled title/
        // description/url is untrusted content from the open web and must
        // never be parsed as markup here.
        var titleLine = document.createElement('div');
        titleLine.className = 'title';
        titleLine.textContent = result.title || result.url;
        text.appendChild(titleLine);

        if (result.description) {
            var descriptionLine = document.createElement('div');
            descriptionLine.className = 'description';
            descriptionLine.textContent = result.description;
            text.appendChild(descriptionLine);
        }

        var urlLine = document.createElement('div');
        urlLine.className = 'url';
        urlLine.textContent = result.url;
        text.appendChild(urlLine);

        row.appendChild(text);

        return row;
    }

    function shortenUrl(url) {
        // Actual truncate-to-fit is CSS's job (.image-tile .url has
        // white-space: nowrap + text-overflow: ellipsis) - this just strips
        // the protocol, since that part is never worth the width it costs.
        return url.replace(/^[a-z]+:\/\//i, '');
    }

    function loadImageDimensions(src) {
        return new Promise(function (resolve) {
            var probe = new Image();
            probe.onload = function () {
                resolve({width: probe.naturalWidth || 1, height: probe.naturalHeight || 1});
            };
            probe.onerror = function () {
                resolve({width: 1, height: 1});
            };
            probe.src = src;
        });
    }

    function buildImageTile(result, aspectRatio) {
        var tile = document.createElement('a');
        tile.className = 'image-tile';
        tile.href = result.url;
        tile.target = '_blank';
        tile.rel = 'noopener noreferrer';

        if (result.thumbnailUrl) {
            var thumb = document.createElement('img');
            thumb.src = result.thumbnailUrl;
            tile.appendChild(thumb);
        }

        var urlLine = document.createElement('div');
        urlLine.className = 'url';
        urlLine.textContent = shortenUrl(result.url);
        tile.appendChild(urlLine);

        if (result.description) {
            var descriptionLine = document.createElement('div');
            descriptionLine.className = 'description';
            descriptionLine.textContent = result.description;
            tile.appendChild(descriptionLine);
        }

        tile.dataset.aspectRatio = aspectRatio;

        return tile;
    }

    // Packs tiles into rows at a shared height, scaled by each image's own
    // aspect ratio, then stretches every full row to exactly fill the
    // container width - the classic Google Photos/Flickr "justified gallery"
    // layout. A CSS grid can't do this because it doesn't know each image's
    // aspect ratio; it can only carve up uniform cells. Rows are built as
    // explicit wrapper elements here rather than left to the browser's own
    // flex-wrap point - letting flex-wrap decide left row boundaries at the
    // mercy of subpixel rounding, which didn't reliably match the row groups
    // this function actually computed widths for.
    function layoutJustifiedGrid(containerEl, tiles, targetRowHeight, gap) {
        var containerWidth = containerEl.clientWidth;
        var row = [];
        var rowAspectSum = 0;

        function placeRow(rowTiles, height) {
            var rowEl = document.createElement('div');
            rowEl.className = 'image-grid-row';

            rowTiles.forEach(function (tile) {
                var aspectRatio = parseFloat(tile.dataset.aspectRatio);
                var width = aspectRatio * height;
                tile.style.width = width + 'px';
                var img = tile.querySelector('img');
                if (img) {
                    img.style.width = width + 'px';
                    img.style.height = height + 'px';
                }
                rowEl.appendChild(tile);
            });

            containerEl.appendChild(rowEl);
        }

        tiles.forEach(function (tile) {
            var aspectRatio = parseFloat(tile.dataset.aspectRatio);
            row.push(tile);
            rowAspectSum += aspectRatio;

            var gapWidth = (row.length - 1) * gap;
            var rowWidthAtTargetHeight = rowAspectSum * targetRowHeight + gapWidth;

            if (rowWidthAtTargetHeight >= containerWidth) {
                var scale = (containerWidth - gapWidth) / (rowAspectSum * targetRowHeight);
                placeRow(row, targetRowHeight * scale);
                row = [];
                rowAspectSum = 0;
            }
        });

        if (row.length > 0) {
            placeRow(row, targetRowHeight);
        }
    }

    function updateStatus() {
        var count = loadedItems.length;
        status.textContent = count + ' result' + (count === 1 ? '' : 's') + (hasMore ? '+' : '') + '.';
    }

    // Fires on scroll (and once after every page renders, in case the page
    // still doesn't fill the viewport) - loads the next page once the user
    // is within 600px of the bottom. loadingMore prevents piling up
    // duplicate requests from repeated scroll events while one is in flight.
    function maybeLoadMore() {
        if (!hasMore || loadingMore || currentQuery === '') {
            return;
        }

        var scrolledToBottom = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 600;

        if (scrolledToBottom) {
            loadPage(loadedItems.length);
        }
    }

    function loadPage(offset) {
        var append = offset > 0;
        loadingMore = true;

        if (!append) {
            status.textContent = 'Searching...';
        }

        var query = currentQuery;
        var type = currentType;

        fetch('/api/search.php?q=' + encodeURIComponent(query) + '&type=' + encodeURIComponent(type) + '&offset=' + offset)
            .then(function (response) { return response.json(); })
            .then(function (data) {
                // The query/type may have changed while this request was in
                // flight (a new search started) - a stale response landing
                // late must not corrupt the results of whatever is current.
                if (query !== currentQuery || type !== currentType) {
                    return;
                }

                var items = data.results || [];
                hasMore = !!data.hasMore;

                if (items.length === 0 && !append) {
                    clearResults();
                    results.className = type === 'image' ? 'image-grid' : '';
                    status.textContent = 'No results found for "' + query + '".';
                    loadingMore = false;
                    return;
                }

                loadedItems = loadedItems.concat(items);
                updateStatus();

                if (type !== 'image') {
                    if (!append) {
                        clearResults();
                        results.className = '';
                    }

                    items.forEach(function (result) {
                        results.appendChild(buildResultRow(result));
                    });

                    loadingMore = false;
                    maybeLoadMore();
                    return;
                }

                Promise.all(items.map(function (result) {
                    return loadImageDimensions(result.thumbnailUrl || '');
                })).then(function (dimensions) {
                    if (query !== currentQuery || type !== currentType) {
                        return;
                    }

                    dimensions.forEach(function (dimension) {
                        tileAspectRatios.push(dimension.width / dimension.height);
                    });

                    clearResults();
                    results.className = 'image-grid';

                    var tiles = loadedItems.map(function (result, index) {
                        return buildImageTile(result, tileAspectRatios[index]);
                    });

                    layoutJustifiedGrid(results, tiles, 180, 8);

                    loadingMore = false;
                    maybeLoadMore();
                });
            })
            .catch(function () {
                status.textContent = 'Something went wrong searching. Try again.';
                loadingMore = false;
            });
    }

    function search(query) {
        currentQuery = query;
        currentType = selectedType();
        loadedItems = [];
        tileAspectRatios = [];
        hasMore = false;
        loadingMore = false;

        if (query === '') {
            clearResults();
            status.textContent = '';
            return;
        }

        loadPage(0);
    }

    function submit() {
        var query = input.value.trim();
        var url = query === '' ? window.location.pathname : '?q=' + encodeURIComponent(query);
        window.history.pushState({query: query}, '', url);
        search(query);
    }

    button.addEventListener('click', submit);

    input.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            submit();
        }
    });

    for (var i = 0; i < typeInputs.length; i++) {
        typeInputs[i].addEventListener('change', function () {
            search(input.value.trim());
        });
    }

    window.addEventListener('popstate', function () {
        var params = new URLSearchParams(window.location.search);
        var query = params.get('q') || '';
        input.value = query;
        search(query);
    });

    window.addEventListener('scroll', maybeLoadMore);

    if (input.value.trim() !== '') {
        search(input.value.trim());
    }
})();
