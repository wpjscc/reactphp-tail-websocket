<?php

/**
 * 命令行用法类似 GNU tail，便于本地调试：
 *
 *   php tail-cli.php --ws-url ws://127.0.0.1:8090/ -n 20 /path/to/app.log
 *   php tail-cli.php --ws-url ws://10.10.10.2:8099/ --name "*.log" /var/log
 *   php tail-cli.php --ws-url ws://127.0.0.1:8090/ -n 0 -s 2 file1.log dir/
 *
 * -n 0 表示不先输出末尾若干行，只跟随后续追加内容。
 * --ws-url 为必选；先连上 WebSocket 再开始 tail，连接成功后每 30 秒发送文本 ping；断线后自动重连；
 * 文件头横幅与追加内容经 base64 编码后发往服务端（与 websocket.php 解码逻辑一致）。
 */

require __DIR__ . '/../vendor/autoload.php';

use React\EventLoop\Loop;
use ReactphpX\Tail\Tail;

$lines = 5;
$tick = 5.0;
$names = [];
$paths = [];
$wsUrl = '';

$argv = $GLOBALS['argv'];
array_shift($argv);

for ($i = 0, $c = count($argv); $i < $c; $i++) {
    $a = $argv[$i];
    if ($a === '-h' || $a === '--help') {
        print_usage();
        exit(0);
    }
    if ($a === '--ws-url' || $a === '--websocket-url') {
        $wsUrl = (string) ($argv[++$i] ?? '');
        continue;
    }
    if (strncmp($a, '--ws-url=', 9) === 0) {
        $wsUrl = substr($a, 9);
        continue;
    }
    if (strncmp($a, '--websocket-url=', 16) === 0) {
        $wsUrl = substr($a, 16);
        continue;
    }
    if ($a === '-n' || $a === '--lines') {
        $lines = (int) ($argv[++$i] ?? 5);
        continue;
    }
    if (strncmp($a, '-n', 2) === 0 && strlen($a) > 2) {
        $lines = (int) substr($a, 2);
        continue;
    }
    if (strncmp($a, '--lines=', 8) === 0) {
        $lines = (int) substr($a, 8);
        continue;
    }
    if ($a === '-s' || $a === '--sleep' || $a === '--sleep-interval') {
        $tick = (float) ($argv[++$i] ?? 1.0);
        continue;
    }
    if (strncmp($a, '-s', 2) === 0 && strlen($a) > 2 && is_numeric(substr($a, 2))) {
        $tick = (float) substr($a, 2);
        continue;
    }
    if (strncmp($a, '--sleep=', 8) === 0) {
        $tick = (float) substr($a, 8);
        continue;
    }
    if ($a === '--name') {
        $n = $argv[++$i] ?? '';
        if ($n !== '') {
            $names[] = $n;
        }
        continue;
    }
    if (strncmp($a, '--name=', 7) === 0) {
        $n = substr($a, 7);
        if ($n !== '') {
            $names[] = $n;
        }
        continue;
    }
    if ($a[0] === '-') {
        fwrite(STDERR, "tail-cli: unknown option: $a\n");
        print_usage(STDERR);
        exit(1);
    }
    $paths[] = $a;
}

if ($paths === []) {
    print_usage(STDERR);
    exit(1);
}

if ($wsUrl === '') {
    fwrite(STDERR, "tail-cli: --ws-url is required (e.g. ws://127.0.0.1:8090/)\n");
    print_usage(STDERR);
    exit(1);
}

foreach ($paths as $p) {
    if (!is_dir($p) && !is_file($p)) {
        fwrite(STDERR, "tail-cli: cannot open '{$p}' for reading: No such file or directory\n");
        exit(1);
    }
}

$tail = new Tail();
$tail->setLastLine($lines);
$tail->setTick($tick);

$ws = [
    'send' => static function (string $payload): void {
    },
    'pingTimer' => null,
];

$tailStarted = false;

$connectWs = function () use (&$connectWs, $wsUrl, &$ws, $tail, &$tailStarted, $paths, $names) {
    \Ratchet\Client\connect($wsUrl)->then(
        function (\Ratchet\Client\WebSocket $conn) use (&$connectWs, &$ws, $tail, &$tailStarted, $paths, $names) {
            if ($ws['pingTimer'] !== null) {
                Loop::cancelTimer($ws['pingTimer']);
            }
            $ws['send'] = static function (string $payload) use ($conn): void {
                $conn->send(base64_encode($payload));
            };
            $ws['pingTimer'] = Loop::addPeriodicTimer(30.0, static function () use ($conn) {
                $conn->send('ping');
            });
            if (!$tailStarted) {
                foreach ($paths as $p) {
                    if (is_dir($p)) {
                        $tail->addPath($p, $names);
                    } else {
                        $tail->addFile($p);
                    }
                }
                $tailStarted = true;
                $tail->start();
            }
            $conn->on('close', function ($code, $reason) use (&$connectWs, &$ws) {
                $ws['send'] = static function (string $payload): void {
                };
                if ($ws['pingTimer'] !== null) {
                    Loop::cancelTimer($ws['pingTimer']);
                    $ws['pingTimer'] = null;
                }
                Loop::addTimer(1.0, $connectWs);
            });
        },
        function (\Throwable $e) use (&$connectWs) {
            fwrite(STDERR, 'WebSocket connect failed: ' . $e->getMessage() . PHP_EOL);
            Loop::addTimer(1.0, $connectWs);
        }
    );
};
$filePath = '';
$tail->on('start', function ($file) use (&$filePath, &$ws) {
    if ($filePath !== $file) {
        $filePath = $file;
        $banner = PHP_EOL . '==> ' . $filePath . ' <==' . PHP_EOL;
        echo $banner;
        ($ws['send'])($banner);
    }
});

$tail->on('data', function ($data) use (&$ws) {
    echo $data;
    ($ws['send'])($data);
});

$tail->on('end', function ($file) {
});

$connectWs();

Loop::run();

function print_usage($out = null): void
{
    if ($out === null) {
        $out = STDOUT;
    }
    $a0 = $GLOBALS['argv'][0] ?? '';
    if ($a0 === '' || ($a0[0] ?? '') === '-') {
        $name = 'tail-cli.php';
    } else {
        $name = basename($a0);
    }
    $invoke = 'php ' . $name;
    fwrite($out, <<<TXT
Usage: {$invoke} [OPTION]... [FILE]...

Follow one or more files or directories (similar to "tail -f").

      --ws-url URL    WebSocket URL (required), e.g. ws://127.0.0.1:8090/
  -n, --lines N       Print the last N lines before following (default: 5).
                      Use 0 to skip initial tail output.
  -s, --sleep SEC     Rescan period for directory watches in seconds (default: 1).
      --name GLOB     For directories, only include files matching GLOB (repeatable).
  -h, --help          Show this help.

Examples:
  {$invoke} --ws-url ws://127.0.0.1:8090/ -n 20 /var/log/app.log
  {$invoke} --ws-url ws://127.0.0.1:8090/ --name "*.log" /var/log
  {$invoke} --ws-url ws://127.0.0.1:8090/ -n 0 a.log b.log

TXT);
}
