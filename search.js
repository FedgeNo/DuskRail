(function () {
    var input = document.getElementById('query-input');
    var button = document.getElementById('search-button');
    var status = document.getElementById('status');
    var results = document.getElementById('results');

    function clearResults() {
        while (results.firstChild) {
            results.removeChild(results.firstChild);
        }
    }

    function buildResultRow(result) {
        var row = document.createElement('div');
        row.className = 'result';

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
        var titleLink = document.createElement('a');
        titleLink.href = result.url;
        titleLink.textContent = result.title || result.url;
        titleLine.appendChild(titleLink);
        text.appendChild(titleLine);

        var urlLine = document.createElement('div');
        urlLine.className = 'url';
        urlLine.textContent = result.url;
        text.appendChild(urlLine);

        if (result.description) {
            var descriptionLine = document.createElement('div');
            descriptionLine.className = 'description';
            descriptionLine.textContent = result.description;
            text.appendChild(descriptionLine);
        }

        row.appendChild(text);

        return row;
    }

    function search(query) {
        if (query === '') {
            clearResults();
            status.textContent = '';
            return;
        }

        status.textContent = 'Searching...';

        fetch('/api/search.php?q=' + encodeURIComponent(query))
            .then(function (response) { return response.json(); })
            .then(function (data) {
                clearResults();

                var items = data.results || [];

                if (items.length === 0) {
                    status.textContent = 'No results found for "' + query + '".';
                    return;
                }

                status.textContent = items.length + ' result' + (items.length === 1 ? '' : 's') + '.';
                items.forEach(function (result) {
                    results.appendChild(buildResultRow(result));
                });
            })
            .catch(function () {
                status.textContent = 'Something went wrong searching. Try again.';
            });
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

    window.addEventListener('popstate', function () {
        var params = new URLSearchParams(window.location.search);
        var query = params.get('q') || '';
        input.value = query;
        search(query);
    });

    if (input.value.trim() !== '') {
        search(input.value.trim());
    }
})();
