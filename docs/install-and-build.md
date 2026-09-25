# 安装与构建

**目前尚无公开安装包。** [Releases](https://github.com/supdger/webman-aot/releases)
开放下载前，下面的步骤仅供已经拿到候选包的测试者使用；GitHub 的源码 ZIP
不含私有 PHP 运行时，不能直接安装。安装包按开发电脑系统选择：
`macos-arm64.tar.gz` 或 `windows-x86_64.zip`；两端都只构建
**Linux amd64 musl 全静态**目标，不生成 Windows exe。目标 Webman
项目无需安装 Composer AOT 插件，业务 PHP 源码也无需按宿主系统分叉。

当前修复后的候选包保存在项目维护者本地，尚未对外发布：

| 包 | SHA-256 |
| --- | --- |
| `webman-aot-0.1.0-dev-macos-arm64.tar.gz` | `da763c86f84aa7d8493cb9692d54cba6e5ec32089c473ced6c2a30e56f7c27ad` |
| `webman-aot-0.1.0-dev-windows-x86_64.zip` | `82c3eaf5e5844d3fc7f190863b8a36053fa0acd5ef66edd133403eac336b2f1a` |

使用前核对候选包摘要；摘要不一致时不要安装。安装器仅写入当前
用户的私有工具目录及用户命令目录，不安装 Docker 或系统级 PHP。

## macOS ARM64

```sh
mkdir webman-aot-install
tar -xzf webman-aot-0.1.0-dev-macos-arm64.tar.gz -C webman-aot-install
cd webman-aot-install
./install.sh
```

重新打开终端后，进入 Webman 项目根目录：

```sh
webman-aot doctor
webman-aot doctor --repair
webman-aot doctor
webman-aot build --profile=saiadmin
webman-aot verify
```

`doctor` 是只读检查；只有显式 `--repair` 会在用户私有目录下载并校验
锁定工具链。若已备齐工具链，可跳过修复。普通 Webman 项目省略
`--profile=saiadmin`，由项目结构自动识别。

## Windows x64 PowerShell

```powershell
New-Item -ItemType Directory -Force .\webman-aot-install | Out-Null
tar.exe -xf .\webman-aot-0.1.0-dev-windows-x86_64.zip -C .\webman-aot-install
powershell -ExecutionPolicy Bypass -File .\webman-aot-install\install.ps1
```

重新打开 PowerShell，进入 Webman 项目根目录后执行与 Mac 相同的
`webman-aot doctor`、`doctor --repair`、`build` 和 `verify` 命令。
首次修复会下载较大的静态 SDK 和编译工具；无需 Docker、GUI 或目标
项目内的 Composer AOT 插件。

## 使用边界

当前实测组合为 SaiAdmin 6.1.5、Webman 2.2.4、TypePHP 0.9.2 与
锁定的 PHP 8.4.25 静态 SDK。新增插件或依赖版本漂移时，未知业务
PHP 不会被静默忽略，需先处理适配失败再出包。`dist-aot` 的 `verify`
只能证明包结构、覆盖和静态链接结构；部署到目标 Linux 后仍须按
[目标机验收](linux-acceptance.md)检查启动、数据库和业务接口。
构建会为 SaiAdmin 保留空的 `plugin/saiadmin/public/export` 可写目录，
但不会把其中已有的导出文件复制进产物；`public/storage` 中的历史上传文件
也不会随包分发。部署时如需保留这些运行数据，应单独迁移，不能把构建包
当作数据备份。上述目录若含 PHP 或符号链接，构建会报错而不是静默排除。
公开二进制安装包前还需核定第三方运行时的再分发许可；当前构建和运行
证据见[验证记录](verification.md)。这项发布审查不影响本地候选包的
已完成测试，但不能被测试结果替代。
