<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Plug-and-detect: check a network camera's login and find its stream URL.
 *
 * - ONVIF (GetProfiles + GetStreamUri, WS-Security digest) when the camera
 *   announced an ONVIF address during the scan.
 * - Otherwise the vendor stream paths from discovery_profiles.json, checked
 *   with an RTSP DESCRIBE (Basic or Digest auth).
 *
 * Every call has a short timeout so the Settings page never hangs.
 */
class CameraProbeService
{
    public const OK = 'ok';

    public const UNAUTHORIZED = 'unauthorized';

    public const NOT_FOUND = 'not_found';

    public const UNREACHABLE = 'unreachable';

    public const ERROR = 'error';

    protected function timeout(): float
    {
        return (float) config('monitoring.devices.probe_timeout_seconds', 3);
    }

    /**
     * RTSP DESCRIBE with the given login.
     *
     * @return array{result: string, status: int|null, message: string}
     */
    public function describe(string $url, string $username = '', string $password = ''): array
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? null;
        $port = $parts['port'] ?? getservbyname('rtsp', 'tcp') ?: null;

        if (! $host || ! $port) {
            return ['result' => self::ERROR, 'status' => null, 'message' => 'The stream address is incomplete.'];
        }

        $socket = @stream_socket_client('tcp://'.$host.':'.$port, $errorCode, $errorMessage, $this->timeout());

        if ($socket === false) {
            return ['result' => self::UNREACHABLE, 'status' => null, 'message' => 'The camera did not answer ('.($errorMessage ?: 'timeout').').'];
        }

        stream_set_timeout($socket, (int) ceil($this->timeout()));

        try {
            $response = $this->rtspRequest($socket, 'DESCRIBE', $url, 1);
            $status = $response['status'];

            if ($status === 401 && $username !== '') {
                $authorization = $this->authorization($response['headers']['www-authenticate'] ?? '', 'DESCRIBE', $url, $username, $password);

                if ($authorization !== null) {
                    $response = $this->rtspRequest($socket, 'DESCRIBE', $url, 2, $authorization);
                    $status = $response['status'];
                }
            }
        } catch (Throwable $exception) {
            return ['result' => self::ERROR, 'status' => null, 'message' => $exception->getMessage()];
        } finally {
            fclose($socket);
        }

