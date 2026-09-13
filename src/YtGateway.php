<?php
declare(strict_types=1);

namespace Wert1209yt;

final class YtGateway
{
    // ─── Settings ─────────────────────────
    public static string $ffmpegBin   = '/usr/bin/ffmpeg';
    public static string $ytdlpBin    = '/usr/local/bin/yt-dlp';
    public static string $cacheDir    = '';
    public static int    $cacheMaxMb  = 4096;
    public static int    $ytdlpTimeout = 30;

    // ─── InnerTube ───────────────────
    private const IT_KEY     = 'AIzaSyAO_FJ2SlqU8Q4STEHLGCilw_Y9_11qcW8';
    private const IT_BASE    = 'https://www.youtube.com/youtubei/v1/';
    private const IT_ORIGIN  = 'https://www.youtube.com';
    private const UA_DESKTOP =
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36';

    private const CLIENTS = [
        ['name' => 'ANDROID', 'version' => '20.10.38',
         'extra' => ['androidSdkVersion' => 30, 'osName' => 'Android', 'osVersion' => '11'],
         'ua'    => 'com.google.android.youtube/20.10.38 (Linux; U; Android 11) gzip',
         'hdr'   => ['X-YouTube-Client-Name: 3', 'X-YouTube-Client-Version: 20.10.38']],
        ['name' => 'IOS', 'version' => '20.10.4',
         'extra' => ['deviceMake' => 'Apple', 'deviceModel' => 'iPhone16,2',
                     'osName' => 'iPhone', 'osVersion' => '18.3.2.22D82'],
         'ua'    => 'com.google.ios.youtube/20.10.4 (iPhone16,2; U; CPU iOS 18_3_2 like Mac OS X;)',
         'hdr'   => ['X-YouTube-Client-Name: 5', 'X-YouTube-Client-Version: 20.10.4']],
    ];

    /** height => [classicItag, label-player, mime] */
    private const CLASSIC = [
        240  => [5,  'small',  'video/mp4; codecs="avc1.42001E, mp4a.40.2"'],
        360  => [18, 'medium', 'video/mp4; codecs="avc1.42001E, mp4a.40.2"'],
        480  => [35, 'large',  'video/mp4; codecs="avc1.4D401E, mp4a.40.2"'],
        720  => [22, 'hd720',  'video/mp4; codecs="avc1.64001F, mp4a.40.2"'],
        1080 => [37, 'hd1080', 'video/mp4; codecs="avc1.640028, mp4a.40.2"'],
    ];

    private const CHUNK       = 1_048_576;   // googlevideo: no more 1 mb
    private const RETRIES     = 3;
    private const RETRY_SLEEP = 300_000;
    private const WRITE_CHUNK = 65_536;

    private const VID_RE   = '/^[A-Za-z0-9_-]{11}$/';
    private const RANGE_RE = '/bytes=(\d*)-(\d*)/';

    // ═══════════════════════════════════════════════════════════════════════
    //  API
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * return available muxed qualitys
     *
     * @return array<int, array{
     *   itag:int, label:string, quality:string, height:int, width:int, fps:int,
     *   mime:string, native:bool, size:int, videoUrl:?string, audioUrl:?string
     * }>   Ключи — классические itag (5,18,22,35,37)
     */
    public static function qualities(string $videoId): array
    {
        if (!preg_match(self::VID_RE, $videoId)) return [];

        $streams = self::extractStreams($videoId);
        if ($streams === null) return [];

        return self::buildQualityMap($streams);
    }

