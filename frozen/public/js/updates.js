(() => {
    const form = document.querySelector('[data-update-import]');
    if (!form) return;
    let uploading = false;
    window.addEventListener('beforeunload', event => { if (uploading) { event.preventDefault(); event.returnValue = ''; } });
    form.addEventListener('submit', async event => {
        event.preventDefault(); if (uploading) return;
        const output = form.querySelector('[data-update-progress]'), button = form.querySelector('button');
        const packageFile = form.elements.package.files[0], metadata = form.elements.manifest.files[0];
        if (!packageFile || !metadata) return;
        if (packageFile.size > 4 * 1024 ** 3 || metadata.size > 1024 ** 2) { output.textContent = 'Package or metadata exceeds the import limit.'; return; }
        const headers = {Accept: 'application/json', 'X-CSRF-TOKEN': form.elements._token.value};
        uploading = true; button.disabled = true;
        try {
            const start = new FormData(); start.append('manifest', metadata); start.append('filename', packageFile.name); start.append('size', packageFile.size);
            const first = await fetch(form.dataset.url, {method: 'POST', headers, body: start});
            if (!first.ok) throw new Error('Import could not start. Check permissions, file size, and update provisioning.');
            const ticket = await first.json();
            for (let offset = 0; offset < packageFile.size; offset += 4 * 1024 ** 2) {
                const chunk = new FormData(); chunk.append('offset', offset); chunk.append('chunk', packageFile.slice(offset, offset + 4 * 1024 ** 2), 'part.bin');
                const response = await fetch(ticket.chunk_url, {method: 'POST', headers, body: chunk});
                if (!response.ok) throw new Error('Upload interrupted. Incomplete uploads cannot be installed; restart the import.');
                output.textContent = `Uploaded ${Math.min(100, Math.round((offset + 4 * 1024 ** 2) / packageFile.size * 100))}%.`;
            }
            output.textContent = 'Upload complete. Native validation is queued; no update has been installed. Refresh Updates after the worker finishes.';
        } catch (error) { output.textContent = error.message; }
        finally { uploading = false; button.disabled = false; }
    });
})();
