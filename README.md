# Webman AOT

Webman AOT 是一个独立的全局命令行工具项目，目标是在 macOS ARM64 和
Windows x64 主机上构建 Linux amd64 musl 全静态 Webman/SaiAdmin
可执行文件。

全静态 SDK 可行性门和 Mac/Windows 跨主机最小 Webman 构建已经通过。
SaiAdmin 6.1.5 在 Mac ARM64 上由全局命令完整编译、打包并通过产物校验；
从隔离安装包重装的同一全局命令再次构建出相同 ELF SHA-256。
同一全静态 Linux ELF 在无 PHP 的隔离 Ubuntu 24.04 x86_64 虚拟机中启动，
验证码、登录、登录后用户信息和权限拒绝路径均通过。
Windows x64 也从已安装的全局命令完成同版 SaiAdmin 构建，其规范化
输入、ELF、覆盖清单、资源清单和分发文件摘要与 Mac 产物一致。目标
两台现有目标机均已运行相同哈希的 ELF，并通过启动与验证码检查；
数据库支持的四条业务路径是在隔离 Ubuntu 虚拟机中验收的，不能称为
已在那两台目标机通过。其他发行版留待后续使用者验证，不作为本轮
源码交付或重复编译的门槛。
新增中立 Webman 插件的完整构建、Linux HTTP 路由与普通 PHP 对照也已在
隔离试验中通过；Mac 与 Windows 从同一项目快照构建出字节相同的 ELF、
覆盖清单和资源清单。
目标系统的逐机检查项见 [Linux 目标机验收](docs/linux-acceptance.md)。
最新包已排除 SaiAdmin 历史导出数据；修复后 Mac 全量构建和校验通过，
Windows 已安装修复包但未重复完成全量构建；这次额外全量重建不作为
交付门槛。两台目标服务器此前运行的
是同一 SHA-256 的 ELF，启动与验证码通过，数据库相关业务路径未在
这两台服务器验收。详见[运行数据打包修复证据](evidence/2026-09-25-runtime-export-package-fix.json)。

源码、文档和证据已交付到 `main`。候选安装包目前保存在本地，
尚未作为公开 Release 发布；公开发布还需核定随包第三方许可义务，
不能把这项发布审查混同为构建或运行失败。
[当前源码及 Git 历史的隐私核查](evidence/2026-09-25-source-history-privacy-audit.json)
与[候选包隐私核查](evidence/2026-09-25-exportguard-public-audit.json)
分别记录；两者都不等于安装包发布审查。
本仓库原创代码与 TypePHP 补丁的许可归属见[上游归属说明](NOTICE.md)。

## 仓库与安装包

这个 Git 仓库供开发和复核；使用者安装的是按宿主系统选择的安装包，
不需要克隆仓库，也不需要在业务项目安装 Composer AOT 插件。
安装包由 `tools/package-installers.php` 明确选择运行所需的 `bin`、`src`、
锁文件、兼容规则和少量构建工具，不包含 `.github/`、`tests/`、
`evidence/` 或开发文档。

- `.github/workflows/` 保留跨 Windows 宿主的自动复现检查。
- `tests/` 保留兼容规则、漏编译拦截和产物校验的回归测试。
- `evidence/` 保留已报告的构建与运行结果，不能用它代替新的运行验收。
- `build/`、`dist/`、`vendor/`、私有配置和系统生成文件不得提交到源码仓库。

新增仓库文件时先判断它属于运行时、构建工具、测试还是证据；只有安装
确实需要的文件才加入安装包。删除测试或工具前应先确认其调用方与对应
验收门槛，不按“终端用户不直接运行”作为删除依据。

本轮结果和边界见
[SaiAdmin 业务运行证据](evidence/2026-09-25-saiadmin-full-static-business-runtime.json)。
新增插件验证见
[中立插件编译与运行证据](evidence/2026-09-25-neutral-plugin-full-build-runtime.json)。
同一现有 ELF 在四种目标发行版容器中的补充启动和接口检查见
[容器运行证据](evidence/2026-09-25-four-distro-docker-runtime.json)；
容器共用 Docker 宿主内核，不能替代目标机验收，构建过程也不使用 Docker。
同一 SaiAdmin ELF 已在 [CentOS 7 虚拟机](evidence/2026-09-25-centos7-vm-saiadmin-runtime.json)
和 [Alibaba Cloud Linux 3 ECS](evidence/2026-09-25-alinux3-ecs-saiadmin-runtime.json)
通过全静态检查、回环地址启动与验证码接口检查；两者都不是裸机，也未完成
数据库支持的四条业务路径。
候选安装包的使用步骤见[安装与构建](docs/install-and-build.md)。
与万总插件及 TypePHP 的具体复用范围见[上游复用边界](docs/upstream-reuse.md)。
SaiAdmin 6.1.5 在普通 PHP 8.4 且 `E_ALL` 下还有一处隐式可空参数问题，
需要按[迁移说明](docs/saiadmin-compatibility.md)处理；AOT 不改原项目源码。

