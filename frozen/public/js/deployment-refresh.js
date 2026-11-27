(() => {
    const url = document.body.dataset.versionUrl;
    if (!url) return;
    let known = null, notice;
    const show = text => {
        if (!notice) {
            notice = document.createElement('div'); notice.className = 'alert alert-info'; notice.setAttribute('role', 'status');
            notice.style.cssText = 'position:sticky;top:0;z-index:1000;padding:12px;background:#eee7ff;color:#30204a';
            document.body.prepend(notice);
        }
        notice.replaceChildren(document.createTextNode(text));
    };
    const check = async () => {
        try {
            const response = await fetch(url, {headers: {Accept: 'application/json'}, cache: 'no-store'});
            if (response.status === 503) { show('Server maintenance is active. Keep your entered values and wait before saving.'); return; }
            if (response.status === 401 || response.status === 403) { show('Your session changed. Copy any unsaved input before signing in again.'); return; }
            if (!response.ok) return;
            const current = await response.json();
            if (known && known.source && current.source !== known.source) {
                show('The server version changed. Save or copy any unsaved input, then refresh this page. ');
                const refresh = document.createElement('button'); refresh.type = 'button'; refresh.textContent = 'Refresh when ready';
                refresh.addEventListener('click', () => { if (window.confirm('Refresh now? Unsaved inputs on this page will be lost.')) window.location.reload(); });
                notice.append(refresh);
            } else if (notice) { notice.remove(); notice = null; }
            if (!known) known = current;
        } catch { show('The server is temporarily unavailable. Keep or copy unsaved input and wait before saving.'); }
    };
    check(); window.setInterval(check, 60000);
})();
