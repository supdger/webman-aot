## Why

Composer发行包误将SaiAdmin生态当作工具归属。用户明确归属为supdger；必须统一包名、命名空间和状态所有权，并提供旧发行迁移入口。

## What Changes

- 发布supdger/webman-aot-builder 0.3.5轻量入口，PSR4改Supdger\WebmanAotInstaller，runtime继续0.3.2。
- 新setup严格接受新owner；旧schema1状态可经显式逐项确认卸载，不能自动adopt。
- 精确列新旧两个global package，同目录按package区分，仅删除确认的一项。先移除旧global再安装新包避免同名proxy碰撞。
- README/Wiki同步，历史tag/资产保持；旧Packagistrecord保留，正确新包公开验证后主Agent设置abandoned replacement迁移提示。

## Capabilities

### New Capabilities
- `composer-distribution`: 正确发行者身份及旧包安全迁移。

## Impact

仅Composer小包/共用卸载器相关namespace和元数据、测试及发行文档；不改业务SaiAdminprofile、编译器、原生runtime/version/latest或真实用户安装/PATH。
