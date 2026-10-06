<?php

namespace App\Http\Controllers;

use App\Models\Gate;
use App\Services\Go2rtcService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Live view work: the signed-in way to go2rtc (its API listens on this PC
 * only). WebRTC offer -> answer, the HLS fallback files, and the player's
 * measurements (resolution, frames per second, delay) for System Status.
 */
class LiveViewController extends Controller
{
    /** go2rtc HLS files the player may load. */
    protected const HLS_FILES = ['stream.m3u8', 'hls/playlist.m3u8', 'hls/segment.ts', 'hls/segment.m4s', 'hls/init.mp4'];

    public function webrtc(Request $request, string $gate, Go2rtcService $go2rtc): Response
    {
        $code = Gate::resolveCode($gate) ?? abort(404);
        $offer = (string) $request->getContent();
        abort_unless(str_starts_with(ltrim($offer), 'v=0'), 422, 'A WebRTC offer is expected.');

        $go2rtc->ensureRunning();
        try {
            $answer = $go2rtc->webrtcAnswer($code, $offer);
        } catch (Throwable) {
            $answer = null;
        }

        return $answer
            ? response($answer, 200, ['Content-Type' => 'application/sdp', 'Cache-Control' => 'no-store'])
            : response('The live view is not available right now.', 503);
    }

    public function hls(Request $request, string $file): Response
    {
        abort_unless(in_array($file, self::HLS_FILES, true), 404);
        if ($request->has('src')) {
            abort_unless(Gate::resolveCode((string) $request->query('src')) !== null, 404);
        }

        try {
            $upstream = Http::timeout(15)->get(app(Go2rtcService::class)->apiUrl('/'.$file), $request->query());
        } catch (Throwable) {
            return response('The live view is not available right now.', 503);
        }

        return response($upstream->body(), $upstream->status(), [
            'Content-Type' => $upstream->header('Content-Type') ?: 'application/octet-stream',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * What a player measured (shown on System Status for 10 minutes).
     */
    public function stats(Request $request, string $gate): JsonResponse
    {
        $code = Gate::resolveCode($gate) ?? abort(404);
        $validated = $request->validate([
            'mode' => ['required', 'in:webrtc,hls,mjpeg'],
            'width' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'height' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'fps' => ['nullable', 'numeric', 'min:0', 'max:240'],
            'delay_ms' => ['nullable', 'numeric', 'min:0', 'max:60000'],
            'page' => ['nullable', 'string', 'max:40'],
        ]);

        // The latest of each mode, so System status can show WebRTC next to the basic view.
        Cache::put("live-view-stats.$code.{$validated['mode']}", [...$validated, 'at' => now()->toIso8601String()], now()->addMinutes(10));

        return response()->json(['ok' => true]);
    }
}
