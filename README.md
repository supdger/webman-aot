# Webman AOT Builder

Webman AOT Builder 将 Webman / SaiAdmin 项目编译成 Linux amd64 全静态程序。
在 macOS Apple Silicon 或 Windows x64 开发机上安装工具、构建项目，再将生成的 `dist-aot/` 部署到 Linux，目标机无需安装 PHP。

## 实现方式

构建器在项目副本中做兼容适配，通过 TypePHP 编译 PHP 代码，再用 Clang 和 PHPx 静态 SDK 生成可执行程序。
原项目源码保持不变，配置、模板和静态资源按需保留为外置文件。

![Webman AOT Builder 构建流程](https://raw.githubusercontent.com/wiki/supdger/webman-aot-builder/assets/build-flow.svg)

## 源码与安装包

本仓库是构建工具的源码，包含命令行程序、项目适配、编译组件管理和安装包制作脚本。
开发或自行制作安装包可从 [最新开发源码](https://github.com/supdger/webman-aot-builder/tree/main) 开始，
具体步骤见 [源码构建指南](https://github.com/supdger/webman-aot-builder/wiki/Build-from-source)。

第一次安装，请打开 [最新正式版下载页](https://github.com/supdger/webman-aot-builder/releases/latest)，下载与你的**开发机**匹配的 **setup**。
Windows x64 选 `windows-x86_64-setup.cmd`；macOS Apple Silicon 选 `macos-arm64-setup.zip`，解压后运行其中的 `.command`。
setup 会让你选择轻量包或完整包，再自动下载、校验和安装。以下为 v0.3.1 下载页的全部资产：

| 文件名 | 用途与适用情况 |
| --- | --- |
| `webman-aot-builder-0.3.1-windows-x86_64-setup.cmd` | **Windows 首次安装推荐**。运行后按提示选择包类型；下载时需要联网。 |
| `webman-aot-builder-0.3.1-macos-arm64-setup.zip` | **macOS Apple Silicon 首次安装推荐**。解压运行 `.command`，按提示选择包类型；下载时需要联网。 |
| `webman-aot-builder-0.3.1-windows-x86_64.zip` | Windows 轻量安装包；手动解压运行 `install.cmd`。首次构建时联网下载编译组件。 |
| `webman-aot-builder-0.3.1-macos-arm64.tar.gz` | macOS Apple Silicon 轻量安装包；手动解压运行 `install.command`。首次构建时联网下载编译组件。 |
| `webman-aot-builder-0.3.1-full-windows-x86_64.zip` | Windows 完整安装包；包含编译组件。适合提前下载后复制到离线开发机，解压运行 `install.cmd`。 |
| `webman-aot-builder-0.3.1-full-macos-arm64.tar.gz` | macOS Apple Silicon 完整安装包；包含编译组件。适合提前下载后复制到离线开发机，解压运行 `install.command`。 |
| `webman-aot-builder-0.3.1-windows-x86_64-components.zip` | Windows 编译组件，由工具自动下载和校验；普通安装无需单独下载，不能独立安装工具。 |
| `webman-aot-builder-0.3.1-macos-arm64-components.zip` | macOS Apple Silicon 编译组件，由工具自动下载和校验；普通安装无需单独下载，不能独立安装工具。 |
| `SHA256SUMS` | 上述八项资产的 SHA-256 校验清单，用于核对下载文件；setup 会自动校验所选安装包。 |
| GitHub 自动提供的 `Source code (zip)` / `Source code (tar.gz)` | 对应版本的源码，供开发或自行构包；直接安装请选择上面的 setup 或安装包。 |

文件名没有 `full` 的安装包就是轻量包；轻量包和完整包安装后都提供 `webman-aot` 命令。
完整包的离线范围是工具安装和编译组件准备；自己的项目仍须事先备好 Composer 依赖及构建所需文件。
这些下载包安装在开发机上；Linux 目标机接收构建生成的整个 `dist-aot/`。

### macOS 首次打开被拦截时

首次双击 `setup.command` 或 `install.command`，可能看到“未打开”或“Apple 无法验证”提示。先确认文件来自本项目 [正式发行页](https://github.com/supdger/webman-aot-builder/releases/latest)，并用同一版本的 `SHA256SUMS` 核对下载文件，然后按以下步骤操作：

1. 在提示窗口点 **“完成”**，保留文件，不要点“移到废纸篓”。

   ![首次打开 install.command 时，点“完成”保留文件](docs/images/macos-install-unverified.png)

2. 打开苹果菜单 → **系统设置 → 隐私与安全性**，向下滚动到 **“安全性”**。
3. 找到“已阻止 `install.command`（或 `setup.command`）”的提示，确认文件名后点 **“仍要打开”**。

   ![在“隐私与安全性”的“安全性”中，为 install.command 点“仍要打开”](docs/images/macos-install-open-anyway.png)

4. 按系统提示再次确认“打开”或“仍要打开”，并完成登录密码或 Touch ID 验证。若终端未自动出现，回到解压目录，再双击同一个 `.command`。
5. 终端打开后，按安装器提示继续。

若找不到“仍要打开”，重新双击一次文件，再回到该设置页面；该按钮通常只在尝试打开后约一小时内显示。详见 [Apple 官方说明](https://support.apple.com/zh-cn/102445)。以上步骤适用于“无法验证”提示；若提示“将损坏你的电脑”或“文件已损坏”，请停止安装，重新下载并核对校验值。

## v0.3.1：一次启动，按提示完成

直接安装时，在 [v0.3.1 Release](https://github.com/supdger/webman-aot-builder/releases/tag/v0.3.1) 选择开发机对应的 **setup**：macOS 下载 setup ZIP，Windows 下载 setup CMD。启动后只需选择轻量包或完整包、确认安装，再选择自己的 Webman / SaiAdmin 项目目录；工具会自动下载、校验、安装、检查新版本、构建项目并验证 `dist-aot/`。包类型由入口菜单选择，无需自己拼接构建或安装命令。

从源码开始时，macOS 在源码根目录执行 `sh ./build.command`，Windows 在 PowerShell 执行 `.\build.cmd`。入口会自动准备锁定材料、制作安装包并检查包清单，随后进入相同的安装和项目流程。具体启动步骤见 [源码构建指南](https://github.com/supdger/webman-aot-builder/wiki/Build-from-source)。

过程会显示当前步骤、实际下载状态和编译器输出的文件计数。较长步骤没有新输出时，会显示进程状态、已用时间和无输出时长；沉默期间的工作进度会标为未知。成功时告诉你产物位置和下一步；失败时保留原始原因，提供恢复建议、本机日志位置和 [Issues](https://github.com/supdger/webman-aot-builder/issues) 地址。项目构建失败后可选择重试、重选目录或结束。安装前会显示安装位置和 PATH 影响，由你确认。

发布状态以 Release 中实际提供的资产为准；源码候选不等于已公开的安装包。v0.2.3 的历史操作说明保留在 [历史安装指南](https://github.com/supdger/webman-aot-builder/wiki/Install-0.2.3)。

自动 `verify` 检查构建产物的结构、完整性及工具报告的静态属性；Linux 目标机启动、数据库和业务接口仍需部署后验收。

## 安装后的命令行使用

引导入口会自动执行校验。需要以后单独构建其他项目时，可在已安装的终端中使用 `webman-aot`；命令和启动示例见 [安装与使用指南](https://github.com/supdger/webman-aot-builder/wiki/Install)。SaiAdmin 的适用版本及要求见 [SaiAdmin 兼容说明](https://github.com/supdger/webman-aot-builder/wiki/SaiAdmin-Compatibility)。

## 构建结果

产物结构示意（运行资源随项目而异）：

```text
dist-aot/
├── server          # Linux amd64 全静态程序
├── start.sh        # 启动脚本
├── stop.sh         # 停止脚本
├── config/         # 外置配置
├── …               # 项目所需的模板、静态资源等
└── manifest.json   # 分发清单
```

把**整个 `dist-aot/`** 复制到 Linux amd64 目标机，按项目需要配置 `.env`、数据库等运行环境后，
在该目录启动：

```sh
./start.sh
```

后台运行用 `./start.sh --daemon`，停止用 `./stop.sh`。
目标机配置和启动后的业务检查见 [Linux 部署与验收](https://github.com/supdger/webman-aot-builder/wiki/Linux-Acceptance)。

## 文档

- [Wiki](https://github.com/supdger/webman-aot-builder/wiki/Home)：安装、使用与维护文档
- [更新日志](https://github.com/supdger/webman-aot-builder/blob/main/CHANGELOG.md)：功能、修复与升级影响

## 许可与反馈

原创代码采用 [MIT 许可证](LICENSE)，第三方组件的许可与归属见 [NOTICE](NOTICE.md)。
问题与建议请提交到 [Issues](https://github.com/supdger/webman-aot-builder/issues)。
