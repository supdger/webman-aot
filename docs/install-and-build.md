# Webman AOT Builder 安装与构建

产品名是 **Webman AOT Builder（`webman-aot-builder`）**。目标仓库为
`supdger/webman-aot-builder`，新版本候选为 v0.2.0。仓库更名、版本占用和
Release 资产尚未在线核实；因此本文不提供一个看似可直接下载的 v0.2.0 链接。
发布后请从新仓库的 Release 选取与开发电脑匹配的安装包，并按以下步骤安装。

已发布的 v0.1.3 是旧名称产品：包名以 `webman-aot-0.1.3-` 开头，命令为
`webman-aot`，默认用户目录为旧根。它的安装方法只用于需要查看或移除该历史版本
的用户；该版本的说明保留在本文[旧版 v0.1.3 迁移](#旧版-v013-迁移)一节。
旧命令的 `self-update` 不会安装新命令、迁移用户目录或缓存。

## 第 1 步：安装新版

从 `supdger/webman-aot-builder` 的 Release 下载适用于**开发电脑**的
macOS Apple Silicon 或 Windows x64 安装包。每个平台提供轻量包和完整包：
轻量包首次使用时会联网下载并校验精简编译组件；完整包携带相同组件，可离线准备。
不要使用 GitHub 自动生成的源码 ZIP 作为安装包。

下载后解压，并在能直接看到 `install.sh`（macOS）或 `install.ps1`（Windows）
的目录执行安装脚本：

```sh
# macOS
./install.sh
```

```powershell
# Windows PowerShell
powershell -ExecutionPolicy Bypass -File .\install.ps1
```

安装完成后，关闭并重新打开终端，确认新命令可用：

```sh
webman-aot-builder version
```

新版默认只管理自己的数据根：

- macOS：`~/Library/Application Support/webman-aot-builder`
- Windows：`%LOCALAPPDATA%\webman-aot-builder`
- 需要自定义根目录时设置 `WEBMAN_AOT_BUILDER_HOME`

新版不读取、覆盖或删除旧版 `webman-aot` 数据根，也不会自动搬运旧缓存。

## 第 2 步：编译 Webman 项目

在开发电脑进入自己的 Webman 后端目录；该目录应同时包含 `webman` 和
`composer.json`，不要在安装包解压目录或工具源码目录执行。

轻量包首次使用建议先检查并准备组件：

```sh
webman-aot-builder doctor
```

`doctor` 会检查平台、磁盘、锁文件和项目，并按需下载、校验和准备组件。
只有最后显示 `Result: healthy` 才继续构建。完整包在安装时已准备组件，可以直接
构建。只检查、不下载时使用 `webman-aot-builder doctor --check`。

SaiAdmin 项目运行：

```sh
webman-aot-builder build --profile=saiadmin
webman-aot-builder verify
```

普通 Webman 项目运行 `webman-aot-builder build`，可省略 `--profile=saiadmin`。
成功后，项目目录会出现 `dist-aot/`。将整个目录部署到 Linux amd64 目标机；
`verify` 证明包结构与静态属性，不代表目标主机的数据库和业务接口已经验收。
目标机步骤见[Linux 目标机验收](linux-acceptance.md)。

## 卸载新版

使用**新版安装包解压目录**内的卸载脚本，不要在项目目录或任意终端当前目录
调用相对路径。默认只移除新版命令入口和安装程序；加 `--purge`（macOS）
或 `-Purge`（Windows）会同时清除新版用户数据根内的组件和备份：

```sh
# macOS：在能直接看到 uninstall.sh 的目录
./uninstall.sh --purge
```

```powershell
# Windows：在能直接看到 uninstall.ps1 的目录
powershell -ExecutionPolicy Bypass -File .\uninstall.ps1 -Purge
```

新版卸载器只处理新版命令 `webman-aot-builder` 和新版数据根，不删除旧版
`webman-aot` 安装、旧用户数据、Webman 项目、`dist-aot/`、安装包或解压目录。
如安装时使用自定义目录，卸载时传入相同参数：macOS 的 `--home`、
`--bin-dir`；Windows 的 `-InstallRoot`、`-BinDir`。

## 旧版 v0.1.3 迁移

v0.1.3 安装包与旧命令仍可从[旧版 Release](https://github.com/supdger/webman-aot/releases/tag/v0.1.3)
获取。旧版用户要改用新身份时：

1. 从 `supdger/webman-aot-builder` 的 Release 取得新版安装包。该新仓库地址和
   Release 在完成远端更名及发布前尚未验证。
2. 解压新版安装包，并运行其中的 `install.sh` 或 `install.ps1`。
3. 打开新终端，运行 `webman-aot-builder version`，确认输出为所装版本。
4. 在项目上执行 `webman-aot-builder doctor`、`build` 和 `verify`。
5. 确认新版本工作后，若要移除旧版，请从 v0.1.3 安装包中运行旧卸载脚本。
   先确认是否保留旧目录中的缓存和安装备份；新版安装器不会代为迁移或清理。

旧版命令 `webman-aot self-update` 只更新 v0.1.3 所属程序，不会安装
`webman-aot-builder` 命令，不会把数据目录切换到
`webman-aot-builder`，也不迁移或验证旧缓存。

历史 v0.1.3 安装器使用的数据目录是 macOS
`~/Library/Application Support/webman-aot` 和 Windows
`%LOCALAPPDATA%\webman-aot`。旧版卸载行为以该版本安装包内的脚本为准；
不要用新版卸载说明去清理旧目录。