    public static function stream(string $videoId, int $itag = 0): never
    {
        if (!preg_match(self::VID_RE, $videoId)) self::fail(400, 'bad video_id');

        $streams = self::extractStreams($videoId);
        $map     = $streams ? self::buildQualityMap($streams) : [];

        if (!$map) self::fail(404, 'no streams');
        if (!$itag || !isset($map[$itag])) {
            $itag = array_key_first($map);
        }
        $q = $map[$itag];

        self::drain();

        if ($q['native']) {
            self::proxyNative($streams, $videoId, $itag);
        }

        self::serveMuxed($streams, $videoId, $q);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Extract
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * @return ?array{
     *   ua:string, title:string,
     *   muxed: array<int, array{itag:int,url:string,mime:string,width:int,height:int,size:int}>,
     *   videoOnly: array<int, array{itag:int,url:string,height:int,width:int,fps:int,size:int,bitrate:int,label:string}>,
     *   audioOnly: ?array{itag:int,url:string,bitrate:int,size:int}
     * }
     */
    private static function extractStreams(string $videoId): ?array
    {
        return self::viaYtDlp($videoId) ?? self::viaInnerTube($videoId);
    }

    // ─── yt-dlp (предпочтительно: полный доступ к adaptive) ────────────────
    private static function viaYtDlp(string $videoId): ?array
    {
        if (self::$ytdlpBin === '' || !is_executable(self::$ytdlpBin)) return null;

        $args = [
            self::$ytdlpBin, '-J', '--no-warnings', '--no-playlist', '--skip-download',
            '--socket-timeout', '15',
            'https://www.youtube.com/watch?v=' . $videoId,
        ];
        $proc = @proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) return null;

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out = '';
        $deadline = time() + self::$ytdlpTimeout;
        while (true) {
            $out .= (string)stream_get_contents($pipes[1]);
            $st = proc_get_status($proc);
            if (!$st['running']) break;
            if (time() > $deadline) { proc_terminate($proc); break; }
            usleep(50_000);
        }
        $out .= (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        $j = json_decode($out, true);
        if (!is_array($j) || empty($j['formats'])) return null;

        $muxed = [];
        $videoOnly = [];
        $audioOnly = null;
        $ua = 'Mozilla/5.0';

        foreach ($j['formats'] as $f) {
            if (empty($f['url'])) continue;
            $vc   = $f['vcodec'] ?? 'none';
            $ac   = $f['acodec'] ?? 'none';
            $tag  = (int)($f['format_id'] ?? 0);
            $len  = (int)($f['filesize'] ?? $f['filesize_approx'] ?? 0);
            if (!empty($f['http_headers']['User-Agent'])) $ua = $f['http_headers']['User-Agent'];

            if ($vc !== 'none' && $ac !== 'none') {
                // muxed
                $muxed[$tag] = [
                    'itag' => $tag, 'url' => $f['url'],
                    'mime' => $f['mime_type'] ?? 'video/mp4',
                    'width' => (int)($f['width'] ?? 0), 'height' => (int)($f['height'] ?? 0),
                    'size' => $len,
                ];
            } elseif ($ac === 'none' && str_starts_with($vc, 'avc1')) {
                $h = (int)($f['height'] ?? 0);
                if ($h <= 0) continue;
                $fps = (int)($f['fps'] ?? 30);
                $cand = [
                    'itag' => $tag, 'url' => $f['url'], 'height' => $h,
                    'width' => (int)($f['width'] ?? 0), 'fps' => $fps,
                    'size' => $len, 'bitrate' => (int)(($f['tbr'] ?? 0) * 1000),
                    'label' => $h . 'p' . ($fps >= 50 ? $fps : ''),
                ];
                $cur = $videoOnly[$h] ?? null;
                if ($cur === null
                    || $cand['fps'] > $cur['fps']
                    || ($cand['fps'] === $cur['fps'] && $cand['bitrate'] > $cur['bitrate'])
                ) $videoOnly[$h] = $cand;
            } elseif ($vc === 'none' && str_starts_with($ac, 'mp4a')) {
                if ((int)($f['audio_channels'] ?? 2) > 2) continue;
                $br = (int)(($f['tbr'] ?? 0) * 1000);
                if ($audioOnly === null || $br > $audioOnly['bitrate']) {
                    $audioOnly = ['itag' => $tag, 'url' => $f['url'],
                                  'bitrate' => $br, 'size' => $len];
                }
            }
        }

        if (!$muxed && !$videoOnly) return null;
        krsort($videoOnly);

        return [
            'ua' => $ua, 'title' => (string)($j['title'] ?? ''),
            'muxed' => $muxed, 'videoOnly' => $videoOnly, 'audioOnly' => $audioOnly,
        ];
    }

    // ─── InnerTube fallback (ANDROID / IOS) ────────────────────────────────
    private static function viaInnerTube(string $videoId): ?array
    {
        foreach (self::CLIENTS as $cl) {
            $client = array_merge(
                ['clientName' => $cl['name'], 'clientVersion' => $cl['version'], 'hl' => 'en', 'gl' => 'US'],
                $cl['extra']
            );
            $payload = [
                'context' => ['client' => $client],
                'videoId' => $videoId, 'racyCheckOk' => true, 'contentCheckOk' => true,
            ];
            $headers = array_merge(
                ['Content-Type: application/json', 'User-Agent: ' . $cl['ua']],
                $cl['hdr']
            );
            $d = self::itPost('player', $payload, $headers);
            if ($d === null) continue;
            if (($d['playabilityStatus']['status'] ?? '') !== 'OK') continue;

            $vd = $d['videoDetails'] ?? [];
            $ua = $cl['ua'];

            $muxed = [];
            foreach ($d['streamingData']['formats'] ?? [] as $f) {
                if (empty($f['url']) || empty($f['itag'])) continue;
                $muxed[(int)$f['itag']] = [
                    'itag' => (int)$f['itag'], 'url' => $f['url'],
                    'mime' => $f['mimeType'] ?? 'video/mp4',
                    'width' => (int)($f['width'] ?? 0), 'height' => (int)($f['height'] ?? 0),
                    'size' => (int)($f['contentLength'] ?? 0),
                ];
            }

            $videoOnly = [];
            $audioOnly = null;
            foreach ($d['streamingData']['adaptiveFormats'] ?? [] as $f) {
                if (empty($f['url']) || empty($f['mimeType'])) continue;
                $mime = $f['mimeType'];

                if (str_starts_with($mime, 'video/mp4') && str_contains($mime, 'avc1')) {
                    $h = (int)($f['height'] ?? 0);
                    if ($h <= 0) continue;
                    $fps = (int)($f['fps'] ?? 30);
                    $cand = [
                        'itag' => (int)$f['itag'], 'url' => $f['url'],
                        'height' => $h, 'width' => (int)($f['width'] ?? 0), 'fps' => $fps,
                        'size' => (int)($f['contentLength'] ?? 0),
                        'bitrate' => (int)($f['bitrate'] ?? 0),
                        'label' => $f['qualityLabel'] ?? ($h . 'p'),
                    ];
                    $cur = $videoOnly[$h] ?? null;
                    if ($cur === null
                        || $cand['fps'] > $cur['fps']
                        || ($cand['fps'] === $cur['fps'] && $cand['bitrate'] > $cur['bitrate'])
                    ) $videoOnly[$h] = $cand;
                } elseif (str_starts_with($mime, 'audio/mp4') && str_contains($mime, 'mp4a')) {
                    $br = (int)($f['bitrate'] ?? 0);
                    if ($audioOnly === null || $br > $audioOnly['bitrate']) {
                        $audioOnly = ['itag' => (int)$f['itag'], 'url' => $f['url'],
                                      'bitrate' => $br, 'size' => (int)($f['contentLength'] ?? 0)];
                    }
                }
            }
            krsort($videoOnly);

            if (!$muxed && (!$videoOnly || $audioOnly === null)) continue;

            return [
                'ua' => $ua, 'title' => (string)($vd['title'] ?? ''),
                'muxed' => $muxed, 'videoOnly' => $videoOnly, 'audioOnly' => $audioOnly,
            ];
        }
        return null;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Quality map
    // ═══════════════════════════════════════════════════════════════════════
    private static function buildQualityMap(array $s): array
    {
        $map = [];

        $native18 = $s['muxed'][18] ?? null;
        if ($native18 !== null) {
            $map[18] = [
                'itag' => 18, 'label' => ($native18['height'] ?: 360) . 'p',
                'quality' => 'medium',
                'height' => $native18['height'] ?: 360,
                'width'  => $native18['width']  ?: 640,
                'fps'    => 30,
                'mime'   => self::CLASSIC[360][2],
                'native' => true,
                'size'   => $native18['size'],
                'videoUrl' => null, 'audioUrl' => null,
            ];
        }

        $audio = $s['audioOnly'];
        if ($audio !== null) {
            $nativeHeight = $map[18]['height'] ?? 0;

            foreach ($s['videoOnly'] as $h => $v) {
                if (!isset(self::CLASSIC[$h])) continue;
                [$tag, $quality, $mime] = self::CLASSIC[$h];
                if (isset($map[$tag])) continue;
                if ($h === $nativeHeight) continue;

                $map[$tag] = [
                    'itag' => $tag, 'label' => $v['label'],
                    'quality' => $quality,
                    'height' => $h,
                    'width'  => $v['width'] ?: (int)round($h * 16 / 9),
                    'fps'    => $v['fps'],
                    'mime'   => $mime,
                    'native' => false,
                    'size'   => $v['size'] + $audio['size'],
                    'videoUrl' => $v['url'],
                    'audioUrl' => $audio['url'],
                ];
            }
        }

        krsort($map);
        return $map;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  1. Native muxed
    // ═══════════════════════════════════════════════════════════════════════
    private static function proxyNative(array $streams, string $videoId, int $itag): never
    {
        $fmt = $streams['muxed'][$itag] ?? null;
        if ($fmt === null) self::fail(404, 'no native format');

        $code = self::proxy($fmt['url'], $streams['ua'], $fmt['mime']);

        if ($code === 403) {
            $fresh = self::extractStreams($videoId);
            $fmt   = $fresh['muxed'][$itag] ?? null;
            if ($fmt !== null) {
                $code = self::proxy($fmt['url'], $fresh['ua'], $fmt['mime']);
            }
        }
        if ($code >= 400 && !headers_sent()) self::fail(502, 'upstream ' . $code);
        exit;
    }

    private static function proxy(string $url, string $ua, string $mime): int
    {
        $upstreamCode = 0;
        $headersSent  = false;
        $cleanMime    = preg_replace('/;.*$/', '', $mime) ?: 'video/mp4';
        $range        = $_SERVER['HTTP_RANGE'] ?? '';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 0,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_BUFFERSIZE     => 256 * 1024,
            CURLOPT_USERAGENT      => $ua,

            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headersSent, &$upstreamCode, $cleanMime) {
                $t = trim($line);
                if ($t === '') return strlen($line);

                if (preg_match('#^HTTP/[\d.]+\s+(\d+)#', $t, $m)) {
                    $code = (int)$m[1];
                    if ($code < 300 || $code >= 400) {
                        $upstreamCode = $code;
                    }
                    return strlen($line);
                }
                if ($upstreamCode < 200 || $upstreamCode >= 300) return strlen($line);

                if (!$headersSent && $upstreamCode > 0) {
                    http_response_code($upstreamCode);
                    header('Content-Type: ' . $cleanMime);
                    header('Accept-Ranges: bytes');
                    $headersSent = true;
                }
                foreach (['content-length', 'content-range'] as $h) {
                    if (stripos($t, $h . ':') === 0) header($t, true);
                }
                return strlen($line);
            },

            CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$upstreamCode) {
                if ($upstreamCode >= 400) return strlen($data);
                echo $data;
                flush();
                return connection_aborted() ? 0 : strlen($data);
            },
        ]);
        if ($range !== '' && preg_match('/bytes=([\d\-,]+)/', $range, $m)) {
            curl_setopt($ch, CURLOPT_RANGE, $m[1]);
        }
        curl_exec($ch);
        return $upstreamCode;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  2. Adaptive - glue
    // ═══════════════════════════════════════════════════════════════════════
    private static function serveMuxed(array $streams, string $videoId, array $q): never
    {
        if (self::$ffmpegBin === '' || !is_executable(self::$ffmpegBin)) {
            self::fail(501, 'ffmpeg not available');
        }
        if (!$q['videoUrl'] || !$q['audioUrl']) self::fail(404, 'no adaptive streams');

        self::ensureCacheDir();
        $cache = self::cachePath($videoId, $q['itag']);

        self::buildIfMissing($cache, $streams, $q);
        @touch($cache);

        self::streamFile($cache);
    }

