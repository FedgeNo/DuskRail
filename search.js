(function () {
    var form = document.getElementById('search-form');
    var input = document.getElementById('query-input');
    var status = document.getElementById('status');
    var results = document.getElementById('results');
    var preview = document.getElementById('preview');
    var typeInputs = document.getElementsByName('result-type');

    var PREVIEW_PLACEHOLDER = 'Select an image to preview it here.';

    // Infinite-scroll state for the in-progress search - reset at the start
    // of every new search() call so a fresh query/type never appends onto a
    // previous one's results. loadedItems is kept only for the running result
    // count (each image page lays itself out and is appended independently,
    // so nothing needs the full list of tiles held together anymore).
    var currentQuery = '';
    var currentType = 'html';
    var loadedItems = [];
    var hasMore = false;
    var loadingMore = false;
    var selectedTile = null;
    var previewToken = 0;

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

    // A div, not an anchor - clicking an image tile loads it into the
    // preview column instead of navigating away (see showPreview()); the
    // parent page and the image itself are both still reachable as links
    // from within the preview once it loads.
    function buildImageTile(result, aspectRatio) {
        var tile = document.createElement('div');
        tile.className = 'image-tile';
        tile.tabIndex = 0;
        tile.setAttribute('role', 'button');

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

        tile.addEventListener('click', function () {
            selectTile(tile, result.itemId);
        });

        tile.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                selectTile(tile, result.itemId);
            }
        });

        return tile;
    }

    function selectTile(tile, itemId) {
        if (selectedTile) {
            selectedTile.classList.remove('selected');
        }

        selectedTile = tile;
        tile.classList.add('selected');
        showPreview(itemId);
    }

    function clearPreview() {
        while (preview.firstChild) {
            preview.removeChild(preview.firstChild);
        }
    }

    function resetPreview() {
        previewToken++; // invalidates any showPreview() request still in flight

        if (selectedTile) {
            selectedTile.classList.remove('selected');
            selectedTile = null;
        }

        clearPreview();
        preview.textContent = PREVIEW_PLACEHOLDER;
    }

    // Shows the thumbnail immediately, scaled up to fill the preview column
    // (it's already on hand, no extra request) - then loads the real
    // full-size image in the background and swaps it in once it's actually
    // ready, same progressive-load feel as Google Images' preview panel.
    // If the full image fails to load (dead link, since crawled), the
    // thumbnail is left in place rather than showing nothing.
    function showPreview(itemId) {
        var thisRequest = ++previewToken;

        clearPreview();
        preview.textContent = 'Loading...';

        fetch('/api/item.php?itemId=' + encodeURIComponent(itemId))
            .then(function (response) { return response.json(); })
            .then(function (item) {
                // The user may have clicked a different tile (or started a
                // new search) while this was in flight - a stale response
                // landing late must not overwrite whatever's now selected.
                if (thisRequest !== previewToken) {
                    return;
                }

                clearPreview();

                var image = document.createElement('img');
                image.className = 'preview-image';

                if (item.thumbnailUrl) {
                    image.src = item.thumbnailUrl;
                }

                preview.appendChild(image);

                if (item.url) {
                    var fullImage = new Image();
                    fullImage.onload = function () {
                        image.src = item.url;
                    };
                    fullImage.src = item.url;
                }

                if (item.title) {
                    var title = document.createElement('div');
                    title.className = 'preview-title';
                    title.textContent = item.title;
                    preview.appendChild(title);
                }

                if (item.description) {
                    var description = document.createElement('div');
                    description.className = 'preview-description';
                    description.textContent = item.description;
                    preview.appendChild(description);
                }

                if (item.parentUrl) {
                    var parentLine = document.createElement('div');
                    parentLine.className = 'preview-parent';
                    parentLine.appendChild(document.createTextNode('From: '));

                    var parentLink = document.createElement('a');
                    parentLink.href = item.parentUrl;
                    parentLink.target = '_blank';
                    parentLink.rel = 'noopener noreferrer';
                    parentLink.textContent = shortenUrl(item.parentUrl);
                    parentLine.appendChild(parentLink);

                    preview.appendChild(parentLine);
                }

                var imageLink = document.createElement('a');
                imageLink.className = 'preview-image-link';
                imageLink.href = item.url;
                imageLink.target = '_blank';
                imageLink.rel = 'noopener noreferrer';
                imageLink.textContent = 'Open full image';
                preview.appendChild(imageLink);
            })
            .catch(function () {
                if (thisRequest !== previewToken) {
                    return;
                }

                clearPreview();
                preview.textContent = 'Could not load preview.';
            });
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

                    if (!append) {
                        clearResults();
                        results.className = 'image-grid';
                    }

                    // Lay out only this page's tiles and append them as their
                    // own justified rows below whatever's already there -
                    // earlier pages keep their existing layout rather than
                    // being torn down and re-flowed on every load. Each page
                    // justifies independently, so its last (partial) row stays
                    // at the target height and the next page starts fresh.
                    var tiles = items.map(function (result, index) {
                        return buildImageTile(result, dimensions[index].width / dimensions[index].height);
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
        hasMore = false;
        loadingMore = false;
        resetPreview();

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

    // One submit handler covers both paths - clicking the (type=submit) button
    // and pressing Enter in the query field both fire the form's submit event.
    // preventDefault keeps the browser from actually navigating to ?q=... and
    // reloading; the AJAX flow and history are handled here instead.
    form.addEventListener('submit', function (event) {
        event.preventDefault();
        submit();
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