        return match (true) {
            $status === 200 => ['result' => self::OK, 'status' => $status, 'message' => 'Stream found and login accepted.'],
            $status === 401 || $status === 403 => ['result' => self::UNAUTHORIZED, 'status' => $status, 'message' => 'The camera rejected the username or password.'],
            $status === 404 => ['result' => self::NOT_FOUND, 'status' => $status, 'message' => 'The camera has no stream at this path.'],
            default => ['result' => self::ERROR, 'status' => $status, 'message' => 'Unexpected camera answer (RTSP '.($status ?? 'none').').'],
        };
    }

    /**
     * @param  resource  $socket
     * @return array{status: int|null, headers: array<string, string>}
     */
    protected function rtspRequest($socket, string $method, string $url, int $sequence, ?string $authorization = null): array
    {
        $request = "{$method} {$url} RTSP/1.0\r\nCSeq: {$sequence}\r\nAccept: application/sdp\r\nUser-Agent: philcst-device-check\r\n";

        if ($authorization !== null) {
            $request .= "Authorization: {$authorization}\r\n";
        }

        fwrite($socket, $request."\r\n");

        $head = '';
        while (! str_contains($head, "\r\n\r\n") && ! feof($socket) && strlen($head) < 16384) {
            $line = fgets($socket, 4096);

            if ($line === false) {
                break;
            }

            $head .= $line;
        }

        $headers = [];
        foreach (array_slice(explode("\r\n", $head), 1) as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }

        // Skip a body (SDP) so the next request on this socket starts clean.
        $length = (int) ($headers['content-length'] ?? 0);
        if ($length > 0) {
            fread($socket, min($length, 65536));
        }

        preg_match('#^RTSP/\d\.\d\s+(\d{3})#', $head, $match);

        return ['status' => isset($match[1]) ? (int) $match[1] : null, 'headers' => $headers];
    }

    protected function authorization(string $challenge, string $method, string $uri, string $username, string $password): ?string
    {
        if (stripos($challenge, 'Digest') === 0) {
            preg_match_all('/(\w+)="?([^",]+)"?/', $challenge, $pairs, PREG_SET_ORDER);
            $values = [];
            foreach ($pairs as $pair) {
                $values[strtolower($pair[1])] = $pair[2];
            }

            if (! isset($values['realm'], $values['nonce'])) {
                return null;
            }

            $ha1 = md5($username.':'.$values['realm'].':'.$password);
            $ha2 = md5($method.':'.$uri);
            $response = md5($ha1.':'.$values['nonce'].':'.$ha2);

            return sprintf(
                'Digest username="%s", realm="%s", nonce="%s", uri="%s", response="%s"',
                $username, $values['realm'], $values['nonce'], $uri, $response
            );
        }

        if (stripos($challenge, 'Basic') === 0) {
            return 'Basic '.base64_encode($username.':'.$password);
        }

        return null;
    }

    /**
     * ONVIF media profiles with their RTSP URIs (main stream first).
     *
     * @return array{result: string, streams: list<array{token: string, name: string, uri: string, width: int|null}>, message: string}
     */
    public function onvifStreams(string $deviceServiceUrl, string $username, string $password): array
    {
        $mediaUrl = preg_replace('#/onvif/device_service$#', '/onvif/media_service', $deviceServiceUrl) ?: $deviceServiceUrl;

        try {
            $profiles = $this->soap($mediaUrl, '<GetProfiles xmlns="http://www.onvif.org/ver10/media/wsdl"/>', $username, $password);

            if ($profiles['status'] === 400 || $profiles['status'] === 404) {
                // Some cameras serve media on the device service address.
                $mediaUrl = $deviceServiceUrl;
                $profiles = $this->soap($mediaUrl, '<GetProfiles xmlns="http://www.onvif.org/ver10/media/wsdl"/>', $username, $password);
            }
        } catch (Throwable) {
            return ['result' => self::UNREACHABLE, 'streams' => [], 'message' => 'The camera ONVIF service did not answer.'];
        }

        if ($this->soapUnauthorized($profiles)) {
            return ['result' => self::UNAUTHORIZED, 'streams' => [], 'message' => 'The camera rejected the username or password.'];
        }

        preg_match_all('#<[^>]*Profiles[^>]*token="([^"]+)"[^>]*>(.*?)</[^>]*Profiles>#s', $profiles['body'], $matches, PREG_SET_ORDER);

        $streams = [];
        foreach ($matches as $match) {
            preg_match('#<[^>]*:?Name>([^<]*)<#', $match[2], $name);
            preg_match('#<[^>]*Width>(\d+)<#', $match[2], $width);

            try {
                $uri = $this->soap($mediaUrl,
                    '<GetStreamUri xmlns="http://www.onvif.org/ver10/media/wsdl"><StreamSetup>'
                    .'<Stream xmlns="http://www.onvif.org/ver10/schema">RTP-Unicast</Stream>'
                    .'<Transport xmlns="http://www.onvif.org/ver10/schema"><Protocol>RTSP</Protocol></Transport>'
                    .'</StreamSetup><ProfileToken>'.htmlspecialchars($match[1]).'</ProfileToken></GetStreamUri>',
                    $username, $password);
            } catch (Throwable) {
                continue;
            }

            if (preg_match('#<[^>]*Uri>([^<]+)<#', $uri['body'], $found)) {
                $streams[] = [
                    'token' => $match[1],
                    'name' => trim($name[1] ?? $match[1]),
                    'uri' => html_entity_decode(trim($found[1])),
                    'width' => isset($width[1]) ? (int) $width[1] : null,
                ];
            }
        }

        if ($streams === []) {
            return ['result' => self::ERROR, 'streams' => [], 'message' => 'The camera did not list any stream over ONVIF.'];
        }

        // Main stream = highest resolution.
        usort($streams, fn (array $a, array $b): int => ($b['width'] ?? 0) <=> ($a['width'] ?? 0));

        return ['result' => self::OK, 'streams' => $streams, 'message' => 'Streams found over ONVIF.'];
    }

    /**
     * @return array{status: int, body: string}
     */
    protected function soap(string $url, string $body, string $username, string $password): array
    {
        $security = '';

        if ($username !== '') {
            $nonce = random_bytes(16);
            $created = gmdate('Y-m-d\TH:i:s\Z');
            $digest = base64_encode(sha1($nonce.$created.$password, true));
            $security = '<Security s:mustUnderstand="1" xmlns="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">'
                .'<UsernameToken><Username>'.htmlspecialchars($username).'</Username>'
                .'<Password Type="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordDigest">'.$digest.'</Password>'
                .'<Nonce EncodingType="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary">'.base64_encode($nonce).'</Nonce>'
                .'<Created xmlns="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd">'.$created.'</Created>'
                .'</UsernameToken></Security>';
        }

        $envelope = '<?xml version="1.0" encoding="UTF-8"?><s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope">'
            .'<s:Header>'.$security.'</s:Header><s:Body>'.$body.'</s:Body></s:Envelope>';

        $response = Http::timeout($this->timeout())
            ->connectTimeout($this->timeout())
            ->withBody($envelope, 'application/soap+xml; charset=utf-8')
            ->post($url);

        return ['status' => $response->status(), 'body' => $response->body()];
    }

    /**
     * @param  array{status: int, body: string}  $response
     */
    protected function soapUnauthorized(array $response): bool
    {
        return $response['status'] === 401
            || str_contains($response['body'], 'NotAuthorized')
            || str_contains($response['body'], 'FailedAuthentication');
    }
}
