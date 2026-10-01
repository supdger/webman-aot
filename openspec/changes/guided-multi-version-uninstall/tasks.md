## 1. 安装发现与交互
- [x] 1.1 实现统一只读发现、静态版本、路径去重与逐项默认保留。
- [x] 1.2 实现严格对象删除、活动入口撤销、Composer精确remove与失败提示。

## 2. 产品入口
- [x] 2.1 接入Composer/native命令及两平台卸载器、打包所需文件，Windows同步临时runtime。
- [x] 2.2 受影响README/CHANGELOG与Wiki草稿，明确未发布能力。

## 3. 验证
- [x] 3.1 隔离temp测试保留/卸载/EOF/非TTY/残留/同目录版本/中文空格/去重/所有权/失败；既有回归。
- [x] 3.2 原生Mac实际入口与Windows目标平台检查，区分未运行缺口；规格validate与独立审查返工。

## Evidence

起点本地 origin/main d365363，干净独立 worktree。远端实时核对DNS失败，不以本地引用冒充实时public验证。本机无pwsh；Windows原生检查待提供受限执行环境。

本轮实际证据：
- PHP 8.4/macOS 隔离 temp 行为测试 `php packages/composer-installer/tests/uninstall.php`：24 checks 通过；含0.1.2直接app/runtime备份布局、静态模板归属、符号链接、Composer失败与其他包保留。既有 Composer `tests/run.php` 18项通过。
- 真实 native Mac launcher PTY：中文空格InstallRoot+自定义BinDir未入PATH，锁定原生PHP，`--list`只读；y实删current+自身launcher，q保留旧generation/.previous/toolchains；最终重新列2项残留。未知PHP hash与linked ancestor拒执行。日志 `/private/tmp/aot-native-uninstall-pty-20261001.log`，fixture与临时副本已清理。测试首次发现cwd防护过宽后定点修复，最终实跑通过；不以先前失败当通过。
- 实际Composer候选ZIP 11 source-identical成员25,153 bytes，路径 `/private/tmp/webman-aot-uninstall-composer-candidate-20261001-r2/webman-aot-builder-0.3.3-composer.zip`，含引擎/resource；worktree `.git`误入归档已修并复测。包名沿用现源码0.3.3仅本地候选，未覆盖公开发行。
- Native app打包stage实测5个受影响成员与源码摘要一致，位于 `/private/tmp/webman-aot-uninstall-native-app-stage-20261001`；未重建完整原生安装包。
- `composer validate --strict`、PHP lint、shell语法、`git diff --check`及OpenSpec validate通过；Wiki未发布草稿 `/private/tmp/webman-aot-uninstall-wiki-draft-20261001` 20页/105本地目标0errors，既有2页未直接Home/sidebar链接警告、49外链及1anchor未验证。仅Install/Upgrade-Uninstall修改。
- Windows没有本机pwsh，原生CMD/PowerShell真实执行及PHP8.1尚未验证，不使用Mac证据替代；独立审查进行中，任务3.2保留未勾选。
- 源码feature分支仅修改本独立worktree，无提交、推送、tag、Release、真实安装/PATH改动或Wiki发布。

- 增补真实Composer全局自卸载：独立temp中文globalhome，本地path镜像安装本包和fixture/other-tool，禁用Packagist网络；真vendor/bin/webman-aot入口PTY y确认，Composer实际remove本包及proxy、其他包/proxy保留，源码自删除后remaining0仍成功，退出0。日志 `/private/tmp/aot-real-composer-uninstall-20261001.log`，tempglobal/fixture已清理。此证据区别于前述失败分支的CLI模拟。

