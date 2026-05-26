<?php
// Ultra-fast HLS Proxy for BunnyCDN (m3u8 + ts + key)

$REFERER = 'https://iframe.mediadelivery.net';

// ── Validate URL ─────────────────────────────────────────────
$url = $_GET['url'] ?? '';
if (!$url) {
    http_response_code(400);
    exit('Missing url');
}

$parsed = parse_url($url);
$host   = $parsed['host'] ?? '';

// Allow all Bunny stream CDN hosts (vz-*.b-cdn.net)
if (!$parsed || !preg_match('/^vz-[a-z0-9\-]+\.b-cdn\.net$/i', $host)) {
    http_response_code(403);
    exit('Forbidden host');
}

$path   = $parsed['path'] ?? '';
$ext    = strtolower(pathinfo($path, PATHINFO_EXTENSION));

$isM3U8 = ($ext === 'm3u8');
$isKey  = ($ext === 'key');

// ── Base cURL ────────────────────────────────────────────────
function curlBase($url, $headers) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 60,
    ]);
    return $ch;
}

// Common headers
$headers = [
    'Referer: ' . $REFERER,
    'Origin: ' . $REFERER,
    'User-Agent: Mozilla/5.0',
];

// Forward Range header (important for video seeking)
if (isset($_SERVER['HTTP_RANGE'])) {
    $headers[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
}

// ═══════════════════════════════════════════════════════
// FAST PATH — TS segments (direct streaming, no buffer)
// ═══════════════════════════════════════════════════════
if (!$isM3U8 && !$isKey) {

    if (ob_get_level()) ob_end_clean();

    $sentHeaders = false;
    $statusCode  = 200;
    $contentType = 'video/mp2t';

    $ch = curlBase($url, $headers);

    curl_setopt_array($ch, [

        CURLOPT_RETURNTRANSFER => false,

        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$statusCode, &$contentType) {
            if (preg_match('#HTTP/\d+\.\d+\s+(\d+)#', $line, $m)) {
                $statusCode = (int)$m[1];
            }
            if (stripos($line, 'content-type:') === 0) {
                $contentType = trim(substr($line, 13));
            }
            if (stripos($line, 'content-length:') === 0) {
                header($line);
            }
            if (stripos($line, 'content-range:') === 0) {
                header($line);
            }
            return strlen($line);
        },

        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$sentHeaders, &$statusCode, &$contentType) {
            if (!$sentHeaders) {
                http_response_code($statusCode);
                header('Content-Type: ' . $contentType);
                header('Access-Control-Allow-Origin: *');
                header('Cache-Control: no-store');
                header('Accept-Ranges: bytes');
                $sentHeaders = true;
            }

            echo $chunk;
            flush();
            return strlen($chunk);
        },
    ]);

    curl_exec($ch);

    if (curl_error($ch) && !$sentHeaders) {
        http_response_code(502);
        echo 'Stream Error';
    }

    curl_close($ch);
    exit;
}

// ═══════════════════════════════════════════════════════
// BUFFER PATH — m3u8 + key
// ═══════════════════════════════════════════════════════
$ch = curlBase($url, $headers);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$body   = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ctype  = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$error  = curl_error($ch);

curl_close($ch);

if ($error) {
    http_response_code(502);
    exit('cURL error');
}

http_response_code($status);
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');
header('Content-Type: ' . ($ctype ?: 'application/octet-stream'));

// ── KEY (no rewrite needed) ───────────────────────────
if ($isKey) {
    echo $body;
    exit;
}

// ── Rewrite M3U8 ──────────────────────────────────────
$base = rtrim(dirname($url), '/');
$lines = explode("\n", $body);

foreach ($lines as &$line) {
    $line = trim($line);
    if ($line === '') continue;

    // Handle key URI
    if (strpos($line, '#EXT-X-KEY') === 0) {
        $line = preg_replace_callback('/URI="([^"]+)"/', function ($m) use ($base) {
            $u = (strpos($m[1], 'http') === 0)
                ? $m[1]
                : $base . '/' . ltrim($m[1], '/');

            return 'URI="proxy.php?url=' . urlencode($u) . '"';
        }, $line);
        continue;
    }

    if ($line[0] === '#') continue;

    if (strpos($line, 'http') !== 0) {
        $line = $base . '/' . ltrim($line, '/');
    }

    $line = 'proxy.php?url=' . urlencode($line);
}

echo implode("\n", $lines);