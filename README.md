# reactphp-tail-websocket

基于 [ReactPHP](https://reactphp.org/) 的 WebSocket 组播服务：把 `tail -f` 风格的日志流通过 WebSocket 推到同一组内的所有连接，并附带浏览器调试页与 HTTP 绑定接口。

## 快速开始（镜像 `wpjscc/reacphp-x-websocket-log`）

以下与本地执行 `php websocket.php group1 0.0.0.0:8099 cliCollectors` 等价，监听 **8099**，组播组 **group1**，tail-cli 入组名 **cliCollectors**：

```bash
docker run --rm -p 8099:8099 \
  -e TOKEN=xxx \
  wpjscc/reacphp-x-websocket-log \
  php websocket.php group1 0.0.0.0:8099 cliCollectors
```

**tail-cli** 可在宿主机直接跑，也可用**同一镜像**在容器里跑（需把待采集目录挂进容器）。宿主机示例：

```bash
php examples/tail-cli.php --ws-url ws://10.10.10.2:8099/ --cli-group cliCollectors --name "*.log" /var/log
```

**tail-cli（Docker，镜像 `wpjscc/reacphp-x-websocket-log`）**：服务端若与 tail 容器在同一台物理机、且 8099 映射在宿主机上，可用 `host.docker.internal`（Linux 需加 `--add-host=host.docker.internal:host-gateway`）：

```bash
docker run --rm \
  --add-host=host.docker.internal:host-gateway \
  -v /var/log:/var/log:ro \
  -e TOKEN=xxx \
  wpjscc/reacphp-x-websocket-log \
  php examples/tail-cli.php \
    --ws-url ws://host.docker.internal:8099/ \
    --cli-group cliCollectors \
    --name "*.log" \
    /var/log
```

服务端在**其它机器**（例如 `10.10.10.2`）时，`--ws-url` 与宿主机跑 PHP 时相同；未配置 `TOKEN` 可去掉 `-e TOKEN`。采集目录按实际修改 `-v` 与最后的路径参数。

浏览器打开：

```
http://10.10.10.2:8099/index?groupId=group1&token=xxx
```

（页面里 **groupId** 填 **组播组** `group1`；**cliGroup** 仅给 tail-cli 入组用。未配置 `TOKEN` 时可去掉 URL 中的 `token` 与上面的 `-e TOKEN`。）

---

本地源码直接跑（与上文 Docker 示例同一套参数）：

```bash
php websocket.php group1 0.0.0.0:8099 cliCollectors
```

## 依赖

- PHP 8.x、Composer
- 目录监听 tail 功能依赖 **inotify**（Linux；Docker 镜像已包含相关扩展）

## 安装

```bash
composer install
```

## 启动 WebSocket / HTTP 服务

```bash
php websocket.php <groupId> <listen> <cliGroup>
```

- **groupId**：日志 **组播** 组名（例如 `group1`），`sendToGroup` 与浏览器 `joinGroupBy_Id` 填此组即可收日志。
- **listen**：监听地址（例如 `0.0.0.0:8090`）。
- **cliGroup**：**tail-cli 专用**；tail 进程连上 WebSocket 后先对 HTTP `joinGroupBy_Id` 入此组，**成功后才启动 inotify tail**。

示例：

```bash
php websocket.php group1 0.0.0.0:8090 cliCollectors
```

### 环境变量 `TOKEN`（可选）

若设置 `TOKEN`，可为逗号分隔的多个合法 token。此时对 HTTP 控制接口的 POST 需在 JSON 中带上 `token` 字段，且值在列表中，否则返回 `token error`。

```bash
export TOKEN=mysecret,other
php websocket.php group1 0.0.0.0:8090 cliCollectors
```

## HTTP 路由

| 路径 | 说明 |
|------|------|
| **`/index`** | 返回 `examples/index.html`：浏览器里连 WebSocket、`joinGroupBy_Id` 入组、查看组播日志。 |
| **WebSocket** | 对支持升级的请求走 WebSocket（默认路径一般为 `/`）。 |
| **`POST /`** | `WebsocketGroupMiddleware`：JSON 体中提供 `event` 及对应参数，可调用 `ConnectionGroup` 上的方法（如 `joinGroupBy_Id`）。 |
| **`GET /api/files`** | 向 **cliGroup** 广播 `api_files:<reqId>:{}`；tail-cli 回 `api_files_response:<reqId>:<json>`，HTTP 返回该 JSON，并向 **组播组** 推送 `api_files_response:<json>`（供页面实时更新）。 |

### WebSocket 消息约定（服务端）

- 连接建立后向该连接发送：`open:<_id>`（`<_id>` 为连接 id）。
- 文本 **`ping`**：服务端回复 `open:<_id>`。
- 文本 **`api_files_response:<reqId>:<json>`**（来自 tail-cli）：匹配挂起的 `/api/files` 请求，并向组播组发送 **`api_files_response:<json>`**（无 reqId）。
- 其它文本：按 **Base64** 解码后的二进制/文本内容向 **当前 `groupId` 组** 组播，**排除**发送者自身。

浏览器与 `tail-cli` 上报日志时会对内容做 Base64；**`api_files` / `api_files_response` 为明文**，不走 Base64。

## 浏览器页面 `examples/index.html`

通过 `http://<host>:<port>/index` 访问（或由本地直接打开文件时，需把 WebSocket URL 指到实际服务地址）。

- 连接 WebSocket 后，若收到 `open:` 且已填写 **token、groupId**，会自动 `POST` 执行 `joinGroupBy_Id`。
- 否则保持连接，补全后点击 **绑定**（不必重连）。
- 左侧 **获取文件** 调用 **`GET /api/files`**；并监听 WebSocket **`api_files_response:`** 更新列表。
- 非 `open:` / 非 `api_files_response:` 的消息在组播日志区域显示；支持贴底自动滚动。

查询参数示例：`?token=...&groupId=group1&wsPath=/`

## 命令行 tail 客户端 `examples/tail-cli.php`

在跟踪文件的同时把日志推到 WebSocket：

```bash
php examples/tail-cli.php --ws-url ws://127.0.0.1:8090/ --cli-group cliCollectors -n 20 /path/to/app.log
php examples/tail-cli.php --ws-url ws://10.10.10.2:8099/ --cli-group cliCollectors --name "*.log" /var/log
```

使用预构建镜像 **`wpjscc/reacphp-x-websocket-log`** 跑 tail-cli（工作目录已为 `/var/www`，含 `examples/tail-cli.php`）：

```bash
# 服务端在本机 Docker、端口映射到宿主机 8099
docker run --rm \
  --add-host=host.docker.internal:host-gateway \
  -v /var/log:/var/log:ro \
  -e TOKEN=xxx \
  wpjscc/reacphp-x-websocket-log \
  php examples/tail-cli.php \
    --ws-url ws://host.docker.internal:8099/ \
    --cli-group cliCollectors \
    --name "*.log" \
    /var/log
```

```bash
# 服务端在其它主机（与本地 PHP 示例相同，仅多卷挂载）
docker run --rm \
  -v /var/log:/var/log:ro \
  -e TOKEN=xxx \
  wpjscc/reacphp-x-websocket-log \
  php examples/tail-cli.php \
    --ws-url ws://10.10.10.2:8099/ \
    --cli-group cliCollectors \
    --name "*.log" \
    /var/log
```

- **`--ws-url`**：必选，WebSocket 地址（不要用 `0.0.0.0` 作客户端目标）。
- **`--cli-group`**：必选，须与 `websocket.php` 的 **cliGroup** 一致；收到 `open:` 后向同源 HTTP `POST /` 执行 `joinGroupBy_Id`，**成功后才 `tail->start()`**。
- 若服务端配置了 **`TOKEN`**，tail-cli 会取 **`TOKEN` 环境变量中逗号分隔的第一段** 作为 POST 的 `token`。
- 每 **30 秒**发送文本 `ping`；断线后自动重连；日志 **Base64** 后发到 **组播组 groupId**。
- 收到 **`api_files:<reqId>:...`** 时以明文回复 **`api_files_response:<reqId>:{"files":[...]}`**（当前 tail 监视的路径列表）。

其它选项与 GNU tail 类似：`-n` / `--lines`、`-s` 目录扫描间隔、`--name` 通配等，见脚本内 `--help`。

## Docker

### 使用预构建镜像 `wpjscc/reacphp-x-websocket-log`

拉取并运行（与文首 **8099 / group1 / cliCollectors** 示例一致）：

```bash
docker pull wpjscc/reacphp-x-websocket-log

docker run --rm -p 8099:8099 \
  -e TOKEN=xxx \
  wpjscc/reacphp-x-websocket-log \
  php websocket.php group1 0.0.0.0:8099 cliCollectors
```

按需改端口映射（如 `-p 8090:8090`）、`TOKEN` 及 `websocket.php` 的三个参数。

### 同一镜像运行 tail-cli

镜像内已含 **inotify** 与 `examples/tail-cli.php`，把日志目录挂入容器即可（**`--cli-group` 须与上方 `websocket.php` 第三个参数一致**）：

```bash
docker run --rm \
  --add-host=host.docker.internal:host-gateway \
  -v /var/log:/var/log:ro \
  -e TOKEN=xxx \
  wpjscc/reacphp-x-websocket-log \
  php examples/tail-cli.php \
    --ws-url ws://host.docker.internal:8099/ \
    --cli-group cliCollectors \
    --name "*.log" \
    /var/log
```

WebSocket 服务不在本机时，将 `--ws-url` 改为可达地址（如 `ws://10.10.10.2:8099/`），并视情况去掉 `--add-host`。需要 **`--network host`** 时（Linux 下让容器直接使用宿主机网络）可自行改用 `ws://127.0.0.1:8099/` 并评估安全与端口冲突。

### 自行构建（阿里云仓库示例）

```bash
docker build -t registry.cn-shanghai.aliyuncs.com/wpjscc/reacphp-x-websocket-log . -f Dockerfile
docker push registry.cn-shanghai.aliyuncs.com/wpjscc/reacphp-x-websocket-log
```

或本地标签：

```bash
docker build -t reactphp-tail-websocket .
docker run --rm -p 8099:8099 reactphp-tail-websocket \
  php websocket.php group1 0.0.0.0:8099 cliCollectors
```

镜像内已执行 `composer install`，工作目录为 **`/var/www`**；运行容器时通过命令传入 `websocket.php` 参数。

## 仓库结构（主要文件）

| 路径 | 作用 |
|------|------|
| `websocket.php` | HTTP + WebSocket 服务入口 |
| `examples/index.html` | 组播日志查看与绑定 UI |
| `examples/tail-cli.php` | 带 WebSocket 上行的 tail 客户端 |
| `examples/TailCliTail.php` | 扩展 Tail，暴露 `getWatchedFilePaths()` 供 `/api/files` 使用 |
| `composer.json` | `reactphp-x/tail`、`reactphp-x/websocket-group`、`ratchet/pawl` 等 |

## 许可证

以项目内 `composer.json` 及所用依赖的开源许可证为准。
