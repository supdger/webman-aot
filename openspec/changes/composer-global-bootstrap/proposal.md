## Why

开发者希望通过 Composer 全局命令构建 Webman 项目，当前源码并非 Composer 包且依赖私有运行时。增加独立入口，保留原 0.3.2 发行包和安装路径。

## What Changes

- 新增 saiadmin/webman-aot-builder 轻量包和 webman-aot 全局命令。
- 首次运行准备固定 0.3.2 完整包，网络失败提供本地导入；验证包摘要和身份。
- 私有隔离安装，不改变原安装目录及 PATH；保留 cwd/argv/退出码。
- 非交互缺资源不等待输入，help/version 不联网。

## Capabilities

### New Capabilities
- `composer-bootstrap`: Composer 全局入口、固定发行资源准备、离线恢复与命令转发。

### Modified Capabilities

无。

## Impact

仅本独立包目录；依赖系统 PHP、curl、tar 或 Windows PowerShell。无 Composer 插件/安装脚本，无发布和编译断点续跑。
