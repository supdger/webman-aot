## Context

见 proposal.md。功能候选 f40c636a 已完成源码独立验收，公开版本目前是 Composer 0.3.7 与原生 0.3.2。组件派生工具可验证前驱归档并替换批准的源码与完整 SDK；Windows 发行辅助脚本仍硬编码早期组件下载 URL。

## Goals / Non-Goals

**Goals:** 真实派生并安装 0.4.0 运行时，Composer 使用实际完整包摘要，同一标签发布配套资源。

**Non-Goals:** 不重建未变化的编译驱动或 SDK ABI，不修改旧发行，不授权远程 Windows 宿主上传，不将市场维护扩大为数据库或业务部署。

## Decisions

- 使用已发布 0.3.2 组件作为批准前驱，验证每个输入文件。比较工具链时排除派生 provenance；组件输入和 SDK 字节仍逐项绑定，只有 manifest 批准源码可以替换。保留 0.3.2 已派生 SDK 为不可变依赖，因为0025仅改变 Translator 的对象缓存，不改变 PHPX ABI。
- 新锁从实际新组件归档与 manifest 生成；Composer 绑定从实际完整安装包结果与包身份生成，避免手填未知摘要。
- Mac 与 Windows 归档可在 Mac 使用正式 packager 制作。Windows 原生安装与执行通过已存在的固定 revision CI 辅助入口单独验证；CLI、物理机与源码检查证据不混用。
- 在组件与安装包阶段保留可监控状态与结果 sidecar；新源码冻结后交独立验收，再按 root 协调提交、发布与公开消费者复测。

## Risks / Trade-offs

- [发行包较大，磁盘与下载耗时] → 使用任务私有目录，核验已有材料复用，及时显示阶段；仅清理本任务临时资源。
- [新组件尚未公开而源码包装需要下载] → 包装工具支持经当前锁核验的本地组件参数，发布后仍使用同版不可变 URL。
- [Windows 物理机未授权] → 优先真实 Windows CI 安装门槛，公开说明实际覆盖；禁止以本机归档或静态检查替代。

## Migration Plan

更新统一版本与实际资源锁；生成双平台组件、small/full/setup/Composer；私有安装与原入口恢复验收；新冻结交独立审核；提交 PR 并合并；同版公开发行；同步 Packagist/Wiki/市场并从公开入口安装复测。回退可安装旧不可变发行，项目源码与 durable checkpoint 不由发行升级删除。
