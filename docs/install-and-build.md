# 安装与构建候选版

当前仅提供本地候选安装包，尚未发布。安装包按开发电脑系统选择：
`macos-arm64.tar.gz` 或 `windows-x86_64.zip`；两端都只构建
**Linux amd64 musl 全静态**目标，不生成 Windows exe。目标 Webman
项目无需安装 Composer AOT 插件，业务 PHP 源码也无需按宿主系统分叉。

当前修复后的本地候选包位于 `dist/installers/exportguard-candidate/`：

| 包 | SHA-256 |
| --- | --- |
| `webman-aot-0.1.0-dev-macos-arm64.tar.gz` | `da763c86f84aa7d8493cb9692d54cba6e5ec32089c473ced6c2a30e56f7c27ad` |
| `webman-aot-0.1.0-dev-windows-x86_64.zip` | `82c3eaf5e5844d3fc7f190863b8a36053fa0acd5ef66edd133403eac336b2f1a` |

使用前核对候选包摘要；摘要不一致时不要安装。两包的修复代码与当前
源码摘要一致；Mac 已完成修复后 SaiAdmin 全量构建和包校验，Windows
修复后安装与 `doctor` 已通过，但未重复完成全量构建。安装器仅写入当前
用户的私有工具目录及用户命令目录，不安装 Docker 或系统级 PHP。
`dist/installers/` 根目录及 `neutral-candidate/` 中的早期同名归档
已被本候选取代，不要混用。

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
候选包随带的许可证文件已有[清单](../evidence/2026-09-25-runtime-license-inventory.json)；
[当前候选包的只读许可预检](../evidence/2026-09-25-exportguard-license-preflight.json)
还记录了 Windows 运行时顶层 GPL v3 许可文本和 SBOM 中未声明许可证的
条目；锁定的
[TypePHP v0.9.2 源码](https://github.com/swoole/typephp/tree/v0.9.2)
在 `composer.json` 中声明 `GPL-3.0-only`，包内许可文本与上游文本规范化
换行后相同。它们的适用范围、再分发义务以及最终静态 ELF 的第三方许可义务
尚未核定；公开二进制发布前须完成审查，不能把文件存在当作合规结论。
