## Context

独立包无原构建源码依赖；正式 0.3.2 完整包已经包含私有 PHP、组件、原安装器。见 proposal。

## Goals / Non-Goals

**Goals:** 私有状态默认与旧安装隔离，系统 PHP >=8.1 只启动入口，系统 curl/tar 或 PowerShell 执行平台操作。测试可通过 --state-dir 指定临时隔离目录。

**Non-Goals:** Packagist 发布、修改 0.3.2、编译缓存断点、真实全局安装覆盖。

## Decisions

- 固定资源锁记录正式完整包SHA、大小、平台、URL，禁止追 latest；下载及导入采用同一校验。
- 包解压前拒绝链接/路径穿越/重复条目；解压后核对package.json身份，原安装器验证payload清单。
- state/runtime 与 state/bin 属于桥接，使用绝对私有PHP路径调用原入口；Windows引导按原bootstrap保存cwd。
- 非TTY只用显式参数，交互恢复可扫描常规Downloads，输入路径优先；拒绝自动选择多个候选。

## Risks / Trade-offs

- Windows宿主不可用 → 保留目标实机验收未完成，增加可在Windows直接运行的行为回归。
- 完整包较大 → 缓存校验复用，下载实时原生进度，不把字节数当编译进度。
- 用户真实环境不可改 → 测试限定任务临时state/home。
