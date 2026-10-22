<?php

declare(strict_types=1);

require __DIR__ . '/run.php';

/** Exercise real php://input, redirects, cookies and example responses. */
function httpRequest(string $url, string $method = 'GET', string $body = '', array $headers = []): array
{
    $context = stream_context_create(['http' => [
        'method' => $method,
        'content' => $body,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 5,
    ]]);
    $stream = fopen($url, 'rb', false, $context);
    if ($stream === false) {
        throw new RuntimeException('HTTP fixture could not be reached.');
    }
    $body = stream_get_contents($stream);
    $headers = stream_get_meta_data($stream)['wrapper_data'];
    fclose($stream);
    preg_match('/\s(\d{3})\s/', $headers[0], $match);
    return [(int) $match[1], $headers, $body];
}

$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($socket === false) {
    throw new RuntimeException($error);
}
$address = stream_socket_get_name($socket, false);
fclose($socket);
$log = tempnam(sys_get_temp_dir(), 'router-http-');
$process = proc_open([
    PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_reporting=32767',
    '-d', 'session.save_path=' . sys_get_temp_dir(), '-S', $address, __DIR__ . '/http-fixture.php',
], [['pipe', 'r'], ['file', $log, 'a'], ['file', $log, 'a']], $pipes, dirname(__DIR__));
if (!is_resource($process)) {
    throw new RuntimeException('Could not start the PHP test server.');
}
fclose($pipes[0]);
$base = 'http://' . $address;
try {
    $ready = false;
    for ($attempt = 0; $attempt < 40; ++$attempt) {
        // Connection refusal during startup is expected.
        restore_error_handler();
        $probe = @fsockopen('127.0.0.1', (int) substr(strrchr($address, ':'), 1), $errno, $error, 0.1);
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        if (is_resource($probe)) {
            fclose($probe);
            $ready = true;
            break;
        }
        usleep(50000);
    }
    check($ready, 'HTTP fixture must start.');
    [$status, , $body] = httpRequest($base . '/?route=/items/42', 'PUT', 'name=hello+world', ['Content-Type: application/x-www-form-urlencoded']);
    check($status === 200 && json_decode($body, true) === ['name' => 'hello world', 'id' => '42'], 'Native HTTP PUT must parse the body.');
    [$status, , $body] = httpRequest($base . '/?route=/items/42', 'POST', '_method=DELETE&name=test', ['Content-Type: application/x-www-form-urlencoded']);
    check($status === 200 && json_decode($body, true) === ['name' => 'test', 'id' => '42'], 'HTTP POST method override must reach DELETE.');
    [$status] = httpRequest($base . '/?route[]=x');
    check($status === 400, 'HTTP array path must return 400 without exposing an exception.');
    [$status] = httpRequest($base . '/?route=/items/42', 'PUT', '{}', ['Content-Type: application/json']);
    check($status === 415, 'HTTP unsupported payload must return 415.');
    [$status, $headers] = httpRequest($base . '/?route=/redirect&to=/health');
    check($status === 302 && in_array('Location: ' . $base . '/health', $headers, true), 'HTTP redirect must emit the validated destination.');
    [$status, $headers] = httpRequest($base . '/?route=/redirect&to=https://evil.example');
    check($status === 400 && !array_filter($headers, static fn($header) => str_starts_with($header, 'Location:')), 'External redirects must not emit Location.');

    [$status, $headers, $body] = httpRequest($base . '/exemple/spoofing/index.php');
    check($status === 200 && preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $body, $token) === 1, 'Example form must include a CSRF token.');
    $cookies = array_values(array_filter($headers, static fn($header) => str_starts_with($header, 'Set-Cookie:')));
    check(count($cookies) > 0, 'Example must create a session cookie.');
    $cookie = explode(';', substr($cookies[0], strlen('Set-Cookie: ')))[0];
    [$status, , $body] = httpRequest($base . '/exemple/spoofing/index.php', 'POST', 'first_name=%3Csvg%20onload%3Dalert%281%29%3E', ['Content-Type: application/x-www-form-urlencoded', 'Cookie: ' . $cookie]);
    check($status === 403 && !str_contains($body, '<svg'), 'Example must reject POST without CSRF and never reflect raw HTML.');
    [$status, , $body] = httpRequest($base . '/exemple/spoofing/index.php', 'POST', '_csrf=' . $token[1] . '&_method=DELETE&first_name=%3Csvg%20onload%3Dalert%281%29%3E&password=do-not-reflect', ['Content-Type: application/x-www-form-urlencoded', 'Cookie: ' . $cookie]);
    check($status === 200 && str_contains($body, 'DELETE :: Spoofing') && !str_contains($body, '<svg') && !str_contains($body, 'do-not-reflect'), 'Valid CSRF override must work without exposing submitted values.');
    [$status] = httpRequest($base . '/exemple/controller/index.php?route=/logado&user=true');
    check($status === 403, 'Query parameters must not authenticate example users.');
    [$status] = httpRequest($base . '/exemple/controller/index.php?route=/admin');
    check($status === 403, 'Admin example must deny unauthenticated access.');
    [$status] = httpRequest($base . '/exemple/controller/index.php?route=/name/params/42/page/2');
    check($status === 200, 'Named controller example must remain functional.');
} finally {
    proc_terminate($process);
    proc_close($process);
    $serverLog = file_get_contents($log);
    unlink($log);
}
check(!preg_match('/PHP (Warning|Deprecated|Fatal error|Parse error)/', $serverLog), 'HTTP server must not log PHP errors or deprecations: ' . $serverLog);
echo "Passed {$passed} total checks, including HTTP integration, on PHP " . PHP_VERSION . PHP_EOL;