    private static function buildIfMissing(string $cache, array $streams, array $q): void
    {
        if (is_file($cache)) return;

        $lock = @fopen($cache . '.lock', 'c');
        if ($lock === false) self::fail(500, 'cache not writable');
        if (!flock($lock, LOCK_EX)) { fclose($lock); self::fail(500, 'lock failed'); }

        try {
            if (is_file($cache)) return;

            $ua   = $streams['ua'];
            $vTmp = $cache . '.v';
            $aTmp = $cache . '.a';
            $out  = $cache . '.part';

            $vLen = (int)($streams['videoOnly'][$q['height']]['size'] ?? 0);
            $aLen = (int)($streams['audioOnly']['size'] ?? 0);

            $okV = self::download($q['videoUrl'], $ua, $vTmp, $vLen);
            $okA = $okV && self::download($q['audioUrl'], $ua, $aTmp, $aLen);

            if (!$okV || !$okA) {
                @unlink($vTmp); @unlink($aTmp);
                self::fail(502, 'download failed');
            }

            $err = self::ffmpegMux($vTmp, $aTmp, $out);
            @unlink($vTmp); @unlink($aTmp);

            if ($err !== null) {
                @unlink($out);
                self::fail(500, 'mux failed: ' . substr($err, -200));
            }
            @rename($out, $cache);
            self::prune();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($cache . '.lock');
        }
    }

