## Purpose

将全局 Composer 命令入口的源码和发行维护归入已有构建器仓库，保证安装仅获取所需轻量入口并保持源码与发行布局一致，同时明确已发布运行时的版本和首次使用边界。

## ADDED Requirements

### Requirement: Lightweight installation
系统 SHALL 提供 saiadmin/webman-aot-builder 的轻量 Composer 发行包，仅包含注册元数据、必要命令入口、PHP 源码、锁定资源信息及许可文档。

#### Scenario: Dist installation
- **WHEN** 使用者以 Composer 从发行 ZIP 安装
- **THEN** 命令代理和自动加载正常，平台完整资源在执行 setup 或实际命令前不被下载

### Requirement: Source and dist parity
系统 SHALL 在原仓库根提供包元数据，源码安装和轻量 ZIP 安装 MUST 使用相同的 bin 与 autoload 路径。

#### Scenario: Source fallback
- **WHEN** 使用者选择源码安装
- **THEN** 相同的 webman-aot 代理可运行 help/version，包的自动加载不依赖完整 AOT 发行文件

### Requirement: Version and release isolation
入口 SHALL 使用新仓库发行版本 0.3.3 并明确目标运行时仍为已验证 0.3.2；新补充发行 MUST 不取代已有完整安装包的 latest 入口。

#### Scenario: Existing user
- **WHEN** 已安装原 0.3.2 的使用者选择 Composer 入口
- **THEN** 可以用代理完整路径确认入口 0.3.3 与目标 0.3.2，不覆盖原安装且旧 latest setup 下载路径保持有效
