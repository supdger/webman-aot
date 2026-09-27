# Webman AOT Builder

在 macOS Apple Silicon 或 Windows x64 开发机上，把 Webman / SaiAdmin 项目编译成面向
Linux amd64 的全静态程序。工具安装在开发机上，无需装进 Webman 项目，也无需 Docker。

## 开始使用

**v0.2.1 的下载、SHA-256 校验、安装、项目目录选择、组件准备、构建、验证与卸载，
请按 [完整安装与使用指南](https://github.com/supdger/webman-aot-builder/wiki/Install) 操作。**
安装包见 [v0.2.1 Release](https://github.com/supdger/webman-aot-builder/releases/tag/v0.2.1)；
GitHub 自动生成的源码 ZIP 不是安装包。

选择与你的**开发机**匹配的 macOS Apple Silicon 或 Windows x64 安装包。
轻量包首次使用时需联网下载并准备编译组件；完整包已包含组件，可在安装时离线准备。
两种包安装后使用相同的 `webman-aot` 命令。

如果使用 **v0.2.0**，请看 [v0.2.0 安装指南](https://github.com/supdger/webman-aot-builder/wiki/Install-0.2.0)；
该版本的命令是 `webman-aot-builder`。安装 v0.2.1 后应按新版指南确认实际调用的
命令位置与版本，避免旧命令路径抢先。

## 文档

- [Wiki 首页](https://github.com/supdger/webman-aot-builder/wiki/Home)：全部使用与维护文档
- [SaiAdmin 兼容说明](https://github.com/supdger/webman-aot-builder/wiki/SaiAdmin-Compatibility)：适用范围与构建要求
- [Linux 目标机验收](https://github.com/supdger/webman-aot-builder/wiki/Linux-Acceptance)：部署后验证启动、数据库与业务接口
- [验证记录](https://github.com/supdger/webman-aot-builder/wiki/Verification)：已测试的组合与证据边界

## 源码与许可

本仓库是工具源码；[从源码制作安装包](https://github.com/supdger/webman-aot-builder/wiki/Build-from-source)
供维护者使用。原创代码采用 [MIT 许可证](LICENSE)，第三方组件的许可与归属见
[NOTICE](NOTICE.md)。问题可提交到 [Issues](https://github.com/supdger/webman-aot-builder/issues)。
