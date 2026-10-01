/*
 * UHF tags in the Registry: the network UHF reader is not a keyboard like the
 * NFC reader, so "Read with UHF reader" asks the server for the tags the
 * reader read after the button was pressed (EPC, uppercase hex).
 *
 * window.uhfTagReader.listen(url, { seconds, onRead(read), onStatus(text, state), onEnd() }) -> stop()
 */
(function () {
    function listen(url, options) {
        const seconds = options.seconds || 30;
        let after = null;
        let stopped = false;
        let timer = null;
        const deadline = Date.now() + seconds * 1000;

        function stop() {
            stopped = true;
            clearTimeout(timer);
            options.onEnd?.();
        }

        async function poll() {
            if (stopped) {
                return;
            }
            if (Date.now() > deadline) {
                options.onStatus?.('No UHF tag was read. Press the button again and hold the tag closer to the reader.', 'error');
                stop();
                return;
            }
            try {
                const query = new URLSearchParams({ after: String(after === null ? 0 : after) });
                const response = await fetch(`${url}?${query}`, { headers: { Accept: 'application/json' } });
                const data = await response.json();
                if (after === null) {
                    // First call: only tags read from now on (server clock).
                    after = data.now;
                    if (!data.connected) {
                        options.onStatus?.('No UHF reader is connected. Assign one in Settings › Gates & Readers.', 'error');
                        stop();
                        return;
                    }
                    options.onStatus?.('Listening… hold the tag near the UHF reader.', 'pending');
                } else {
                    (data.reads || []).slice().reverse().forEach(function (read) {
                        after = Math.max(after, read.epoch);
                        if (!stopped) {
                            options.onRead?.(read);
                        }
                    });
                }
            } catch (error) {
                options.onStatus?.('Could not reach the server.', 'error');
            }
            if (!stopped) {
                timer = setTimeout(poll, 700);
            }
        }

        poll();
        return stop;
    }

    window.uhfTagReader = { listen: listen };
})();
