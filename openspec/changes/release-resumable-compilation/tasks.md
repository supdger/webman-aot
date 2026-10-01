## 1. 配套源码与组件

- [x] 1.1 统一 0.4.0 版本、修复前驱派生与本地组件入口，并通过锁验证、补丁及受影响辅助脚本检查。
- [x] 1.2 从锁定前驱实际制作双平台 0.4.0 组件，从实际 manifest/归档生成组件锁并验证新增0025进入包内。

## 2. 安装与消费者

- [ ] 2.1 实际制作双平台 small/full/setup 安装资源，核 payload 与版本身份，私有安装并从原入口运行恢复回归；分别记录 Windows 实际执行缺口。
- [x] 2.2 从完整安装包实际结果生成 Composer 0.4.0 绑定并制作小 ZIP，通过 bridge/plugin 首次安装菜单与旧包升级回归。
- [ ] 2.3 同步受影响 README/Wiki 草稿和发行说明，冻结新源码/资产清单并完成独立发行验收。

## 3. 公开发行

- [ ] 3.1 按已授权范围提交、PR、合并与创建 0.4.0 发行，公开下载后核全部资产摘要。
- [ ] 3.2 同步 Packagist、Wiki 和 Webman 市场，通过公开消费者安装与版本/续编入口核验，记录未测平台与业务边界。

## 当前证据

2026-10-01：正式派生工具逐文件校验不可变0.3.2双平台前驱，在新任务目录制作0.4.0组件；新增0025 Translator与30项补丁manifest一致，既有032 derived SDK继续作为ABI不变的锁定输入。Mac 7627项、260126486字节，Windows 7696项、337452782字节。`tools/lock-release-artifacts.php components`逐文件核验两ZIP并生成040组件锁，未手填未知摘要。

源码窄回归：工具链锁、workspace并发/清理/旧writer隔离、Guided27项、ProcessOutput、Composer bridge19项/guide18项、Composer strict metadata、diff空白检查通过。本阶段NativeBuildRevision冻结时Composer资源绑定仍等待实际新full包结果；不会发布该中间提交。随后仅追加真实绑定元数据，记录NativeBuildRevision与最终tag源码revision，并逐项核原生payload未变。

所有新发行材料与执行日志位于 `/private/tmp/webman-aot-release-040`。Windows真实安装/续编须新固定revision CI或实际Windows证据，当前制作归档不代表该层通过。

NativeBuildRevision `f3609c91bdcbb4b51b7edd88aa16ffa51c6ae8ba`：干净独立源码制作双平台small/full包与setup，Mac实际解包安装确认0.4.0；全新完整包安装的五轮真实109单元原入口回归，复用计数0/109、109/109、107/109、108/109、fresh0/109，结果 `/private/tmp/webman-aot-release-040/author-native/results.json`。

Composer040绑定来自实际full打包结果，永久工具核归档身份/大小/摘要后生成，不手填预估值。真实隔离Composer PTY34项通过，涵盖首次官方信任、bare global require、重复require、旧036升级与无人模式。帮助文案随后只更新恢复说明，直接bridge20项/guide18项/卸载37项回归通过；最终小ZIP13项源字节一致。新源码141项原生app映射记录逐项匹配已固定包与NativeBuildRevision，binding/help/文档/CI测试追加不改变原生payload。Windows新增父进程硬终止、partial恢复与并发拒绝helper已接真实消费者；Mac无PowerShell执行环境，其实际解析和执行门槛留给固定revision Windows CI。
