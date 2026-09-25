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

把下载的安装包放到一个目录，在那个目录打开终端。

### macOS Apple Silicon

```sh
mkdir webman-aot-install
tar -xzf webman-aot-0.1.0-macos-arm64.tar.gz -C webman-aot-install
cd webman-aot-install
./install.sh
```

### Windows x64 PowerShell

```powershell
New-Item -ItemType Directory -Force .\webman-aot-install | Out-Null
tar.exe -xf .\webman-aot-0.1.0-windows-x86_64.zip -C .\webman-aot-install
powershell -ExecutionPolicy Bypass -File .\webman-aot-install\install.ps1
```

**安装到此结束。** 关闭并重新打开终端，执行 `webman-aot version`；
出现版本号表示开发电脑已装好工具。安装阶段不会编译 Webman 项目。

## 第 2 步：编译你的 Webman 项目

在开发电脑进入你自己的后端目录：该目录内应有 `webman` 和
`composer.json`。不要在工具安装包目录或本仓库源码目录执行。

```sh
webman-aot doctor
```

如果检查提示缺少编译组件，再执行：

```sh
webman-aot doctor --repair
webman-aot doctor
```

`doctor` 只检查；`doctor --repair` 才在当前用户目录下载并校验工具链，
首次运行可能比较久。检查通过后编译：

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
