(function () {
    var form = document.getElementById('search-form');
    var input = document.getElementById('query-input');
    var status = document.getElementById('status');
    var results = document.getElementById('results');
    var preview = document.getElementById('preview');
    var typeInputs = document.getElementsByName('result-type');

    var PREVIEW_PLACEHOLDER = 'Select an image to preview it here.';
    var TARGET_ROW_HEIGHT = 180;
    var GRID_GAP = 8;

    // Infinite-scroll state for the in-progress search - reset at the start
    // of every new search() call so a fresh query/type never appends onto a
    // previous one's results.
    var currentQuery = '';
    var currentType = 'html';
    var loadedCount = 0;
    var hasMore = false;
    var loadingMore = false;
    var previewToken = 0;

    // Every image tile currently on screen, as ImageTile instances, in the
    // order they were loaded. Kept because the justified layout has to be
    // recomputed from scratch whenever the container's width changes, and
    // each tile's aspect ratio only exists here - it's a fact about the
    // image, not something the DOM has any way to store or recover.
    var imageTiles = [];

    // The tile whose image is in the preview column, if any - kept here so
    // the arrow keys know where to move from and Escape knows what to clear.
    var selectedTile = null;

    /**
     * Thrown when the server refuses a request for being over the public
     * request budget. Its own type so the catch below can tell "wait and it
     * will work" apart from a genuine failure.
     */
    function RateLimited(retryAfter) {
        this.retryAfter = retryAfter;
    }

    RateLimited.prototype = Object.create(Error.prototype);

    /**
     * One result row in the page (non-image) list.
     */
    function SearchResultRow(result) {
        this.result = result;
    }

    SearchResultRow.prototype.toDOM = function () {
        var row = document.createElement('a');
        row.className = 'SearchResultRow';
        row.href = this.result.url;
        row.target = '_blank';
        row.rel = 'noopener noreferrer';

        if (this.result.thumbnailURL) {
            var thumb = document.createElement('img');
            thumb.src = this.result.thumbnailURL;
            thumb.alt = '';
            row.appendChild(thumb);
        }

        var text = document.createElement('div');

        // textContent throughout, never innerHTML - a crawled title/
        // description/url is untrusted content from the open web and must
        // never be parsed as markup here.
        var titleLine = document.createElement('div');
        titleLine.className = 'SearchResultTitle';
        titleLine.textContent = this.result.title || this.result.url;
        text.appendChild(titleLine);

        if (this.result.description) {
            var descriptionLine = document.createElement('div');
            descriptionLine.className = 'SearchResultDescription';
            descriptionLine.appendChild(highlightTerms(this.result.description, queryTerms()));
            text.appendChild(descriptionLine);
        }

        var urlLine = document.createElement('div');
        urlLine.className = 'SearchResultURL';
        urlLine.textContent = this.result.url;
        text.appendChild(urlLine);

        row.appendChild(text);

        return row;
    };

    /**
     * One tile in the image grid. A div rather than an anchor - clicking it
     * loads the image into the preview column instead of navigating away
     * (see ImagePreview); the parent page and the image itself are both
     * still reachable as links from within the preview once it loads.
     */
    function ImageTile(result, aspectRatio) {
        this.result = result;
        this.aspectRatio = aspectRatio;
        this.element = null;
    }

    ImageTile.prototype.toDOM = function () {
        var tile = document.createElement('div');
        tile.className = 'ImageTile';
        tile.tabIndex = 0;
        tile.setAttribute('role', 'button');

        if (this.result.thumbnailURL) {
            var thumb = document.createElement('img');
            thumb.src = this.result.thumbnailURL;
            thumb.alt = this.result.title || '';
            tile.appendChild(thumb);
        }

        var urlLine = document.createElement('div');
        urlLine.className = 'ImageTileURL';
        urlLine.textContent = shortenURL(this.result.url);
        tile.appendChild(urlLine);

        if (this.result.description) {
            var descriptionLine = document.createElement('div');
            descriptionLine.className = 'ImageTileDescription';
            descriptionLine.textContent = this.result.description;
            tile.appendChild(descriptionLine);
        }

        this.element = tile;

        return tile;
    };

    /**
     * Sizes this tile for a justified row of the given height. The width
     * follows from the image's own aspect ratio, which is what makes every
     * tile in a row share one height without any of them being cropped.
     */
    ImageTile.prototype.resizeTo = function (height) {
        var width = this.aspectRatio * height;

        this.element.style.width = width + 'px';

        var img = this.element.querySelector('img');

        if (img) {
            img.style.width = width + 'px';
            img.style.height = height + 'px';
        }
    };

    ImageTile.prototype.setSelected = function (selected) {
        this.element.classList.toggle('Selected', selected);
    };

    /**
     * The side panel showing one image at full size, with its title,
     * description, and a link back to the page it was found on.
     */
    function ImagePreview(item) {
        this.item = item;
    }

    ImagePreview.prototype.toDOM = function () {
        var fragment = document.createDocumentFragment();

        var image = document.createElement('img');
        image.className = 'PreviewImage';
        image.alt = this.item.title || '';

        if (this.item.thumbnailURL) {
            image.src = this.item.thumbnailURL;
        }

        fragment.appendChild(image);

        // The thumbnail is already on hand, so it shows immediately and the
        // real image is swapped in behind it once it has actually loaded -
        // the same progressive feel as Google Images' preview panel. A dead
        // link (the image has gone since it was crawled) simply never fires
        // onload, leaving the thumbnail in place rather than showing nothing.
        if (this.item.url) {
            var fullImage = new Image();
            fullImage.onload = function () {
                image.src = this.item.url;
            }.bind(this);
            fullImage.src = this.item.url;
        }

        if (this.item.title) {
            var title = document.createElement('div');
            title.className = 'PreviewTitle';
            title.textContent = this.item.title;
            fragment.appendChild(title);
        }

        if (this.item.description) {
            var description = document.createElement('div');
            description.className = 'PreviewDescription';
            description.textContent = this.item.description;
            fragment.appendChild(description);
        }

        if (this.item.parentURL) {
            var parentLine = document.createElement('div');
            parentLine.className = 'PreviewParent';
            parentLine.appendChild(document.createTextNode('From: '));

            var parentLink = document.createElement('a');
            parentLink.href = this.item.parentURL;
            parentLink.target = '_blank';
            parentLink.rel = 'noopener noreferrer';
            parentLink.textContent = shortenURL(this.item.parentURL);
            parentLine.appendChild(parentLink);

            fragment.appendChild(parentLine);
        }

        var imageLink = document.createElement('a');
        imageLink.className = 'PreviewImageLink';
        imageLink.href = this.item.url;
        imageLink.target = '_blank';
        imageLink.rel = 'noopener noreferrer';
        imageLink.textContent = 'Open full image';
        fragment.appendChild(imageLink);

        return fragment;
    };

    function selectedType() {
        for (var i = 0; i < typeInputs.length; i++) {
            if (typeInputs[i].checked) {
                return typeInputs[i].value;
            }
        }
        return 'html';
    }

    function clearElement(element) {
        while (element.firstChild) {
            element.removeChild(element.firstChild);
        }
    }

    /**
     * The current query as bare lowercased words worth highlighting - quotes
     * stripped, short words dropped (highlighting "of" everywhere is noise,
     * and the FULLTEXT index didn't match on them anyway).
     */
    function queryTerms() {
        var words = currentQuery.split('"').join(' ').split(' ');
        var terms = [];

        words.forEach(function (word) {
            word = word.toLowerCase();

            if (word.length >= 3 && terms.indexOf(word) === -1) {
                terms.push(word);
            }
        });

        return terms.slice(0, 8);
    }

    /**
     * The text as a fragment with every occurrence of the query's terms
     * wrapped in <mark>. Built from text nodes and elements, never markup -
     * this is crawled content, and it stays inert no matter what's in it.
     */
    function highlightTerms(text, terms) {
        var fragment = document.createDocumentFragment();

        if (terms.length === 0) {
            fragment.appendChild(document.createTextNode(text));
            return fragment;
        }

        var lower = text.toLowerCase();
        var position = 0;

        while (position < text.length) {
            var matchStart = -1;
            var matchLength = 0;

            terms.forEach(function (term) {
                var found = lower.indexOf(term, position);

                if (found !== -1 && (matchStart === -1 || found < matchStart)) {
                    matchStart = found;
                    matchLength = term.length;
                }
            });

            if (matchStart === -1) {
                fragment.appendChild(document.createTextNode(text.slice(position)));
                break;
            }

            if (matchStart > position) {
                fragment.appendChild(document.createTextNode(text.slice(position, matchStart)));
            }

            var mark = document.createElement('mark');
            mark.textContent = text.slice(matchStart, matchStart + matchLength);
            fragment.appendChild(mark);
            position = matchStart + matchLength;
        }

        return fragment;
    }

    function shortenURL(url) {
        // Actual truncate-to-fit is CSS's job (.ImageTileURL has
        // white-space: nowrap + text-overflow: ellipsis) - this just strips
        // the protocol, since that part is never worth the width it costs.
        return url.replace(/^[a-z]+:\/\//i, '');
    }

    function loadImageDimensions(src) {
        return new Promise(function (resolve) {
            if (!src) {
                resolve({width: 1, height: 1});
                return;
            }

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

    function tileFor(element) {
        for (var i = 0; i < imageTiles.length; i++) {
            if (imageTiles[i].element === element) {
                return imageTiles[i];
            }
        }
        return null;
    }

    function selectTile(tile) {
        selectedTile = tile;

        imageTiles.forEach(function (other) {
            other.setSelected(other === tile);
        });

        showPreview(tile.result.itemId);
    }

    /**
     * Moves the selection along the grid by delta tiles (arrow keys). The
     * ends don't wrap - stepping past the last image should feel like an
     * edge, not silently teleport back to the first.
     */
    function selectAdjacentTile(delta) {
        if (selectedTile === null) {
            return;
        }

        var index = imageTiles.indexOf(selectedTile) + delta;

        if (index >= 0 && index < imageTiles.length) {
            selectTile(imageTiles[index]);
        }
    }

    function resetPreview() {
        previewToken++; // invalidates any showPreview() request still in flight
        selectedTile = null;

        imageTiles.forEach(function (tile) {
            tile.setSelected(false);
        });

        // Left empty here rather than set to PREVIEW_PLACEHOLDER - loadPage()
        // sets that itself, and only once it actually knows there are image
        // results to preview.
        clearElement(preview);
    }

    function showPreview(itemId) {
        var thisRequest = ++previewToken;

        clearElement(preview);
        preview.textContent = 'Loading…';

        fetch('/api/item.php?itemId=' + encodeURIComponent(itemId))
            .then(function (response) { return response.json(); })
            .then(function (item) {
                // The user may have clicked a different tile (or started a
                // new search) while this was in flight - a stale response
                // landing late must not overwrite whatever's now selected.
                if (thisRequest !== previewToken) {
                    return;
                }

                clearElement(preview);
                preview.appendChild(new ImagePreview(item).toDOM());
            })
            .catch(function () {
                if (thisRequest !== previewToken) {
                    return;
                }

                clearElement(preview);
                preview.textContent = 'Could not load preview.';
            });
    }

    /**
     * Packs tiles into rows at a shared height, scaled by each image's own
     * aspect ratio, then stretches every full row to exactly fill the
     * container width - the classic Google Photos/Flickr "justified gallery"
     * layout. A CSS grid can't do this because it doesn't know each image's
     * aspect ratio; it can only carve up uniform cells. Rows are built as
     * explicit wrapper elements rather than left to the browser's own
     * flex-wrap point - letting flex-wrap decide left row boundaries at the
     * mercy of subpixel rounding, which didn't reliably match the row groups
     * this function actually computed widths for.
     */
    function layoutJustifiedGrid(containerEl, tiles) {
        var containerWidth = containerEl.clientWidth;
        var row = [];
        var rowAspectSum = 0;

        function placeRow(rowTiles, height) {
            var rowEl = document.createElement('div');
            rowEl.className = 'ImageGridRow';

            rowTiles.forEach(function (tile) {
                tile.resizeTo(height);
                rowEl.appendChild(tile.element);
            });

            containerEl.appendChild(rowEl);
        }

        tiles.forEach(function (tile) {
            row.push(tile);
            rowAspectSum += tile.aspectRatio;

            var gapWidth = (row.length - 1) * GRID_GAP;
            var rowWidthAtTargetHeight = rowAspectSum * TARGET_ROW_HEIGHT + gapWidth;

            if (rowWidthAtTargetHeight >= containerWidth) {
                var scale = (containerWidth - gapWidth) / (rowAspectSum * TARGET_ROW_HEIGHT);
                placeRow(row, TARGET_ROW_HEIGHT * scale);
                row = [];
                rowAspectSum = 0;
            }
        });

        if (row.length > 0) {
            placeRow(row, TARGET_ROW_HEIGHT);
        }
    }

    /**
     * Re-flows every tile currently on screen. Row boundaries are computed
     * against the container's width, so a window resize invalidates all of
     * them at once - without this the grid keeps the rows it was built for
     * and either overflows its column or leaves a growing empty margin.
     * Every page is re-laid out as one run here, unlike the incremental
     * append during scrolling, since there's no reason to preserve the old
     * page boundaries once everything is being recomputed anyway.
     */
    function relayoutImageGrid() {
        if (currentType !== 'image' || imageTiles.length === 0) {
            return;
        }

        clearElement(results);
        layoutJustifiedGrid(results, imageTiles);
    }

    function updateStatus() {
        status.textContent = loadedCount + ' result' + (loadedCount === 1 ? '' : 's') + (hasMore ? '+' : '') + '.';
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
            loadPage(loadedCount);
        }
    }

    function loadPage(offset) {
        var append = offset > 0;
        loadingMore = true;

        if (!append) {
            status.textContent = 'Searching…';
        }

        var query = currentQuery;
        var type = currentType;

        fetch('/api/search.php?q=' + encodeURIComponent(query) + '&type=' + encodeURIComponent(type) + '&offset=' + offset)
            .then(function (response) {
                // Over the request budget (see RateLimit). Worth saying so
                // plainly rather than as a generic failure - it clears on its
                // own, and the reader can see how long that takes.
                if (response.status === 429) {
                    return response.json().then(function (data) {
                        throw new RateLimited(data.retryAfter || 60);
                    });
                }

                return response.json();
            })
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
                    clearElement(results);
                    results.className = type === 'image' ? 'ImageGrid' : '';
                    status.textContent = 'No results found for "' + query + '".';
                    loadingMore = false;
                    return;
                }

                loadedCount += items.length;
                updateStatus();

                if (type !== 'image') {
                    if (!append) {
                        clearElement(results);
                        results.className = '';
                    }

                    items.forEach(function (result) {
                        results.appendChild(new SearchResultRow(result).toDOM());
                    });

                    loadingMore = false;
                    maybeLoadMore();
                    return;
                }

                Promise.all(items.map(function (result) {
                    return loadImageDimensions(result.thumbnailURL);
                })).then(function (dimensions) {
                    if (query !== currentQuery || type !== currentType) {
                        return;
                    }

                    if (!append) {
                        clearElement(results);
                        results.className = 'ImageGrid';
                        imageTiles = [];

                        // Only shown once there are actually image results to
                        // preview - items.length is guaranteed > 0 here, since
                        // the zero-results case above already returned early.
                        preview.textContent = PREVIEW_PLACEHOLDER;
                    }

                    // Lay out only this page's tiles and append them as their
                    // own justified rows below whatever's already there -
                    // earlier pages keep their existing layout rather than
                    // being torn down and re-flowed on every load. Each page
                    // justifies independently, so its last (partial) row stays
                    // at the target height and the next page starts fresh.
                    var tiles = items.map(function (result, index) {
                        var tile = new ImageTile(result, dimensions[index].width / dimensions[index].height);
                        tile.toDOM();
                        return tile;
                    });

                    imageTiles = imageTiles.concat(tiles);
                    layoutJustifiedGrid(results, tiles);

                    loadingMore = false;
                    maybeLoadMore();
                });
            })
            .catch(function (error) {
                if (query !== currentQuery || type !== currentType) {
                    return;
                }

                if (error instanceof RateLimited) {
                    // hasMore stays as it was, so scrolling doesn't keep
                    // firing requests that are only going to be refused.
                    hasMore = false;
                    status.textContent = 'Too many searches just now. Try again in '
                        + error.retryAfter + ' second' + (error.retryAfter === 1 ? '' : 's') + '.';
                } else {
                    status.textContent = 'Something went wrong searching. Try again.';
                }

                loadingMore = false;
            });
    }

    function search(query) {
        currentQuery = query;
        currentType = selectedType();
        loadedCount = 0;
        hasMore = false;
        loadingMore = false;
        resetPreview();
        imageTiles = [];

        // The index-wide counts only make sense when nothing is being
        // searched - a live query's own result count replaces them, and they
        // come back the moment the query is cleared.
        document.getElementById('index-stats').hidden = query !== '';

        if (query === '') {
            clearElement(results);
            status.textContent = '';
            return;
        }

        loadPage(0);
    }

    /**
     * Pushes the current query and result type into the address bar and runs
     * the search. Both go in, not just the query - the page reads them back
     * server-side, so a shared or reloaded URL comes up showing the same
     * thing rather than resetting to Pages.
     */
    function submit() {
        var query = input.value.trim();
        var type = selectedType();
        var url = window.location.pathname;

        if (query !== '') {
            url = '?q=' + encodeURIComponent(query) + '&result-type=' + encodeURIComponent(type);
        }

        window.history.pushState({query: query, type: type}, '', url);
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

    // Delegated on document rather than bound per tile - tiles are created
    // and thrown away by the hundred as results page in and the grid
    // re-flows, and one listener that outlives all of them is both cheaper
    // and impossible to leave dangling on a detached element.
    document.addEventListener('click', function (event) {
        var element = event.target.closest('.ImageTile');
        var tile = element !== null ? tileFor(element) : null;

        if (tile !== null) {
            selectTile(tile);
        }
    });

    document.addEventListener('keydown', function (event) {
        // Arrow keys and Escape drive the preview once an image is selected -
        // but never while the reader is typing in the query field, where the
        // arrows have to keep moving the text cursor.
        if (selectedTile !== null && event.target !== input) {
            if (event.key === 'ArrowRight') {
                event.preventDefault();
                selectAdjacentTile(1);
                return;
            }

            if (event.key === 'ArrowLeft') {
                event.preventDefault();
                selectAdjacentTile(-1);
                return;
            }

            if (event.key === 'Escape') {
                resetPreview();
                preview.textContent = PREVIEW_PLACEHOLDER;
                return;
            }
        }

        if (event.key !== 'Enter' && event.key !== ' ') {
            return;
        }

        var element = event.target.closest('.ImageTile');
        var tile = element !== null ? tileFor(element) : null;

        if (tile !== null) {
            event.preventDefault();
            selectTile(tile);
        }
    });

    document.addEventListener('change', function (event) {
        if (event.target.name === 'result-type') {
            submit();
        }
    });

    window.addEventListener('popstate', function () {
        var params = new URLSearchParams(window.location.search);
        var query = params.get('q') || '';
        var type = params.get('result-type') === 'image' ? 'image' : 'html';

        input.value = query;

        for (var i = 0; i < typeInputs.length; i++) {
            typeInputs[i].checked = typeInputs[i].value === type;
        }

        search(query);
    });

    window.addEventListener('scroll', maybeLoadMore);

    // Debounced: a drag-resize fires this continuously, and re-flowing the
    // whole grid on every intermediate width is work whose result is thrown
    // away a few milliseconds later.
    var resizeTimer = null;
    window.addEventListener('resize', function () {
        if (resizeTimer !== null) {
            clearTimeout(resizeTimer);
        }

        resizeTimer = setTimeout(function () {
            resizeTimer = null;
            relayoutImageGrid();
        }, 150);
    });

    if (input.value.trim() !== '') {
        search(input.value.trim());
    }
})();