最终候选补修与独立判定：
- 同范围后续审查确认并修复两个must：Mac归一化误改真实POSIX路径；Composer状态解锁后删除setup.lock会允许并发setup换锁。仅Windows转换分隔符/trim外壳，Mac保留反斜杠、尾空格、引号字节；Composer状态保留原锁inode/根并解释残留。
- 最终长期回归30/30通过（此前24项为初稿证据）；真实Mac native PTY与真实Composer global self-remove在新引擎下重新实跑通过，fixture均清理。
- 独立review最终补判PASS：长期30/30亲跑，自有9项path/lock碰撞及跨进程锁fixture通过；前轮独立Mac native12checks与真实ZIP安装后的Composer自卸载通过。后续日志 `/private/tmp/webman-aot-independent-path-lock-review-20261001.log`；原生日志 `/private/tmp/webman-aot-independent-native-review-20261001.log`，Composer日志 `/private/tmp/webman-aot-independent-composer-selfremove-20261001.log`。无剩余must。
- 新最终候选Composer ZIP r3 11source-identical成员25,314bytes：`/private/tmp/webman-aot-uninstall-composer-candidate-20261001-r3/webman-aot-builder-0.3.3-composer.zip`。独立生产native staging ZIP新r2 137files/4新增payload与源码一致（完整原生包未重建）。r2旧ZIP已被新候选证据替代，不用其25,153bytes作为最终候选数据。
- Windows原生执行仍未验证、PHP8.1仍未跑，任务3.2因为目标平台未完成保留未勾；本地source/Mac/Composer切片实现与独立验收完成。公开安装仍为既有0.3.2/0.3.3，不包含本能力。


## 4. v0.3.4轻量公开发行（续授权）

用户原话：“发布，我要清理干净后实施composer包”。范围：必要同功能修复、feature commit/push/PR合并至原仓库main、新v0.3.4轻量tag/Release/资产/索引及Wiki；保留native v0.3.2 latest与完整资源。不操作真实用户安装、删除或PATH。旧3.2Windows原生缺口不转嫁为本次full发行通过。

- [x] 4.1 版本metadata、CHANGELOG/README与Wiki清理流程同步，保持runtime0.3.2。
- [x] 4.2 必要检查、source一致11文件ZIP、真实隔离localZIP安装/help/version/list/逐项清旧版本保留Composer与自卸载验收。
- [x] 4.3 prepare-for-launch发布评审与固定候选独立验收，commit/push/PR并附task artifact。
- [x] 4.4 获独立发行验收后合并、精确tag/非latestRelease、资产与SHA256SUMS公开，核对source/index/dist引用。
- [x] 4.5 Wiki发布、官方Packagist默认globalrequire^0.3.4与代理公开验证，保持旧3.3和native3.2资产不可变。

prepare-for-launch进度（同本任务唯一记录）:
- [x] 0. 范围与风险
- [x] 1. 质量验收（跑检查）
- [x] 2. 提测/准出
- [x] 3. 预发验证清单
- [x] 4. 发布评审一页纸
- [x] 5. 物料与合规 checklist
- [x] 6. 灰度方案
- [x] 7. 优化风险速扫
- [ ] 8. 结论


v0.3.4发布候选证据（替代前述0.3.3开发包的发行材料）:
- 固定bridge0.3.4、runtime0.3.2，原生完整包不重发；composer validate --strict、既有18项与卸载30项、diff check通过。
- 实际source一致ZIP 11成员25,376bytes，SHA256 `2e08d0b72aef6e02f3378ec68964f0d58764b30718bc09a91d2551006defbb82`，`/private/tmp/webman-aot-release-034-candidate-20261001/webman-aot-builder-0.3.4-composer.zip`。
- 真Composer消费该ZIP（非path源码）2.03秒通过：help/version/list不prepare，PTY卸载旧0.1.2及绑定旧入口、保留全局Composer，重新列剩余；再PTY真self-remove精确包、其他global工具保留，收尾remaining0正常。日志`/private/tmp/aot-034-localzip-consumer-20261001.log`；隔离temp已清理。
- Wiki受影响2页已准备0.3.4清理后用Composer流程，20页107本地目标0error；既有2历史页未直链警告，49外链未验证。尚未Wiki push。
- public主仓main已实时CLI核对d365363；0.3.4尚无tag。Windows SSH确认连接，但TEMP源码/包上传被auto_review拒绝特定物理机export授权；主Agent正在请求该具体许可，不换入口绕过。Windows实跑依赖此许可，发行结论由独立review最终判定。
