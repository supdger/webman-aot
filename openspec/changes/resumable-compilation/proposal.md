## Why

大型 Webman 项目编译中断后，目前重试会清空镜像并强制重编所有单元，已经完成的工作丢失。0.4.x 应让使用者从原入口重试，安全复用已成功的编译单元并重新链接、校验产物。

## What Changes

- 默认保留并复用完整、有输入及内容校验的对象缓存；损坏、未完成或输入变化自动重编。
- 同一项目的构建和清理互斥，阻止并发与残留子进程污染缓存。
- 提供显式 `build --fresh` 及引导中的全量重建选项；失败后说明重试方式和恢复范围。
- 为 native 与 Composer 后续 0.4.0 发行记录运行时绑定要求，当前不发布版本或改写旧发行地址。

## Capabilities

### New Capabilities

- `resumable-compilation`: 项目构建中断后的安全对象复用、互斥和恢复入口。

### Modified Capabilities

无。

## Impact

影响 Project 工作区与镜像、TypePHP 编译和锁定补丁、CLI 与 Guided 的恢复提示，以及 README 和现有 Wiki 的构建说明。只修改隔离分支；不触及正在编译的项目、旧安装、数据库或远程宿主，不提交或发布。
