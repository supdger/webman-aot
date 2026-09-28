# Webman AOT Builder

在 macOS Apple Silicon 或 Windows x64 开发机上，把 Webman / SaiAdmin 项目编译成面向
Linux amd64 的全静态程序。工具安装在开发机上，无需装进 Webman 项目，也无需 Docker。

## 开始使用

**v0.2.3 的下载、SHA-256 校验、安装、组件准备、构建、验证与卸载，
请按 [完整安装与使用指南](https://github.com/supdger/webman-aot-builder/wiki/Install) 操作。**
安装包见 [v0.2.3 Release](https://github.com/supdger/webman-aot-builder/releases/tag/v0.2.3)；
GitHub 自动生成的源码 ZIP 不是安装包。

选择与你的**开发机**匹配的 macOS Apple Silicon 或 Windows x64 安装包。
轻量包首次使用时需联网下载并准备编译组件；完整包已包含组件，可在安装时离线准备。
两种包安装后使用相同的 `webman-aot` 命令。
`doctor` 在任意目录检查开发机并准备组件；`build` 须在自己的 Webman 项目根目录运行。
`verify` 默认检查当前目录的 `dist-aot/`，也可用 `--path` 指定分发目录。

安装或升级后请按上方 v0.2.3 指南确认实际调用的命令位置，版本应显示 `webman-aot 0.2.3`。
旧版不再推荐使用；[Release 历史](https://github.com/supdger/webman-aot-builder/releases)仅供查询历史版本。

## 文档

- [Wiki 首页](https://github.com/supdger/webman-aot-builder/wiki/Home)：全部使用与维护文档
- [SaiAdmin 兼容说明](https://github.com/supdger/webman-aot-builder/wiki/SaiAdmin-Compatibility)：适用范围与构建要求
- [Linux 目标机验收](https://github.com/supdger/webman-aot-builder/wiki/Linux-Acceptance)：部署后验证启动、数据库与业务接口
- [验证记录](https://github.com/supdger/webman-aot-builder/wiki/Verification)：已测试的组合与证据边界

## 源码构建

在 Windows PowerShell 或 macOS Terminal 中，用 [v0.2.3 工具源码](https://github.com/supdger/webman-aot-builder/tree/v0.2.3)
和锁定的运行时输入制作安装包，完整步骤见 [当前源码构建指南](https://github.com/supdger/webman-aot-builder/wiki/Build-from-source)。
最新开发源码见 [main](https://github.com/supdger/webman-aot-builder/tree/main)；上述步骤固定使用 v0.2.3。

## 源码与许可

本仓库是工具源码；[v0.2.0 历史构包记录](https://github.com/supdger/webman-aot-builder/wiki/Build-from-source-0.2.0)
供维护者查阅。原创代码采用 [MIT 许可证](LICENSE)，第三方组件的许可与归属见
[NOTICE](NOTICE.md)。问题可提交到 [Issues](https://github.com/supdger/webman-aot-builder/issues)。
