# Linux amd64 目标机验收

计划覆盖的系统为 CentOS 7、Alibaba Cloud Linux 3、Alibaba Cloud
Linux 4 和 Ubuntu 22.04。本轮可用的目标机只有 CentOS 7 虚拟机及
Alibaba Cloud Linux 3 ECS；两者的实测范围见下文。其余系统留待后续
使用者按同一流程验证，不把未测系统标成已支持。虚拟机、容器或另一
发行版的结果不能替代某台尚未测试的目标机。

已有的[四发行版容器运行记录](../evidence/2026-09-25-four-distro-docker-runtime.json)
使用同一份预先编译的 ELF，分别确认中立 Webman 插件接口和 SaiAdmin
验证码接口可用；没有用 Docker 编译。它只验证各镜像的用户空间，
全部容器实际共用 Docker Desktop 的 LinuxKit 内核，且 macOS ARM64
上的 amd64 执行为模拟运行。容器内没有隔离数据库，因此登录、
用户信息、权限拒绝和普通 PHP 对照仍须按下面的目标机流程逐台完成。

另有 [CentOS 7 虚拟机运行记录](../evidence/2026-09-25-centos7-vm-saiadmin-runtime.json)：
相同 SaiAdmin ELF 在目标机校验了 SHA-256、x86-64 静态链接和无动态解释器，
仅监听 `127.0.0.1` 启动后，验证码返回 HTTP 200、业务码 200；服务与隔离
测试目录均已清理。这比容器测试多覆盖了 CentOS 7 内核上的实际运行，但
仍是虚拟机，且未建立隔离数据库，不能计入四条业务路径全通过。

另有 [Alibaba Cloud Linux 3 ECS 运行记录](../evidence/2026-09-25-alinux3-ecs-saiadmin-runtime.json)：
同一 ELF 在 ECS 实例上通过哈希与全静态检查，回环地址启动后验证码返回
HTTP 200、业务码 200；数据库和 Redis 临时指向不可用端口，测试进程、
目录及临时 SSH 密钥已清理。ECS 仍是虚拟机，且没有隔离数据库或登录等
业务验收，因此同样不能计入四条业务路径全通过。

随后在该 ECS 的独立目录内完成了[一次性数据库预检](../evidence/2026-09-25-alinux3-ecs-isolated-db-preflight.json)：
仅绑定回环地址的 MariaDB 10.5.29 已初始化，测试库和账号连接通过；数据库
进程随后停止。AOT 产物尚未传入、业务表尚未导入，四条业务路径均未在该机
复测，不能把这项预检记作业务验收通过。

## 准备边界

- 使用同一个经过 `webman-aot verify` 的 `dist-aot` 包，并记录包与
  `server` 的 SHA-256。不要把开发项目的 `.env`、数据库或账号打进包。
- 为每台主机准备隔离测试目录、仅监听本机的测试端口、一次性数据库和
  最小权限测试账号。数据库创建与删除须由操作者明确执行；本仓库的验收
  脚本不会自动建库，也不能指向线上数据库。
- 运行所需的 `.env`、配置、模板与静态资源按分发清单外置。各目标机使用
  同一份业务源码构建出的 ELF；如为某台主机重新编译，必须重新记录哈希并
  说明原因。
- 测试前确认端口空闲、目录不是生产站点、不会覆盖现有服务。测试后停掉
  进程，删除一次性库、账号和临时文件，并确认删除结果。

## 每台主机的证据

在测试目录内先运行只读静态检查脚本；它需要系统提供 `file`、
`readelf`、`sha256sum` 和 `ldd`，不会启动服务或读取 `.env`：

```sh
sh ./probe-static-linux.sh ./server
```

脚本随仓库位于 [`tests/probe-static-linux.sh`](../tests/probe-static-linux.sh)，
部署验收时需与业务验收脚本一同复制到隔离测试目录。它在 Linux x86_64、
静态 ELF 和 `ldd` 检查全部通过后才返回成功；动态系统程序应被拒绝。
同时保存以下原始输出，便于独立复核：

```sh
uname -srm
cat /etc/os-release
sha256sum ./server
file ./server
readelf -l ./server
readelf -d ./server
ldd ./server
```

验收要求是 `ELF 64-bit`、`x86-64`，无 `INTERP`、无动态段，`ldd`
报告 `not a dynamic executable`。不要通过升级系统 glibc、复制动态库或
删除包内文件来使检查通过。

使用隔离测试数据库的连接参数启动 `./start.sh`，确认启动日志和本地监听
端口。再以一次性低权限 SaiAdmin 账号运行
[`tests/accept-saiadmin-linux.sh`](../tests/accept-saiadmin-linux.sh)：

```sh
AOT_TEST_BASE_URL=http://127.0.0.1:8787 \
AOT_TEST_USERNAME=测试账号 \
AOT_TEST_PASSWORD=测试密码 \
AOT_TEST_SESSION_DIR=/隔离测试目录/dist-aot/runtime/sessions \
sh /隔离测试目录/accept-saiadmin-linux.sh
```

此脚本会从隔离会话文件读取验证码，只能用于本机的一次性测试环境，不得
用于生产会话目录或账号。脚本要求 `curl`、`python3`、`perl`、`awk`、`grep`，
只接受显式端口的 `http://127.0.0.1` 地址并绕过代理；这些是验收工具依赖，
不是部署后的 `server` 运行依赖。无需安装 `jq`；CentOS 7 上可先运行
`sh ./accept-saiadmin-linux.sh --self-test` 校验 JSON 解析。四个 `PASS`
分别代表验证码、登录、登录后用户信息和权限拒绝路径通过。运行时还要确认读取外置配置与静态资源正常；
普通 PHP 对照须在同版本依赖和相同隔离数据下另行执行。
权限拒绝检查要求当前锁定的 SaiAdmin 返回业务码 `400`、`type=failed`
和“权限不足”消息；业务 `500` 不会算作权限验收成功。

每台主机分别记录系统版本、ELF 哈希、静态链接检查、启动结果、四条业务
路径、普通 PHP 对照及清理结果。任一项缺失或失败，就将该系统标为
“未验收”，不要以其他系统的结果补足。
