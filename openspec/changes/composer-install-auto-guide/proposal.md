## Why

用户实际运行普通 `composer global require supdger/webman-aot-builder`，0.3.6 只安装入口，没有自动显示下一步菜单。用户已接受 Composer 插件首次信任确认，要求安装后直接进入已有引导。

## What Changes

- 0.3.7 将同一包改为可选 Composer 插件，Composer 2.5.3+ 自行管理首次 allow-plugins 信任。
- 仅交互终端中明确单独全局 require 本包，安装和自动加载完成后打开原有 guide。首次、升级、无变更重复安装均适用。
- 其他命令、局部项目、无人环境、禁用插件/脚本、仅更新锁与失败安装不自动引导；普通 bin 仍可使用。
- README 与现有 Wiki 简化为一条普通安装命令，说明首次信任、资源选择、项目路径与独立结果。

## Capabilities

### New Capabilities
- `composer-install-guide`: 全局包安装完成后自动衔接已有交互引导。

### Modified Capabilities

无；保留原手动 guide、资源校验与卸载能力。

## Impact

仅轻量 Composer 包及元数据、直接相关测试和文档。运行时和 native latest 继续 0.3.2，既有标签和资产不改。全局插件会被 Composer 加载，但入口早期判定只允许本次目标动作；不写 root scripts、PATH 或预置信任。
