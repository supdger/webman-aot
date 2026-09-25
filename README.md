# Webman AOT

在 macOS 或 Windows 开发机上，把 Webman / SaiAdmin 项目编译成可部署到
Linux amd64 的全静态程序。目标项目不用安装 AOT Composer 插件，构建时不用 Docker。

![同一份项目源码从 Mac 或 Windows 编译成 Linux 全静态发布目录](docs/assets/build-flow.svg)

图里的“项目源码”指**你自己的 Webman / SaiAdmin 后端项目**，不是本仓库的
源码 ZIP。使用 `webman-aot build` 前，必须先在开发电脑安装好工具。

它会自动发现项目及已安装插件的业务 PHP、在隔离目录生成必要的 AOT 适配，
并在发布前检查漏编译与包完整性。普通 PHP 源码保持不变；目标服务器不需要
安装 PHP 或 Docker。

## 先选你的路线

**只想用工具编译项目？** 不必下载源码。到
[Releases 安装包页面](https://github.com/supdger/webman-aot/releases)，按**开发电脑**
的系统下载 Mac 或 Windows 安装包，再从下面的「第 1 步」开始。
目前该页面尚无公开安装包，因而新用户暂时无法完成这条路线；
这里列出文件名是为了说明将来的选择，**不是说文件已经能下载**。

**想查看或修改工具、自己制作安装包？** 下载本仓库源码，按
[从源码制作安装包](docs/build-installers.md)准备锁定的第三方运行时并运行
打包脚本。源码 ZIP 不含这些运行时，不能直接当安装包执行。

从头到尾是这条关系：

```text
本仓库源码 + 锁定的第三方运行时 → Mac/Windows 工具安装包
工具安装包 → 在开发电脑安装 webman-aot 命令
webman-aot 命令 + 你的 Webman 项目 → Linux dist-aot/ 发布目录
```

## 这三个东西分别是什么

1. GitHub「Code → Download ZIP」下载的是**Webman AOT 工具的源代码**：给开发者查看和修改，**不是安装包，解压后不能直接使用工具**。
2. `webman-aot-...-macos-arm64.tar.gz` 或 `webman-aot-...-windows-x86_64.zip` 是**工具安装包**：按你的开发电脑系统选一个，只安装一次。本文下面的安装命令用的是这个文件。
3. `dist-aot/` 是**编译结果**：安装工具后，在你的 Webman 项目里运行 `webman-aot build` 才会生成；它要复制到 Linux 服务器运行。

当前说明供已经拿到测试安装包的人使用；不要把
[源码 ZIP](https://github.com/supdger/webman-aot/archive/refs/heads/main.zip)
当作测试安装包。

## 第 1 步：在开发电脑安装工具（只做一次）

看**开发电脑**的系统选择安装包，不是看 Linux 服务器的系统。Mac 和
Windows 安装包最终都编译出 Linux amd64 程序；Windows 包不会生成 Windows exe。

- macOS Apple Silicon：`webman-aot-0.1.0-dev-macos-arm64.tar.gz`
- Windows x64：`webman-aot-0.1.0-dev-windows-x86_64.zip`

在 Releases 页面找到与你的**开发电脑**系统相符的文件，点击文件名下载；
如果页面还没有文件，就先不要执行后面的安装命令。已经拿到测试包的人，
把它放到一个容易找到的目录，在该目录打开终端，运行对应命令：

```sh
# macOS
mkdir webman-aot-install
tar -xzf webman-aot-0.1.0-dev-macos-arm64.tar.gz -C webman-aot-install
./webman-aot-install/install.sh
```

```powershell
# Windows PowerShell
New-Item -ItemType Directory -Force .\webman-aot-install | Out-Null
tar.exe -xf .\webman-aot-0.1.0-dev-windows-x86_64.zip -C .\webman-aot-install
powershell -ExecutionPolicy Bypass -File .\webman-aot-install\install.ps1
```

安装脚本结束后，**关闭并重新打开终端**，运行：

```sh
webman-aot version
```

能显示版本号，就表示**工具已经装到开发电脑上**。此时还没有编译任何项目，
也没有生成 `dist-aot/`。安装器只写入当前用户目录，不安装系统级 PHP。
安装包摘要和补充说明见[安装说明](docs/install-and-build.md)。

## 第 2 步：用工具编译你的项目（每个项目执行）

在开发电脑上，进入**你自己的 Webman 后端目录**，即同时能看到
`webman` 和 `composer.json` 的目录；**不是刚解压的安装包目录，
也不是本工具的源码目录**。

先检查环境：

```sh
webman-aot doctor
```

如果提示缺少编译组件，再运行以下两条；`--repair` 会下载并校验组件，
首次运行可能比较久。检查通过时直接跳过：

```sh
webman-aot doctor --repair
webman-aot doctor
```

最后编译并检查产物：

```sh
webman-aot build --profile=saiadmin
webman-aot verify
```

普通 Webman 项目把编译命令改为 `webman-aot build`；SaiAdmin 也可以自动识别。
未知插件代码或依赖结构不兼容时会报错，不会静默跳过业务 PHP。
成功后，**当前 Webman 项目目录**下才会出现 `dist-aot/`。

已实际验证的组合是 SaiAdmin 6.1.5、Webman 2.2.4、TypePHP 0.9.2 和锁定的
PHP 8.4 工具链；其他依赖版本和插件需要重新验证。SaiAdmin 的普通 PHP 8.4
注意事项见[兼容与迁移](docs/saiadmin-compatibility.md)。

## 第 3 步：把编译结果放到 Linux 服务器（每次发布执行）

把**整个** `dist-aot/` 目录复制到 Linux amd64 服务器。在部署目录配置外置
`.env`（数据库等环境配置不用重新编译），然后从该目录启动：

```sh
cd dist-aot
./start.sh
```

后台运行可用 `./start.sh --daemon`；停机用 `./stop.sh`。不要只复制 ELF、
不要把开发机的私有 `.env` 或历史上传/导出数据打进发布包。上线前按
[Linux 验收步骤](docs/linux-acceptance.md)检查静态链接、启动和业务接口。
目标机不需要 PHP 或 Docker；“全静态”不等于所有未测试系统都已通过业务验收。

## 验证记录

[查看编译、跨 Mac/Windows 一致性及 Linux 运行证据](docs/verification.md)。
这些记录可在 GitHub 上单独查看，不包含在 `main` 的源码下载 ZIP 中。
问题反馈请使用 [Issues](https://github.com/supdger/webman-aot/issues)。

本项目原创代码使用 [MIT 许可证](LICENSE)；TypePHP 补丁及第三方组件保留
各自许可，详见[归属说明](NOTICE.md)。
