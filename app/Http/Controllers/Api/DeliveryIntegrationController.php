<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesIntegration;
use App\Http\Controllers\Controller;
use App\Services\LifecycleBarrier;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/** Receipts and canonical effects commit in one application database transaction. */
class DeliveryIntegrationController extends Controller
{
    use AuthorizesIntegration;

    public function store(Request $request, SettingsService $settings)
    {
        if ($denied = $this->authorizeIntegrationRequest($request, $settings)) return $denied;
        abort_unless(app(LifecycleBarrier::class)->enabled(), 503, 'Durable delivery is not initialized.');
        $data = $request->validate(['envelope' => ['required', 'string', 'max:1048576'], 'sha256' => ['required', 'regex:/^[a-f0-9]{64}$/'], 'media' => ['present', 'array']]);
        abort_unless(hash_equals(hash('sha256', $data['envelope']), $data['sha256']), 422, 'Envelope checksum mismatch.');
        try { $envelope = json_decode($data['envelope'], true, flags: JSON_THROW_ON_ERROR); }
        catch (\Throwable) { abort(422, 'Malformed envelope.'); }
        abort_unless(is_array($envelope), 422, 'Malformed envelope.');
        validator($envelope, [
            'schema' => ['required', 'integer', 'in:1'], 'installation' => ['required', 'uuid'], 'generation' => ['required', 'uuid'],
            'operation' => ['required', 'regex:/^[a-f0-9]{64}$/'], 'kind' => ['required', 'in:crossing,plate,rfid,event,guest,devices'],
            'occurrence' => ['required', 'string', 'max:120'], 'payload' => ['required', 'array'], 'media' => ['present', 'array'],
        ])->validate();
        $identity = app(LifecycleBarrier::class)->checkIdentity();
        abort_unless($envelope['installation'] === $identity['installation'] && $envelope['generation'] === $identity['generation'], 409, 'Installation/database generation mismatch.');
        $payload = $envelope['payload'];
        if ($envelope['kind'] !== 'rfid' && $envelope['kind'] !== 'devices') {
            abort_unless(($payload['external_event_key'] ?? null) === $envelope['occurrence'] && isset($payload['event_time']), 422, 'Original occurrence context is required.');
        } elseif ($envelope['kind'] === 'rfid') {
            abort_unless(isset($payload['scan_time']), 422, 'Original scan time is required.');
        }
        $files = []; $temporary = [];
        $journal = app(\App\Services\DeliveryMediaJournal::class);
        try {
            abort_unless(array_keys($data['media']) === array_keys($envelope['media']), 422, 'Media inventory mismatch.');
            foreach ($envelope['media'] as $name => $info) {
                abort_unless(in_array($name, ['snapshot', 'plate_image'], true) && is_array($info), 422);
                abort_unless(is_string($data['media'][$name]), 422, 'Malformed evidence.');
                $bytes = base64_decode($data['media'][$name], true);
                abort_unless($bytes !== false && strlen($bytes) <= 10485760 && strlen($bytes) === ($info['bytes'] ?? null)
                    && hash_equals(hash('sha256', $bytes), (string) ($info['sha256'] ?? '')), 422, 'Evidence checksum mismatch.');
                $path = tempnam(sys_get_temp_dir(), 'delivery-'); $temporary[] = $path;
                file_put_contents($path, $bytes);
                $files[$name] = new UploadedFile($path, 'evidence.jpg', null, null, true);
            }
            $journal->begin();
            $result = DB::transaction(function () use ($envelope, $data, $request, $payload, $files) {
                // Serialize before reading receipts, including SQLite's deferred-transaction case.
                DB::table('plate_processing_locks')->where('id', 1)->increment('version');
                $old = DB::table('delivery_receipts')->where('operation', $envelope['operation'])->first();
                if ($old) {
                    abort_unless(hash_equals($old->sha256, $data['sha256']) && $old->installation === $envelope['installation'] && $old->generation === $envelope['generation'], 409, 'Operation identity conflicts with committed content.');
                    return array_replace(json_decode($old->result_json, true, flags: JSON_THROW_ON_ERROR), ['ack_effect' => 'verified', 'created' => false, 'duplicate' => true]);
                }
                $targets = ['crossing' => [CrossingIntegrationController::class, 'store'], 'plate' => [VisitorIntegrationController::class, 'storePlate'],
                    'rfid' => [FutureIntegrationController::class, 'receiveRfidScan'], 'event' => [FutureIntegrationController::class, 'receive'],
                    'guest' => [\App\Http\Controllers\GuestObservationController::class, 'store'], 'devices' => [DeviceIntegrationController::class, 'store']];
                app(\App\Services\RfidCameraFusionService::class)->finalizeExpired();
                $forward = Request::create($request->url(), 'POST', $payload, [], $files, $request->server->all());
                $forward->headers->replace($request->headers->all());
                $forward->setJson(new \Symfony\Component\HttpFoundation\InputBag($payload));
                $previous = app('request'); app()->instance('request', $forward);
                try { $response = app()->call([app($targets[$envelope['kind']][0]), $targets[$envelope['kind']][1]], ['request' => $forward]); }
                finally { app()->instance('request', $previous); }
                if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                    // Do not commit partial legacy effects or claim acceptance for a failed action.
                    throw new \Illuminate\Http\Exceptions\HttpResponseException($response);
                }
                $body = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
                if (($body['image_update'] ?? null) === 'failed') abort(503, 'Evidence was not committed; retain original bytes.');
                $result = ['ack_effect' => 'committed', 'duplicate' => false, 'created' => $response->getStatusCode() === 201, 'result' => $body,
                    'delivery_ack' => ['schema' => 1, 'operation' => $envelope['operation'], 'sha256' => $data['sha256'],
                        'installation' => $envelope['installation'], 'generation' => $envelope['generation'], 'committed' => true]];
                DB::table('delivery_receipts')->insert(['operation' => $envelope['operation'], 'sha256' => $data['sha256'],
                    'installation' => $envelope['installation'], 'generation' => $envelope['generation'], 'kind' => $envelope['kind'],
                    'result_json' => json_encode($result, JSON_THROW_ON_ERROR), 'committed_at' => now()]);
                return $result;
            }, 5);
            return response()->json($result);
        } finally { $journal->finish(); foreach ($temporary as $path) @unlink($path); }
    }
}
