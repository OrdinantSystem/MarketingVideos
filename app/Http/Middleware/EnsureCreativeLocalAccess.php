<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCreativeLocalAccess
{
    /** @var list<string> */
    private const LOOPBACK_RANGES = ['127.0.0.0/8', '::1/128'];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('creative.access.mode') !== 'local') {
            abort(503, 'The configured creative access mode is not supported.');
        }

        $remoteAddress = $request->server('REMOTE_ADDR');
        if (! is_string($remoteAddress)
            || ! IpUtils::checkIp($remoteAddress, self::LOOPBACK_RANGES)) {
            abort(403, 'The creative workspace is available only from this machine.');
        }

        $authority = $this->requestAuthority($request);
        $configuredPort = config('creative.access.port');
        if (! is_int($configuredPort) || $configuredPort < 1 || $configuredPort > 65535) {
            abort(503, 'The configured creative local port is invalid.');
        }
        if ($authority === null
            || ! $this->allowedHost($authority['host'])
            || ($authority['explicit_port'] && $authority['port'] !== $configuredPort)) {
            abort(403, 'The creative workspace requires a local loopback host.');
        }

        if (! in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)
            && ! $this->hasSafeOriginEvidence($request, $authority)) {
            abort(403, 'Cross-origin creative workspace changes are not allowed.');
        }

        return $next($request);
    }

    /** @return array{scheme: 'http'|'https', host: string, port: int, explicit_port: bool}|null */
    private function requestAuthority(Request $request): ?array
    {
        $hostHeader = $request->headers->get('host');
        if (! is_string($hostHeader)) {
            return null;
        }

        $parsedHost = $this->parseHostHeader($hostHeader);
        if ($parsedHost === null) {
            return null;
        }

        $httpsValue = $request->server('HTTPS');
        $requestSchemeValue = $request->server('REQUEST_SCHEME');
        $https = is_string($httpsValue) ? strtolower($httpsValue) : ($httpsValue === 1 ? '1' : '');
        $requestScheme = is_string($requestSchemeValue) ? strtolower($requestSchemeValue) : '';
        $scheme = in_array($https, ['1', 'on', 'true'], true) || $requestScheme === 'https'
            ? 'https'
            : 'http';
        $serverPort = $request->server('SERVER_PORT');
        $port = $parsedHost['port'];
        if ($port === null) {
            $port = is_numeric($serverPort) ? (int) $serverPort : ($scheme === 'https' ? 443 : 80);
        }
        if ($port < 1 || $port > 65535) {
            return null;
        }

        return [
            'scheme' => $scheme,
            'host' => $parsedHost['host'],
            'port' => $port,
            'explicit_port' => $parsedHost['port'] !== null,
        ];
    }

    /** @return array{host: string, port: int|null}|null */
    private function parseHostHeader(string $hostHeader): ?array
    {
        if ($hostHeader === ''
            || trim($hostHeader) !== $hostHeader
            || preg_match('/[\s\/@?#,]/', $hostHeader) === 1) {
            return null;
        }

        if (str_starts_with($hostHeader, '[')) {
            if (preg_match('/^\[([^\]]+)](?::([0-9]{1,5}))?$/', $hostHeader, $matches) !== 1) {
                return null;
            }

            $host = strtolower($matches[1]);
            $port = isset($matches[2]) ? (int) $matches[2] : null;
        } else {
            if (substr_count($hostHeader, ':') > 1
                || preg_match('/^([^:]+)(?::([0-9]{1,5}))?$/', $hostHeader, $matches) !== 1) {
                return null;
            }

            $host = strtolower($matches[1]);
            $port = isset($matches[2]) ? (int) $matches[2] : null;
        }

        if ($port !== null && ($port < 1 || $port > 65535)) {
            return null;
        }

        return ['host' => $host, 'port' => $port];
    }

    private function allowedHost(string $host): bool
    {
        return $host === 'localhost'
            || (filter_var($host, FILTER_VALIDATE_IP) !== false
                && IpUtils::checkIp($host, self::LOOPBACK_RANGES));
    }

    /** @param array{scheme: 'http'|'https', host: string, port: int, explicit_port: bool} $requestAuthority */
    private function hasSafeOriginEvidence(Request $request, array $requestAuthority): bool
    {
        foreach (['origin', 'referer'] as $header) {
            $value = $request->headers->get($header);
            if ($value === null) {
                continue;
            }
            if (! $this->sameOrigin($value, $requestAuthority)) {
                return false;
            }
        }

        return true;
    }

    /** @param array{scheme: 'http'|'https', host: string, port: int, explicit_port: bool} $requestAuthority */
    private function sameOrigin(string $url, array $requestAuthority): bool
    {
        if ($url === '' || trim($url) !== $url) {
            return false;
        }

        $parts = parse_url($url);
        if (! is_array($parts)
            || ! is_string($parts['scheme'] ?? null)
            || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower(trim($parts['host'], '[]'));
        $port = is_int($parts['port'] ?? null)
            ? $parts['port']
            : ($scheme === 'https' ? 443 : 80);

        return $scheme === $requestAuthority['scheme']
            && $host === $requestAuthority['host']
            && $port === $requestAuthority['port'];
    }
}
