(function () {
    var retries = new WeakMap();

    // Real source failures are cacheable placeholder images. HTTP errors
    // here are temporary: do not turn a busy processing pool into a blank
    // thumbnail for the rest of the reader's visit.
    document.addEventListener('error', function (event) {
        if (event.target.tagName !== 'IMG') {
            return;
        }

        var image = event.target;
        var url = image.getAttribute('src') || '';

        if (!url.startsWith('/thumbnails/')) {
            return;
        }

        var retry = retries.get(image) || {delay: 2000, timer: null};

        if (retry.timer !== null) {
            return;
        }

        function retry_image() {
            retry.timer = null;

            if (!image.isConnected || image.getAttribute('src') !== url) {
                retries.delete(image);
                return;
            }

            if (document.hidden) {
                retry.timer = setTimeout(retry_image, 60000);
                return;
            }

            image.removeAttribute('src');
            image.setAttribute('src', url);
        }

        retry.timer = setTimeout(retry_image, retry.delay + Math.floor(Math.random() * 1000));
        retry.delay = Math.min(60000, retry.delay * 2);
        retries.set(image, retry);
    }, true);

    document.addEventListener('load', function (event) {
        var retry = retries.get(event.target);

        if (retry) {
            clearTimeout(retry.timer);
            retries.delete(event.target);
        }
    }, true);
})();
