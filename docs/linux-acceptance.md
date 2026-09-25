# Linux amd64 目标机验收

将已验证的整个 `dist-aot/` 目录复制到 Linux amd64 目标机后，按下面步骤
验收。已有的构建和运行结果见[验证记录](verification.md)；它们不能代替
你自己的服务器、数据库和业务接口验收。

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

脚本随仓库位于 [`scripts/probe-static-linux.sh`](../scripts/probe-static-linux.sh)，
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
[`scripts/accept-saiadmin-linux.sh`](../scripts/accept-saiadmin-linux.sh)：

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

若以后要宣称某台新主机完成数据库业务验收，应分别记录该主机的系统
版本、ELF 哈希、静态链接检查、启动结果、四条业务路径、普通 PHP
对照及清理结果。任一项缺失或失败，就不要把该主机标为“四条业务路径
已验收”；也不要用其他系统的结果补足。这不是本轮增加测试机或重复
编译的要求。
