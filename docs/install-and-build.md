# 安装与构建

**目前尚无公开安装包。** 今后普通使用者从
[Releases](https://github.com/supdger/webman-aot/releases)
打开一个版本，展开 **Assets**，按开发电脑系统点击
`macos-arm64.tar.gz` 或 `windows-x86_64.zip` 文件下载。
如果页面没有这两个文件，就说明现在还不能按下面的步骤公开下载安装；
不要下载「Source code (zip)」冒充安装包。下面的步骤目前仅供已经拿到
测试安装包的人使用。
GitHub「Code → Download ZIP」得到的是源码，**不是下面命令使用的安装包**。
安装包按开发电脑系统选择：
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
macOS 在包所在目录执行
`shasum -a 256 webman-aot-0.1.0-dev-macos-arm64.tar.gz`，
Windows PowerShell 执行
`(Get-FileHash .\webman-aot-0.1.0-dev-windows-x86_64.zip -Algorithm SHA256).Hash`；
将结果与对应包公布的 SHA-256 对照。

## 第 1 步：在开发电脑安装工具

把收到的测试安装包放到一个目录，在那个目录打开终端。

### macOS Apple Silicon

```sh
mkdir webman-aot-install
tar -xzf webman-aot-0.1.0-dev-macos-arm64.tar.gz -C webman-aot-install
cd webman-aot-install
./install.sh
```

### Windows x64 PowerShell

```powershell
New-Item -ItemType Directory -Force .\webman-aot-install | Out-Null
tar.exe -xf .\webman-aot-0.1.0-dev-windows-x86_64.zip -C .\webman-aot-install
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
公开二进制安装包前还需核定第三方运行时的再分发许可；当前构建和运行
证据见[验证记录](verification.md)。这项发布审查不影响本地候选包的
已完成测试，但不能被测试结果替代。
