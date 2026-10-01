## 1. 契约与实现

- [x] 1.1 完成恢复规格与隔离设计，通过 OpenSpec 严格校验。
- [x] 1.2 增加原子摘要对象 checkpoint 的锁定 TypePHP 补丁，验证补丁流水线、正常命中与损坏/输入变化失效。
- [x] 1.3 实现独立 attempt、项目互斥及真实工具链身份，验证并发拒绝和父死后旧 attempt 隔离。
- [x] 1.4 接入默认恢复及 `--fresh`，修改引导恢复选择和帮助，验证原入口与退出码。

## 2. 集成与交付

- [x] 2.1 从候选原入口运行真实 Webman 多单元中断恢复、源码/依赖变化、损坏与并发回归，记录平台证据和未运行门槛。
- [x] 2.2 更新受影响 README 与仓库外 Wiki 草稿，审查质量及 diff，冻结未提交候选并交独立验收。

## 验收证据与限制

源码候选基于公开 main `69b65084`，分支 `codex/resumable-compilation`。原生版本标签与公开资源锁保持 0.3.2，仅作为未发布 0.4.x 功能候选；未提交、推送、发布或同步运行项目。公开 Composer 0.3.7 继续绑定公开 0.3.2。

- 原入口：`WEBMAN_AOT_BUILDER_HOME=/private/tmp/webman-aot-resume-evidence/home /private/tmp/webman-aot-resume-evidence/bin/webman-aot build`，项目为该任务的全新私有 fixture。候选 current/app 完整复制本分支 src，TypePHP 源为正式 0.3.2 prepared 私有副本加新增0025；实际内容 manifest/derivation 在私有副本重生。不是新发行包安装证据。
- TypePHP/PHPX 锁定 pristine archive 经 `tools/apply-typephp-patches.php` 完整 25 补丁验证 30 项 after digest，再运行返回 already-applied-and-verified。新增补丁未改变 SDK ABI；正式032的已有0024源、SDK配套摘要已核实。
- 真实109单元：首次0复用；不改输入重试109复用，编译和链接约3秒；最终ELF SHA256 `6ed6a272f74d2a3afd470c16213f78644e5945954804f7df063385df3af45708` 一致。
- 第5完成事件后SIGKILL父，实际旧进程组仍存活；该任务旧进程组随后终止，默认重试复用8个完整单元并重编剩余101个。原始日志 `/private/tmp/webman-aot-resume-evidence/actual-parent-killed.log`、`actual-parent-resumed.log`。
- 损坏对象与截断完成记录复用107/109；控制器语义变更108/109；fresh0/109；语法错误exit70不发布，修复后109/109。日志与计数 `/private/tmp/webman-aot-resume-evidence/regression-native-reports.json`。
- `tests/resumable-workspace.php` 实际文件及子进程验证构建/清理互斥、旧attempt写者与新attempt隔离、外项目拒绝及编译器/header/SDK配置/flags字节变化失效。`tools/test-guided.php`27项通过，含重试与fresh菜单；既有project-mirror、process-output回归通过。
- 新增可维护真实入口 `tests/resumable-native.php` 要求候选私有home、公有launcher、Composer-ready无数据库fixture和新证据目录，自动复制fixture并覆盖复用、损坏、源码失效、fresh。最终源码该入口首轮0/109已通过，完整循环日志持续在 `/private/tmp/webman-aot-resume-evidence/permanent-native`。
- 成功后只清理本attempt，独立`verify --json`仍通过：17文件、direct52/shadow6；失败attempt及checkpoint保留。旧attempt不是恢复输入；未完成单元不能内部续跑。
- 质量扫描0失败；native测试分支计数仅为多个明确行为/前置检查的人工复核提示，未引入重复产品分支。
- Wiki仅仓库外草稿 `/private/tmp/webman-aot-resume-docs-20261001`，未发布。Windows真实硬退出恢复、PHP8.1原生链、0.4.0双平台组件制作、native/Composer运行时绑定和安装验收仍是后续发行门槛；当前受控私有manifest更新不能替代打包工具生成新发行资源。
