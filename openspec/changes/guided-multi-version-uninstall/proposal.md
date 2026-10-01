## Why

使用者想清理多种安装方式的历史版本，但现有卸载器整删版本并恢复旧入口，无法逐项选择。Composer 入口与独立安装须从同一命令看清版本、位置、归属与实际删除结果。

## What Changes

- 新增 `webman-aot uninstall` 与只读 `--list`，只扫描已知管理根、显式管理根及 PATH 固定入口名。
- 列出当前、升级代次、回滚、安装备份、Composer 私有状态与全局包，逐项 `y/N/q`，默认保留；非终端及 EOF 保留。
- 严格归属与路径检查，无证据旧入口说明保留；不运行未知入口，不改 PATH，不删除项目成果或共享工具链。
- 独立卸载器与 Composer 共用引擎，Windows 从临时运行时同步执行，避免自删除占用；不再恢复旧入口。

## Capabilities

### New Capabilities

- `guided-uninstall`: 多来源安装发现、逐项确认、安全删除与可确认结果。

### Modified Capabilities

无。

## Impact

Composer 包入口、原生 launcher/卸载脚本、安装包制作与原生命令帮助；README 小入口与 Wiki 未发布草稿。用户已续授权发布v0.3.4轻量Composer入口、必要feature提交/PR合并、tag/非latestRelease/Packagist/Wiki同步；目标运行时0.3.2与native完整资产保持。不操作真实用户安装或PATH。
