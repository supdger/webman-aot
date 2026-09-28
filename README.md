# Webman AOT Builder

在 macOS Apple Silicon 或 Windows x64 开发机上，把 Webman / SaiAdmin 项目编译成面向 Linux amd64 的全静态程序。工具安装在开发机上，无需装进 Webman 项目，也无需 Docker。

## 当前开发源码：单入口引导流程

本仓库当前开发源码新增了面向用户的引导入口：macOS 使用 `build.command`，Windows 使用 `build.cmd`。它会按提示选择轻量包或完整包，显示安装位置和 PATH 影响后再确认安装，然后可选择 Webman / SaiAdmin 项目目录；工具会依次构建并验证 `dist-aot/`。过程显示当前阶段、日志位置和失败原因，日志只保存在本机；无法解决时可把错误摘要提交到 [Issues](https://github.com/supdger/webman-aot-builder/issues)。

独立安装入口由已校验的同平台安装包生成：macOS setup ZIP 解开后运行其中的 `.command` 文件，Windows 运行 `.cmd` 文件。选好包类型后，setup 会自动下载对应安装包、核验大小和 SHA-256、检查归档后解压，再进入同一安装和项目引导流程。轻量包首次构建时需要联网下载锁定组件；完整包可离线准备组件。

**以上引导入口属于当前开发源码及其新构建产物，尚未包含在公开 v0.2.3 Release 中。** 公开下载和 v0.2.3 的原有安装、命令行使用方式仍见 [v0.2.3 安装与使用指南](https://github.com/supdger/webman-aot-builder/wiki/Install)。当前开发源码版本号仍是 `0.2.3`，版本号相同不表示开发快照就是该 Release 的资产。

引导流程中的 `verify` 检查构建产物的结构、完整性及工具报告的静态属性，不代替 Linux 目标机上的启动、数据库和业务接口验收。

## 公开 v0.2.3 命令行流程

**v0.2.3 的下载、SHA-256 校验、安装、组件准备、构建、验证与卸载，请按 [完整安装与使用指南](https://github.com/supdger/webman-aot-builder/wiki/Install) 操作。** 安装包见 [v0.2.3 Release](https://github.com/supdger/webman-aot-builder/releases/tag/v0.2.3)；GitHub 自动生成的源码 ZIP 不是安装包。

选择与你的**开发机**匹配的 macOS Apple Silicon 或 Windows x64 安装包。轻量包首次使用时需联网下载并准备编译组件；完整包已包含组件，可在安装时离线准备。两种包安装后使用相同的 `webman-aot` 命令。`doctor` 在任意目录检查开发机并准备组件；`build` 须在自己的 Webman 项目根目录运行；`verify` 默认检查当前目录的 `dist-aot/`，也可用 `--path` 指定分发目录。

安装或升级后请按 v0.2.3 指南确认实际调用的命令位置，版本应显示 `webman-aot 0.2.3`。旧版不再推荐使用；[Release 历史](https://github.com/supdger/webman-aot-builder/releases)仅供查询历史版本。

## 文档

- [Wiki 首页](https://github.com/supdger/webman-aot-builder/wiki/Home)：全部使用与维护文档
- [SaiAdmin 兼容说明](https://github.com/supdger/webman-aot-builder/wiki/SaiAdmin-Compatibility)：适用范围与构建要求
- [Linux 目标机验收](https://github.com/supdger/webman-aot-builder/wiki/Linux-Acceptance)：部署后验证启动、数据库与业务接口
- [验证记录](https://github.com/supdger/webman-aot-builder/wiki/Verification)：已测试的组合与证据边界

## 源码与许可

本仓库是工具源码；[v0.2.3 源码构建指南](https://github.com/supdger/webman-aot-builder/wiki/Build-from-source)说明公开版本的历史流程。原创代码采用 [MIT 许可证](LICENSE)，第三方组件的许可与归属见 [NOTICE](NOTICE.md)。问题可提交到 [Issues](https://github.com/supdger/webman-aot-builder/issues)。
