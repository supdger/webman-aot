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

直接使用请下载 [最新正式版安装包](https://github.com/supdger/webman-aot-builder/releases/latest)，
选择与你的**开发机**匹配的 macOS Apple Silicon 或 Windows x64 包。
轻量包首次使用时联网准备编译组件；完整包包含组件，可在安装时离线准备。
两种包安装后都提供 `webman-aot` 命令。GitHub 的 `Source code (zip)` 是源码压缩包，不是安装包。

## 安装与使用

下载、校验和安装请按 [安装与使用指南](https://github.com/supdger/webman-aot-builder/wiki/Install) 操作。
安装后可在任意目录运行以下命令，检查开发机并准备缺失的编译组件，健康时输出 `Result: healthy`：

```sh
webman-aot doctor
```

构建时进入**自己的 Webman 项目根目录**（包含 `composer.json`、`composer.lock`、`start.php` 和 `app/`），运行：

```sh
webman-aot build
webman-aot verify
```

`build` 生成 `dist-aot/`，`verify` 检查该目录的分发结构和静态属性。
SaiAdmin 项目使用 `webman-aot build --profile=saiadmin`，适用版本及要求见
[SaiAdmin 兼容说明](https://github.com/supdger/webman-aot-builder/wiki/SaiAdmin-Compatibility)。

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