## 一套项目源码，两套宿主工具

Mac 和 Windows 使用同一份 Webman/SaiAdmin PHP 项目、同一套兼容规则与
锁定的 Linux amd64 musl SDK。安装包只按开发电脑的系统分别提供私有
PHP、TypePHP 和编译工具；选择宿主工具包不要求改业务源码。适配只在
隔离构建目录生成 AOT 副本，普通 PHP 项目保持原样。两端从干净目录
生成的 6,554 个 C++/头文件已逐项一致；两端最终 ELF 也逐字节一致，
相同哈希的 ELF 已通过隔离 Linux 业务验收。Mac、Windows 已安装全局命令
均已完成完整构建和包校验；尚未实测的目标系统不能由这些结果代替。

## 开发入口

```bash
php tools/toolchain.php self-check
php tests/run.php
php bin/webman-aot.php --help
```

工具链脚本只读写本仓库的 `build/`、`dist/` 和后续定义的用户私有工具目录，
不要求目标 Webman 项目安装 Composer 插件。

发布版启动器只使用用户私有目录中的运行时和应用代码：

- macOS ARM64：`~/Library/Application Support/webman-aot`
- Windows x64：`%LOCALAPPDATA%\webman-aot`

源码开发时直接运行 `php bin/webman-aot.php`；最终安装包会把私有 PHP、
应用版本和启动器放入上述目录，不读取目标项目的 PHP 或 Composer AOT 包。

每次命令运行按 `bootstrap`、`dispatch`、`execute` 记录阶段状态。用户私有
`logs/<run-id>/` 中包含结构化 `events.jsonl` 和人类可读 `run.log`；失败时
额外生成 `diagnostic.json`，并使用稳定退出码区分用法错误、不可用依赖、
内部错误和配置错误。

只读环境检查：

```bash
webman-aot doctor
webman-aot doctor --json
webman-aot doctor --repair
```

`doctor` 检查构建主机、架构、磁盘、工具链源站、当前 Webman 项目、锁定组件
版本和归档摘要。缺失或损坏时列出具体组件并返回非零状态，不下载、不修复，
只有显式执行 `doctor --repair` 才会下载缺失或损坏组件。修复先写入候选代次，
完成全部摘要自检后通过目录重命名发布；失败候选会被删除，当前代次保持不变。

独立产物检查：

```bash
webman-aot verify
webman-aot verify --path=/path/to/dist-aot --json
webman-aot verify --deployed
```

默认按原始包严格检查所有受管文件及摘要；部署后修改了外置配置或模板时，
使用 `--deployed` 检查不可变文件，并允许 `.env`、上传和日志等运行数据。
Mac/Windows 上会检查 ELF 静态结构、资源、权限和覆盖清单，但报告中的
`targetLdd` 为 `not-run-on-build-host`；仍须在目标 Linux 上另行执行
`ldd ./server` 并留存证据。隔离 Linux 虚拟机的 SaiAdmin 业务验收
已记录在上方证据文件中；此命令本身不替代启动和业务验收。

## Windows 跨主机复现

在 Windows x64 PowerShell 中检出同一提交后直接运行：

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\windows-replay.ps1
```

只准备隔离的 PHP、TypePHP、LLVM、静态 SDK 和 musl sysroot，不运行
跨主机 smoke 编译时，可加 `-PrepareOnly`。成功后工作区会生成
`prepared-toolchain.json`，记录工具路径、锁摘要和 SDK 指纹。此模式仅证明
编译工具已备齐，不证明 Webman 项目完成构建或运行。

脚本从 `toolchain.lock.json` 下载并校验锁定组件，将下载缓存和每次构建工作区
放在 `%LOCALAPPDATA%\webman-aot`。它不调用 Docker、winget、系统 PHP 或 GUI
安装器，并分别比较 Mac 基线的规范化输入摘要和 ELF SHA-256。只有两项都一致
才返回成功。锁定组件使用固定 partial 文件、有界网络重试和断点续传；下载
完成后仍须通过 SHA-256 才会原子提升为可用缓存。

Windows ZIP 与源码归档统一由系统自带 `tar.exe` 解包，避免 PowerShell
`Expand-Archive` 在包含完整 Composer 依赖的 TypePHP 包上长时间挂起。

仓库的 `Windows full-static replay` workflow 在 Windows Server 2022 x64 原生
runner 上执行同一脚本，用于持续验证跨宿主 SDK 与 ELF 一致性。该 workflow
不替代后续干净实体 Windows 用户账号下的安装、构建和卸载验收。
修正规范化输入基线后的
[Windows 自动复核](https://github.com/supdger/webman-aot/actions/runs/36153995918)
在提交 `a22c97a` 上通过；它验证的是静态 smoke 产物，不是另一次 SaiAdmin
全量构建。
