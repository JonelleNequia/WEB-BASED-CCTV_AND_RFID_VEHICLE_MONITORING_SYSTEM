/*
 * ROI work, Phase 7: Vehicle Activity Report.
 * Print and Save as PDF open the same print window (Save as PDF is a
 * destination there), so the PDF has the same layout as the printout.
 * Opened from Activity Logs › Print, the print window opens by itself once
 * the snapshots have loaded.
 */
(function () {
    const hint = document.querySelector('[data-report-pdf-hint]');

    function imagesLoaded() {
        return Promise.all(Array.from(document.images).map(function (image) {
            if (image.complete) {
                return null;
            }
            return new Promise(function (resolve) {
                image.addEventListener('load', resolve, { once: true });
                image.addEventListener('error', resolve, { once: true });
            });
        }));
    }

    async function openPrintWindow() {
        await imagesLoaded();
        window.print();
    }

    document.querySelector('[data-report-print]')?.addEventListener('click', function () {
        if (hint) {
            hint.hidden = true;
        }
        openPrintWindow();
    });

    document.querySelector('[data-report-pdf]')?.addEventListener('click', function () {
        if (hint) {
            hint.hidden = false;
        }
        openPrintWindow();
    });

    // Include snapshots: reload the report with or without the thumbnail column.
    document.querySelector('[data-report-snapshots]')?.addEventListener('change', function (event) {
        event.target.form.submit();
    });

    if (document.body.dataset.autoprint === '1') {
        // A reload of this tab should not open the print window again.
        const url = new URL(window.location.href);
        url.searchParams.delete('autoprint');
        window.history.replaceState(null, '', url);
        window.addEventListener('load', function () {
            window.setTimeout(openPrintWindow, 200);
        });
    }
})();
