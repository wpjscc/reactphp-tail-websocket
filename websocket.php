<?php

require __DIR__ . '/vendor/autoload.php';

if ($argc < 4) {
    fwrite(STDERR, "Usage: php websocket.php <group> <listen> <cliGroup>\n");
    fwrite(STDERR, "Example: php websocket.php group1 0.0.0.0:8090 cliCollectors\n");
    fwrite(STDERR, "  group    WebSocket 组播组（tail 上报日志发往该组）\n");
    fwrite(STDERR, "  cliGroup tail-cli 通过 joinGroupBy_Id 先入组后再开始 tail 的目标组\n");
    exit(1);
}
$group = $argv[1];
$listen = $argv[2];
$cliGroup = $argv[3];

use React\EventLoop\Loop;
use ReactphpX\WebsocketGroup\WebsocketGroupComponent;
use ReactphpX\WebsocketGroup\WebsocketGroupMiddleware;
use ReactphpX\ConnectionGroup\ConnectionGroup;
use ReactphpX\ConnectionGroup\SingleConnectionGroup;
use React\Http\Message\Response;
use ReactphpX\WebsocketMiddleware\WebsocketMiddleware;

$connectionGroup = SingleConnectionGroup::instance();
// $connectionGroup = new ConnectionGroup;

$connectionGroup->on('open', function ($conn, $request) use ($connectionGroup) {
    $connectionGroup->sendMessageTo_id($conn->_id, 'open:' . $conn->_id);
});

$connectionGroup->on('message', function ($from, $msg) use ($connectionGroup, $group) {
    if ($msg === 'ping') {
        $connectionGroup->sendMessageTo_id($from->_id, 'open:' . $from->_id);

        return;
    }
    if (str_starts_with($msg, 'api_files_response:')) {
        $rest = substr($msg, strlen('api_files_response:'));
        $colon = strpos($rest, ':');
        if ($colon === false) {
            return;
        }
        $targetId = substr($rest, 0, $colon);
        $jsonBody = substr($rest, $colon + 1);
        $connectionGroup->sendMessageTo_Id($targetId, 'api_files_response:' . $jsonBody);

        return;
    }
    if (str_starts_with($msg, 'api_file_get_contents_response:')) {
        $rest = substr($msg, strlen('api_file_get_contents_response:'));
        $colon = strpos($rest, ':');
        if ($colon === false) {
            return;
        }
        $targetId = substr($rest, 0, $colon);
        $jsonBody = substr($rest, $colon + 1);
        $connectionGroup->sendMessageTo_Id($targetId, 'api_file_get_contents_response:' . $jsonBody);

        return;
    }
    $connectionGroup->sendToGroup($group, base64_decode($msg), [], [$from->_id]);
});

$connectionGroup->on('close', function ($conn, $reason) {
    var_dump('close', $conn->_id, $reason);
});


$websocketGroupMiddleware = new WebsocketGroupMiddleware($connectionGroup);
$token = getenv('TOKEN');
if ($token) {
    $token = explode(',', $token);
    $websocketGroupMiddleware->setTokens($token);
}



function getMemoryUsage(): array {
        return [
            'date' => date('Y-m-d H:i:s'),
            'current_usage_mb' => round(memory_get_usage() / 1024 / 1024, 4),
            'current_usage_real_mb' => round(memory_get_usage(true) / 1024 / 1024, 4),
            'peak_usage_mb' => round(memory_get_peak_usage() / 1024 / 1024, 4),
            'peak_usage_real_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 4),
        ];
    }


    $currentMemoryUsage = getMemoryUsage();
\React\EventLoop\Loop::addPeriodicTimer(2, function () use ($currentMemoryUsage) {
    // Log::channel('stdout')->info('',[
    //     'start_memory_usage' => $currentMemoryUsage,
    //     'current_memory_usage' => getMemoryUsage(),
    // ]); 
    // print_r($currentMemoryUsage);
    // print_r(getMemoryUsage());
    // echo PHP_EOL;
    gc_collect_cycles();
    // print_r(getMemoryUsage());

});



$http = new React\Http\HttpServer(
    function (\Psr\Http\Message\ServerRequestInterface $request, callable $next) use ($connectionGroup, $cliGroup) {
        $path = $request->getUri()->getPath();
        if ($request->getMethod() === 'GET' && trim($path, '/') === 'api/files') {
            $query = $request->getQueryParams();
            $connId = isset($query['_id']) ? (string) $query['_id'] : '';
            if ($connId === '') {
                return new Response(
                    400,
                    ['Content-Type' => 'application/json; charset=utf-8'],
                    json_encode(['code' => 1, 'msg' => 'missing _id query parameter', 'files' => []], JSON_UNESCAPED_UNICODE)
                );
            }
            if ($connectionGroup->getGroup_IdCount($cliGroup) > 0) {
                $connectionGroup->sendToGroup($cliGroup, 'api_files:' . $connId . ':{}', [], []);
            }

            return new Response(
                200,
                ['Content-Type' => 'application/json; charset=utf-8'],
                json_encode(['code' => 0, 'msg' => 'ok', 'files' => []], JSON_UNESCAPED_UNICODE)
            );
        }
        if ($request->getMethod() === 'GET' && trim($path, '/') === 'api/file_get_contents') {
            $query = $request->getQueryParams();
            $connId = isset($query['_id']) ? (string) $query['_id'] : '';
            $filePath = isset($query['path']) ? (string) $query['path'] : '';
            if ($connId === '') {
                return new Response(
                    400,
                    ['Content-Type' => 'application/json; charset=utf-8'],
                    json_encode(['code' => 1, 'msg' => 'missing _id query parameter'], JSON_UNESCAPED_UNICODE)
                );
            }
            if ($filePath === '') {
                return new Response(
                    400,
                    ['Content-Type' => 'application/json; charset=utf-8'],
                    json_encode(['code' => 1, 'msg' => 'missing path query parameter'], JSON_UNESCAPED_UNICODE)
                );
            }
            $payload = json_encode(['path' => $filePath], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($connectionGroup->getGroup_IdCount($cliGroup) > 0) {
                $connectionGroup->sendToGroup($cliGroup, 'api_file_get_contents:' . $connId . ':' . $payload, [], []);
            }

            return new Response(
                200,
                ['Content-Type' => 'application/json; charset=utf-8'],
                json_encode(['code' => 0, 'msg' => 'ok'], JSON_UNESCAPED_UNICODE)
            );
        }
        if (trim($path, '/') === 'index') {
            $file = __DIR__ . '/examples/index.html';
            if (is_readable($file)) {
                return Response::html(file_get_contents($file));
            }
            return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], "examples/index.html not found\n");
        }
        return $next($request);
    },
    $websocketGroupMiddleware,
    new WebsocketMiddleware(new WebsocketGroupComponent($connectionGroup))
);
$socket = new React\Socket\SocketServer($listen);
echo 'Server running at ' . $listen . ' (broadcast group: ' . $group . ', cliGroup: ' . $cliGroup . ')' . PHP_EOL;
$http->listen($socket);