# Webman AOT

在 macOS 或 Windows 开发机上，把 Webman / SaiAdmin 项目编译成可部署到
Linux amd64 的全静态程序。目标项目不用安装 AOT Composer 插件，构建时不用 Docker。

![同一份项目源码从 Mac 或 Windows 编译成 Linux 全静态发布目录](docs/assets/build-flow.svg)

它会自动发现项目及已安装插件的业务 PHP、在隔离目录生成必要的 AOT 适配，
并在发布前检查漏编译与包完整性。普通 PHP 源码保持不变；目标服务器不需要
安装 PHP 或 Docker。

> **预览状态：安装包尚未公开发布。** 目前可以[查看和下载源码](https://github.com/supdger/webman-aot/archive/refs/heads/main.zip)，但源码 ZIP 不含私有 PHP 运行时，不能直接当作安装包使用。请勿把下面的候选包安装步骤理解成已有公开下载地址；安装包完成第三方许可核查后才会放到 [Releases](https://github.com/supdger/webman-aot/releases)。

## 下载与安装

选择**开发电脑**对应的安装包；两种安装包生成的目标都是 Linux amd64 程序，
Windows 包不会生成 Windows exe。

- macOS Apple Silicon：`webman-aot-0.1.0-dev-macos-arm64.tar.gz`
- Windows x64：`webman-aot-0.1.0-dev-windows-x86_64.zip`

拿到候选包后，在包含该文件的目录执行：

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

重新打开终端，然后运行 `webman-aot version` 确认命令可用。安装器只写入当前
用户目录；缺少的编译组件由下述显式修复命令下载和校验。详细说明见
[安装与构建](docs/install-and-build.md)。

## 编译 Webman / SaiAdmin

进入**项目后端根目录**（有 `webman` 和 `composer.json` 的目录）：

```sh
webman-aot doctor
webman-aot doctor --repair  # doctor 提示缺组件时才执行
webman-aot build
webman-aot verify
```

`build` 自动识别普通 Webman 和 SaiAdmin。需要明确指定时可运行
`webman-aot build --profile=saiadmin`。未知插件代码或依赖结构不兼容时会报错，
不会静默跳过业务 PHP。构建结果位于当前项目的 `dist-aot/`。

已实际验证的组合是 SaiAdmin 6.1.5、Webman 2.2.4、TypePHP 0.9.2 和锁定的
PHP 8.4 工具链；其他依赖版本和插件需要重新验证。SaiAdmin 的普通 PHP 8.4
注意事项见[兼容与迁移](docs/saiadmin-compatibility.md)。

## 部署到 Linux

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
