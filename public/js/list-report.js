// A missing/corrupt occurrence image must leave a readable report placeholder.
(function () {
    document.querySelectorAll('[data-report-image]').forEach((image) => {
        const unavailable = () => {
            image.closest('figure').hidden = true;
            image.closest('td').querySelector('.no-snapshot').hidden = false;
        };
        image.addEventListener('error', unavailable, { once: true });
        if (image.complete && !image.naturalWidth) unavailable();
    });
})();
