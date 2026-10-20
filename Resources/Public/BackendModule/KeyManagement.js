// Neos 9's module controller drops the module's Cache-Control header, so a page restored from the back/forward cache reloads for a fresh verdict.
window.addEventListener('pageshow', function (event) {
    if (event.persisted) {
        window.location.reload();
    }
});