    private static function download(string $url, string $ua, string $dest, int $total): bool
    {
        $fp = @fopen($dest, 'wb');
        if ($fp === false) return false;

        $pos = 0;
        while ($total === 0 || $pos < $total) {
            $end = $pos + self::CHUNK - 1;
            if ($total > 0 && $end > $total - 1) $end = $total - 1;

            $body = self::fetchRange($url, $ua, $pos, $end);
            if ($body === null) { fclose($fp); @unlink($dest); return false; }

            fwrite($fp, $body);
            $pos += strlen($body);
            if ($total === 0) break;
        }
        fclose($fp);
        return filesize($dest) > 0;
    }

    private static function fetchRange(string $url, string $ua, int $from, int $to): ?string
    {
        for ($i = 0; $i < self::RETRIES; $i++) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RANGE          => "{$from}-{$to}",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_USERAGENT      => $ua,
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if (($code === 206 || $code === 200) && is_string($body) && $body !== '') {
                return $body;
            }
            if ($i < self::RETRIES - 1) usleep(self::RETRY_SLEEP);
        }
        return null;
    }

    private static function ffmpegMux(string $v, string $a, string $out): ?string
    {
        $args = [
            self::$ffmpegBin, '-loglevel', 'error', '-y',
            '-i', $v, '-i', $a,
            '-map', '0:v:0', '-map', '1:a:0',
            '-c', 'copy', '-movflags', '+faststart',
            '-f', 'mp4', $out,
        ];
        $proc = proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) return 'proc_open failed';

        fclose($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $rc  = proc_close($proc);

        if ($rc !== 0 || !is_file($out) || filesize($out) === 0) {
            return $err !== '' ? $err : "ffmpeg rc={$rc}";
        }
        return null;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Cache management
    // ═══════════════════════════════════════════════════════════════════════
    private static function cacheDir(): string
    {
        return self::$cacheDir !== ''
            ? self::$cacheDir
            : sys_get_temp_dir() . '/yt_stream';
    }

    private static function cachePath(string $videoId, int $itag): string
    {
        return self::cacheDir() . "/{$videoId}_{$itag}.mp4";
    }

    private static function ensureCacheDir(): void
    {
        $d = self::cacheDir();
        if (!is_dir($d) && !@mkdir($d, 0775, true) && !is_dir($d)) {
            self::fail(500, 'cache dir not creatable');
        }
    }

    private static function prune(): void
    {
        $d = self::cacheDir();
        $files = glob($d . '/*.mp4') ?: [];
        if (!$files) return;

        $total = 0;
        $list  = [];
        foreach ($files as $f) {
            $sz = @filesize($f);
            if ($sz === false) continue;
            $list[] = ['f' => $f, 'sz' => $sz, 't' => @filemtime($f) ?: 0];
            $total += $sz;
        }
        $limit = self::$cacheMaxMb * 1_048_576;
        if ($total <= $limit) return;

        usort($list, static fn(array $a, array $b): int => $a['t'] <=> $b['t']);
        foreach ($list as $e) {
            if ($total <= $limit) break;
            if (@unlink($e['f'])) $total -= $e['sz'];
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Range-delivery
    // ═══════════════════════════════════════════════════════════════════════
    private static function streamFile(string $path): never
    {
        $size  = (int)filesize($path);
        $start = 0;
        $end   = $size - 1;
        $range = $_SERVER['HTTP_RANGE'] ?? '';

        header('Content-Type: video/mp4');
        header('Accept-Ranges: bytes');

        if ($range !== '' && preg_match(self::RANGE_RE, $range, $m)) {
            if ($m[1] !== '') $start = (int)$m[1];
            if ($m[2] !== '') $end   = (int)$m[2];

            if ($start > $end || $start >= $size) {
                http_response_code(416);
                header("Content-Range: bytes */{$size}");
                exit;
            }
            $end = min($end, $size - 1);
            http_response_code(206);
            header("Content-Range: bytes {$start}-{$end}/{$size}");
        }
        header('Content-Length: ' . ($end - $start + 1));

        $fp = fopen($path, 'rb');
        if ($fp === false) self::fail(500, 'cache read failed');
        fseek($fp, $start);

        $left = $end - $start + 1;
        while ($left > 0 && !feof($fp)) {
            $chunk = fread($fp, min(self::WRITE_CHUNK, $left));
            if ($chunk === false) break;
            echo $chunk;
            flush();
            $left -= strlen($chunk);
            if (connection_aborted()) break;
        }
        fclose($fp);
        exit;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  InnerTube POST
    // ═══════════════════════════════════════════════════════════════════════
    private static function itPost(string $endpoint, array $payload, array $headers): ?array
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => self::IT_BASE . $endpoint . '?key=' . self::IT_KEY . '&prettyPrint=false',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => array_merge($headers, [
                'Content-Length: ' . strlen($json),
                'Accept: application/json',
                'Origin: ' . self::IT_ORIGIN,
            ]),
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($code !== 200 || $res === false) return null;
        $d = json_decode($res, true);
        return is_array($d) ? $d : null;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Helpers
    // ═══════════════════════════════════════════════════════════════════════
    private static function drain(): void
    {
        while (ob_get_level() > 0) ob_end_clean();
    }

    private static function fail(int $code, string $msg): never
    {
        http_response_code($code);
        exit($msg);
    }
}