@php($candidate = $updateStatus['candidate'] ?? null)
<section class="panel" data-updates>
    <h2>Updates</h2>
    <p>Only the main server installs updates. Internet access is optional; monitoring continues while checking or downloading.</p>
    @if (!($updateStatus['provisioned'] ?? false))
        <p role="status">Update trust is not provisioned. An OS administrator must verify publisher public material and register the retained recovery controller. Unsigned legacy packages cannot be adopted automatically.</p>
    @else
        <dl>
            <dt>Installed version</dt><dd>{{ $updateStatus['installed']['version'] }} · {{ $updateStatus['installed']['platform'] }}</dd>
            <dt>Installed source</dt><dd class="text-break">{{ $updateStatus['installed']['source']['sha256'] }}</dd>
            <dt>Last check</dt><dd>{{ $updateStatus['last_check']['outcome'] }} {{ $updateStatus['last_check']['at'] ?? '' }}</dd>
            <dt>Installation / recovery</dt><dd>{{ $updateStatus['result']['outcome'] ?? 'No installation requested' }}</dd>
        </dl>
        <form method="post" action="{{ route('settings.updates.request') }}">@csrf
            <input type="hidden" name="action" value="check"><button class="button" type="submit">Check for Updates</button>
        </form>
        <details><summary>Automatic preparation and channel</summary>
            <form method="post" action="{{ route('settings.updates.request') }}">@csrf
                <input type="hidden" name="action" value="preferences">
                <label>Channel <select name="channel">@foreach(['stable','prerelease','development'] as $channel)<option value="{{ $channel }}" @selected(($updateStatus['preferences']['channel'] ?? 'stable') === $channel)>{{ ucfirst($channel) }}</option>@endforeach</select></label>
                <label><input type="checkbox" name="automatic_check" value="1" @checked($updateStatus['preferences']['automatic_check'] ?? false)> Automatically check</label>
                <label><input type="checkbox" name="automatic_download" value="1" @checked($updateStatus['preferences']['automatic_download'] ?? false)> Prepare complete downloads automatically</label>
                <p>Automatic preparation requires the installed native worker. It never installs an update.</p><button class="button" type="submit">Save preparation preferences</button>
            </form>
        </details>
        @if($candidate)
            <h3>Available: {{ $candidate['manifest']['signed']['version'] }}</h3>
            <p style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $candidate['notes'] }}</p>
            <p>{{ $candidate['ready'] ? 'Authenticated package prepared. Native compatibility and recovery checks are still required.' : 'Complete package has not been downloaded and validated.' }}</p>
            @if(isset($candidate['requirements']))<p>Estimated disk needed: {{ number_format($candidate['requirements']['required_disk_bytes']/1073741824,2) }} GiB. Duration depends on storage and the certified migrations. {{ $candidate['requirements']['downtime'] }}</p><p>{{ $candidate['requirements']['recovery'] }}</p>@endif
            <form method="post" action="{{ route('settings.updates.request') }}">@csrf
                <input type="hidden" name="candidate" value="{{ $candidate['id'] }}"><input type="hidden" name="action" value="{{ !$candidate['ready'] ? 'download' : (($candidate['install_ready'] ?? false) ? 'install' : 'plan') }}">
                @if($candidate['install_ready'] ?? false)<input type="hidden" name="plan_confirmation" value="{{ $candidate['plan_confirmation'] }}"><label><input type="checkbox" name="maintenance_confirmed" value="1" required> I confirm this version change requires monitoring downtime, a coherent recovery backup, and local OS administrator approval.</label>@endif
                <button class="button button-primary" type="submit">{{ !$candidate['ready'] ? 'Download complete package' : (($candidate['install_ready'] ?? false) ? 'Request installation' : 'Prepare installation plan') }}</button>
            </form>
        @endif
        @if(isset($updateStatus['installation_request']))
            <p>Ask the local OS administrator to review request <code>{{ $updateStatus['installation_request']['id'] }}</code> with the retained controller. No service is stopped by this page.</p>
        @endif
        <h3>Import from USB or local disk</h3>
        <form data-update-import data-url="{{ route('settings.updates.import') }}">@csrf
            <label>Complete package <input type="file" name="package" accept=".zip,.gz" required></label>
            <label>Fresh signed update metadata <input type="file" name="manifest" accept=".json" required></label>
            <button class="button" type="submit">Import complete package</button><p data-update-progress role="status" aria-live="polite"></p>
        </form>
        <p>Uploads are bounded to 4 GiB, sent in 4 MiB parts, and become usable only after native signature/content validation. The local administrator can also import directly without a browser or internet.</p>
    @endif
</section>
@push('scripts')<script src="{{ asset('js/updates.js') }}?v={{ filemtime(public_path('js/updates.js')) }}" defer></script>@endpush
@push('styles')
<style>
[data-updates] { min-width:0; padding:var(--space-4); }
[data-updates] dd { margin-inline-start:0; overflow-wrap:anywhere; }
[data-updates] form { margin-block:var(--space-4); }
[data-updates] label { display:block; margin-block:var(--space-2); }
[data-updates] input[type=file] { max-width:100%; }
[data-updates] code { overflow-wrap:anywhere; }
</style>
@endpush
