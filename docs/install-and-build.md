# 安装与构建

到 [v0.1.0 Releases 页面](https://github.com/supdger/webman-aot/releases/tag/v0.1.0)
的 **Assets** 下载你的开发电脑对应的安装包和 `SHA256SUMS.txt`：

| 开发电脑 | 下载文件 |
| --- | --- |
| macOS Apple Silicon | `webman-aot-0.1.0-macos-arm64.tar.gz` |
| Windows x64 | `webman-aot-0.1.0-windows-x86_64.zip` |

不要下载 GitHub 自动生成的「Source code (zip)」充当安装包。先把本机
计算的 SHA-256 与 `SHA256SUMS.txt` 中同名文件的一行对照；不同就
不要安装：

```sh
# macOS，在下载目录执行
shasum -a 256 webman-aot-0.1.0-macos-arm64.tar.gz
```

```powershell
# Windows PowerShell，在下载目录执行
(Get-FileHash .\webman-aot-0.1.0-windows-x86_64.zip -Algorithm SHA256).Hash
```

安装包按**开发电脑**系统选，不是按目标 Linux 服务器选。两端都构建
Linux amd64 musl 程序，Windows 包不生成 Windows exe。安装器仅写入
当前用户的私有工具目录及命令目录，不安装 Docker 或系统级 PHP。

## 第 1 步：在开发电脑安装工具

**安装包必须先解压；已经解压过就直接使用解压出的文件夹，不要再执行
解压命令。** 当前 v0.1.0 没有双击安装入口，解压后还需执行一条安装
命令。安装脚本位于安装包根目录，不在你的 Webman 项目里。

### Windows x64

1. 在下载目录找到 `webman-aot-0.1.0-windows-x86_64.zip`，右键选择
   “全部提取”。如果已经有解压出的文件夹，跳过这一步。
2. 打开解压出的文件夹。应能直接看到 `install.ps1`、`uninstall.ps1`
   和 `payload`；看不到 `install.ps1` 就继续找实际包含它的文件夹。
3. 在这个文件夹的地址栏输入 `powershell` 并按回车。确认 PowerShell
   当前路径是这个文件夹，然后执行：

   ```powershell
   powershell -ExecutionPolicy Bypass -File .\install.ps1
   ```

### macOS Apple Silicon

1. 双击下载的 `webman-aot-0.1.0-macos-arm64.tar.gz` 解压；如果已经解压，
   跳过这一步。
2. 进入能看到 `install.sh` 的文件夹，在该文件夹打开终端，执行：

   ```sh
   ./install.sh
   ```

安装脚本结束时会显示两行路径，例如 Windows 上：

```text
Webman AOT installed in: E:\Users\你的用户名\AppData\Local\webman-aot
Command installed as: E:\Users\你的用户名\AppData\Local\webman-aot\bin\webman-aot.cmd
```

这表示**安装脚本已完成**。关闭并重新打开终端，执行：

```sh
webman-aot version
```

输出 `webman-aot 0.1.0` 才表示新终端也能找到并运行工具。安装阶段不会
编译 Webman 项目；下一步才进入项目目录。

### 命令行解压安装包

不使用文件管理器时，**先进入下载文件所在目录**，确认那里有 ZIP 或
tar.gz，再执行对应命令。已经在解压后的目录时，不要重复执行本段；
直接回到上面的安装脚本命令。

```sh
# macOS，在 tar.gz 所在目录
mkdir webman-aot-install
tar -xzf webman-aot-0.1.0-macos-arm64.tar.gz -C webman-aot-install
cd webman-aot-install
./install.sh
```

```powershell
# Windows PowerShell，在 ZIP 所在目录
New-Item -ItemType Directory -Force .\webman-aot-install | Out-Null
tar.exe -xf .\webman-aot-0.1.0-windows-x86_64.zip -C .\webman-aot-install
if ($LASTEXITCODE -ne 0) { throw '解压失败；请确认当前目录有 ZIP 文件，不要继续安装' }
powershell -ExecutionPolicy Bypass -File .\webman-aot-install\install.ps1
```

## 第 2 步：编译你的 Webman 项目

在开发电脑进入你自己的后端目录：该目录内应有 `webman` 和
`composer.json`。不要在工具安装包目录或本仓库源码目录执行。

```sh
webman-aot doctor
```

看最后的 `Result`：

- `Result: healthy`：环境已准备好，继续下方的 `build`。
- `Result: unhealthy`，并看到
  `[ERROR] component:... locked component is missing` 或
  `[ERROR] components: ... not downloaded yet`，或
  `[ERROR] prepared-toolchain: ... run doctor --repair`：这在**第一次运行**
  很常见，表示编译组件尚未下载，**不代表工具安装失败**。如果平台、磁盘、
  网络和项目检查同时显示 `[OK]`，执行：

  ```sh
  webman-aot doctor --repair
  webman-aot doctor
  ```

`doctor` 只检查，不会下载组件；`doctor --repair` 才会在当前用户目录
下载、校验并准备锁定的编译工具链。首次运行可能很久，且在完成前
可能暂时没有输出；请等它返回命令提示符。修复成功时会显示
`Toolchain generation activated: ...`，后续检查应显示 `Result: healthy`。
只有健康时才能编译。如果修复命令报错，或再次检查仍为 `unhealthy`，
**不要继续编译**；查看具体 `[ERROR]` 和输出末尾的 Diagnostic bundle
路径，先处理网络、磁盘、依赖或项目错误。检查通过后编译：

```sh
webman-aot build --profile=saiadmin
webman-aot verify
```

普通 Webman 项目改用 `webman-aot build`。成功后，**你的项目目录**
会出现 `dist-aot/`；这里才是编译完成的 Linux 发布目录。

## 第 3 步：部署 Linux 发布目录

将整个 `dist-aot/` 复制到 Linux amd64 服务器，在部署目录配置外置 `.env`，
然后进入该目录执行 `./start.sh`。数据库连接等环境参数不需要重新编译。
请按[目标机验收](linux-acceptance.md)检查启动和真实业务接口；
`webman-aot verify` 不等于目标服务器验收。

## 卸载开发电脑上的工具

在下载目录打开终端，使用安装时解压出的 `webman-aot-install` 目录：

```sh
# macOS
./webman-aot-install/uninstall.sh --purge
```

```powershell
# Windows PowerShell
powershell -ExecutionPolicy Bypass -File .\webman-aot-install\uninstall.ps1 -Purge
```

不加 `--purge` / `-Purge` 时，会移除当前安装和命令入口，但保留部分
已下载组件与安装备份；加上后会清除 Webman AOT 的整个用户工具目录。
Mac 默认目录是 `~/Library/Application Support/webman-aot`，Windows 默认
目录是 `%LOCALAPPDATA%\webman-aot`。卸载脚本还会移除安装时加入的
命令路径；重新打开终端后生效。卸载不会删除你的 Webman 项目、项目
中的 `dist-aot/`，也不会删除下载的安装包或解压目录。

如果解压目录已经删除，从上方 Release 重新下载与你的开发电脑对应的
安装包并解压，即可取得卸载脚本。自定义安装目录的用户卸载时也要传入
相同目录参数：Mac 使用 `--home`、`--bin-dir`；Windows 使用
`-InstallRoot`、`-BinDir`，避免只卸载默认位置。

## 使用边界

想自己从本仓库源码生成上述工具安装包，请看
[从源码制作安装包](build-installers.md)。源码下载、工具安装和编译
Webman 项目是三个不同动作。

当前实测组合为 SaiAdmin 6.1.5、Webman 2.2.4、TypePHP 0.9.2 与
锁定的 PHP 8.4.25 静态 SDK。新增插件或依赖版本漂移时，未知业务
PHP 不会被静默忽略，需先处理适配失败再出包。`dist-aot` 的 `verify`
只能证明包结构、覆盖和静态链接结构；部署到目标 Linux 后仍须按
[目标机验收](linux-acceptance.md)检查启动、数据库和业务接口。
构建会为 SaiAdmin 保留空的 `plugin/saiadmin/public/export` 可写目录，
但不会把其中已有的导出文件复制进产物；`public/storage` 中的历史上传文件
也不会随包分发。部署时如需保留这些运行数据，应单独迁移，不能把构建包
当作数据备份。上述目录若含 PHP 或符号链接，构建会报错而不是静默排除。
第三方组件的许可和来源见[归属说明](../NOTICE.md)；构建与运行的
已验证范围见[验证记录](verification.md)。在未验证过的目标系统和
业务项目上，仍需执行实际部署与业务验收。
