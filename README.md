# YtGateway - get video stream from YouTube!

For first install ffmpeg, ytdlp

Install:
```bash
composer require wert1209yt/ytgateway
```
Using:
```php
require_once __DIR__ . '/vendor/autoload.php';

use Wert1209yt\YtGateway

$list = YtGateway::qualities('dQw4w9WgXcQ');
foreach ($list as $itag => $q) {
    echo "[{$itag}] {$q['label']} ({$q['height']}p, ~"
        . round($q['size'] / 1_048_576) . " MB)\n";
}
// [18] 360p (360p, ~12 MB)
// [22] 720p (720p60, ~85 MB)
// [37] 1080p (1080p60, ~240 MB)

YtGateway::stream('dQw4w9WgXcQ', 22);
```
Developed for [2017BackUs](https://github.com/Wert1209yt/2017BackUs)