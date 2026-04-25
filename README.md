```
docker build -t registry.cn-shanghai.aliyuncs.com/wpjscc/reacphp-x-websocket-log . -f Dockerfile
docker push registry.cn-shanghai.aliyuncs.com/wpjscc/reacphp-x-websocket-log
```

```
php websocket.php group1 0.0.0.0:8099
php examples/tail-cli.php --ws-url ws://10.10.10.2:8099/ --name "*.log" /var/log

http://10.10.10.2:8099/index?id=1&groupId=group1&token=xxx
```

# reactphp-tail-websocket

基于 [ReactPHP](https://reactphp.org/) 的 WebSocket 组播服务：把 `tail -f` 风格的日志流通过 WebSocket 推到同一组内的所有连接，并附带浏览器调试页与 HTTP 绑定接口。

## 依赖

- PHP 8.x、Composer
- 目录监听 tail 功能依赖 **inotify**（Linux；Docker 镜像已包含相关扩展）

## 安装

```bash
composer install
```

## 启动 WebSocket / HTTP 服务

```bash
php websocket.php <groupId> <listen>
```

- **groupId**：组播组名（例如 `group1`），与客户端 `joinGroupById` / `sendToGroup` 使用的一致。
- **listen**：监听地址（例如 `0.0.0.0:8090`）。

示例：

```bash
php websocket.php group1 0.0.0.0:8090
```

### 环境变量 `TOKEN`（可选）

若设置 `TOKEN`，可为逗号分隔的多个合法 token。此时对 HTTP 控制接口的 POST 需在 JSON 中带上 `token` 字段，且值在列表中，否则返回 `token error`。

```bash
export TOKEN=mysecret,other
php websocket.php group1 0.0.0.0:8090
```

## HTTP 路由

| 路径 | 说明 |
|------|------|
| **`/index`** | 返回 `examples/index.html`：浏览器里连 WebSocket、绑定用户 id / 组、查看组播日志。 |
| **WebSocket** | 对支持升级的请求走 WebSocket（默认路径一般为 `/`）。 |
| **`POST /`** | `WebsocketGroupMiddleware`：JSON 体中提供 `event` 及对应参数，可调用 `ConnectionGroup` 上的方法（如 `bindId`、`joinGroupById`）。 |

### WebSocket 消息约定（服务端）

- 连接建立后向该连接发送：`open:<_id>`（`<_id>` 为连接 id）。
- 文本 **`ping`**：服务端回复 `open:<_id>`。
- 其它文本：按 **Base64** 解码后的二进制/文本内容向 **当前 `groupId` 组** 组播，**排除**发送者自身。

浏览器 `tail-cli` 发送前会对内容做 Base64，与上述逻辑一致。

## 浏览器页面 `examples/index.html`

通过 `http://<host>:<port>/index` 访问（或由本地直接打开文件时，需把 WebSocket URL 指到实际服务地址）。

- 连接 WebSocket 后，若收到 `open:` 且已填写 **token、id、groupId**，会自动 `POST` 执行 `bindId` + `joinGroupById`。
- 否则保持连接，补全后点击 **绑定**（不必重连）。
- 非 `open:` 开头的消息在页面下方组播日志区域显示；支持贴底自动滚动、上滑查看时暂停自动滚到底。

查询参数示例：`?token=...&id=1&groupId=group1&wsPath=/`

## 命令行 tail 客户端 `examples/tail-cli.php`

在跟踪文件的同时把日志推到 WebSocket：

```bash
php examples/tail-cli.php --ws-url ws://127.0.0.1:8090/ -n 20 /path/to/app.log
php examples/tail-cli.php --ws-url ws://10.10.10.2:8099/ --name "*.log" /var/log
```

- **`--ws-url`**：必选，WebSocket 地址（客户端请使用可路由 IP/主机名，不要用 `0.0.0.0`）。
- 每 **30 秒**发送文本 `ping`；断线后自动重连。
- 文件切换横幅与增量内容会 **Base64 编码** 后发送。

其它选项与 GNU tail 类似：`-n` / `--lines`、`-s` 目录扫描间隔、`--name` 通配等，见脚本内 `--help`。

## Docker

```bash
docker build -t reactphp-tail-websocket .
```

镜像内执行 `composer install` 后，工作目录为 `/var/www`。运行容器时需自行指定启动命令，例如：

```bash
docker run --rm -p 8090:8090 -e TOKEN=secret reactphp-tail-websocket \
  php websocket.php group1 0.0.0.0:8090
```

（按实际端口、组名调整。）

## 仓库结构（主要文件）

| 路径 | 作用 |
|------|------|
| `websocket.php` | HTTP + WebSocket 服务入口 |
| `examples/index.html` | 组播日志查看与绑定 UI |
| `examples/tail-cli.php` | 带 WebSocket 上行的 tail 客户端 |
| `composer.json` | `reactphp-x/tail`、`reactphp-x/websocket-group`、`ratchet/pawl` 等 |

## 许可证

以项目内 `composer.json` 及所用依赖的开源许可证为准。
