<?php

namespace App\Http\Controllers;

use App\Models\Gate;
use App\Models\SystemSetting;
use App\Services\DeviceRegistryService;
use App\Services\ReaderNetworkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Live view work, Phase 2: Settings › Gates › reader card, "Move reader to
 * this network" (read, then apply after the user confirms) and the
 * temporary workaround switch.
 */
class ReaderNetworkController extends Controller
{
    public function status(string $gate, ReaderNetworkService $service): JsonResponse
    {
        return response()->json($service->state($this->gate($gate)) ?? ['missing' => true])->header('Cache-Control', 'no-store');
    }

    public function read(Request $request, string $gate, ReaderNetworkService $service): JsonResponse
    {
        $login = $request->validate($this->loginRules());
        $id = $service->requestRead($this->gate($gate), $login);

        return response()->json(['request_id' => $id, 'message' => 'Reading the reader\'s settings…']);
    }

    public function apply(Request $request, string $gate, ReaderNetworkService $service): JsonResponse
    {
        $validated = $request->validate([
            'mode' => ['required', 'in:static,dhcp'],
            'ip' => ['nullable', 'required_if:mode,static', 'ipv4'],
            'confirm' => ['accepted'],
            ...$this->loginRules(),
        ]);
        $id = $service->requestApply($this->gate($gate), $validated['mode'], $validated['ip'] ?? null, $validated);

        return response()->json(['request_id' => $id, 'message' => 'Changing the reader\'s address…']);
    }

    public function workaround(Request $request, DeviceRegistryService $registry): RedirectResponse
    {
        $enabled = $request->boolean('enabled');
        SystemSetting::query()->updateOrCreate(['setting_key' => 'reader_workaround'], ['setting_value' => $enabled ? '1' : '0']);
        $registry->exportRuntimeConfig();

        return back()->with('status', $enabled ? 'Temporary workaround turned on.' : 'Temporary workaround turned off.');
    }

    protected function gate(string $gate): Gate
    {
        return Gate::query()->where('code', Gate::resolveCode($gate) ?? abort(404))->firstOrFail();
    }

    /**
     * @return array<string, list<string>>
     */
    protected function loginRules(): array
    {
        // The reader module's own login (not a user of this system); 5 characters at most.
        return ['username' => ['nullable', 'string', 'max:5'], 'password' => ['nullable', 'string', 'max:5']];
    }
}
