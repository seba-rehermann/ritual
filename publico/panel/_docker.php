<?php
/**
 * Helpers para hablar con el Docker Engine API vía curl + unix socket.
 * Requiere que /var/run/docker.sock esté montado en el container y que
 * www-data pertenezca al grupo del socket.
 */

declare(strict_types=1);

const DOCKER_SOCK = '/var/run/docker.sock';
const DOCKER_API  = 'http://localhost/v1.43';

/**
 * Llama a un endpoint del Docker API.
 * @return array{http:int,body:string,error:?string}
 */
function docker_api(string $method, string $path, ?string $body = null, int $timeout = 10): array {
    if (!file_exists(DOCKER_SOCK)) {
        return ['http' => 0, 'body' => '', 'error' => 'docker.sock no montado'];
    }
    $cmd = 'curl -sS --max-time ' . (int)$timeout
         . ' --unix-socket ' . escapeshellarg(DOCKER_SOCK)
         . ' -X ' . escapeshellarg(strtoupper($method));
    if ($body !== null) {
        $cmd .= " -H 'Content-Type: application/json'"
              . ' --data-binary ' . escapeshellarg($body);
    }
    $cmd .= " -w '\n%{http_code}' "
          . escapeshellarg(DOCKER_API . $path)
          . ' 2>&1';
    $out = shell_exec($cmd) ?? '';
    $parts = explode("\n", rtrim($out));
    $code  = (int) array_pop($parts);
    return ['http' => $code, 'body' => implode("\n", $parts), 'error' => null];
}

function docker_available(): bool {
    return docker_api('GET', '/_ping', null, 3)['http'] === 200;
}

/**
 * Demultiplexa el stream binario de Docker (header 8 bytes por frame).
 * Si los bytes no parecen ser un stream multiplexado, devuelve tal cual.
 */
function docker_demux(string $raw): string {
    $out = '';
    $i = 0;
    $len = strlen($raw);
    $ok = false;
    while ($i + 8 <= $len) {
        $stream = ord($raw[$i]);
        if ($stream !== 1 && $stream !== 2) {
            return $raw; // no es multiplexado
        }
        $size = (ord($raw[$i+4]) << 24) | (ord($raw[$i+5]) << 16)
              | (ord($raw[$i+6]) << 8)  |  ord($raw[$i+7]);
        $i += 8;
        if ($i + $size > $len) break;
        $out .= substr($raw, $i, $size);
        $i += $size;
        $ok = true;
    }
    return $ok ? $out : $raw;
}

/**
 * Ejecuta un comando dentro de un contenedor y devuelve stdout+stderr.
 * $cmd debe ser un array (argv), sin pasar por la shell del host.
 */
function docker_exec(string $container, array $cmd, int $timeout = 15): string {
    $create = docker_api('POST', "/containers/" . rawurlencode($container) . "/exec",
        json_encode([
            'AttachStdout' => true,
            'AttachStderr' => true,
            'Tty'          => false,
            'Cmd'          => $cmd,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        $timeout);
    if ($create['http'] !== 201) {
        return "✗ exec create HTTP {$create['http']}: {$create['body']}";
    }
    $id = json_decode($create['body'], true)['Id'] ?? null;
    if (!$id) return "✗ exec sin id";

    $start = docker_api('POST', "/exec/{$id}/start",
        json_encode(['Detach' => false, 'Tty' => false]),
        $timeout);
    if ($start['http'] !== 200) {
        return "✗ exec start HTTP {$start['http']}: {$start['body']}";
    }
    return docker_demux($start['body']);
}

function container_state(string $name): array {
    $r = docker_api('GET', '/containers/' . rawurlencode($name) . '/json');
    if ($r['http'] !== 200) return ['exists' => false, 'running' => false, 'status' => 'sin docker'];
    $j = json_decode($r['body'], true) ?: [];
    return [
        'exists'  => true,
        'running' => (bool)($j['State']['Running'] ?? false),
        'status'  => $j['State']['Status'] ?? 'unknown',
        'started' => $j['State']['StartedAt'] ?? null,
        'image'   => $j['Config']['Image'] ?? '',
    ];
}

function container_restart(string $name): string {
    $r = docker_api('POST', '/containers/' . rawurlencode($name) . '/restart?t=10', null, 20);
    return $r['http'] === 204 ? "✓ {$name} reiniciado" : "✗ {$name}: HTTP {$r['http']} — {$r['body']}";
}

function container_logs(string $name, int $tail = 150, bool $timestamps = true): string {
    $q = 'stdout=1&stderr=1&tail=' . (int)$tail . ($timestamps ? '&timestamps=1' : '');
    $r = docker_api('GET', '/containers/' . rawurlencode($name) . '/logs?' . $q);
    if ($r['http'] !== 200) return "✗ No se pudieron leer logs: HTTP {$r['http']}";
    $clean = docker_demux($r['body']);
    $clean = preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', $clean) ?? $clean;
    return $clean !== '' ? $clean : '(sin salida)';
}
