## 1. 归属与迁移
- [x] 1.1 更正metadata/namespace/owner/help/version，保留SaiAdmin业务适配与历史资源。
- [x] 1.2 两个精确package逐项列表/删除、状态owner双重校验、不adopt旧状态。
- [x] 1.3 迁移后lock-only根真实claim与localZIP移除旧global/新proxy清理旧state、self-remove验证。

## 2. 发行
- [x] 2.1 README/Wiki/CHANGELOG、OpenSpec及source-identical ZIP固定，独立准出。
- [x] 2.2 featurePR/attach/merge、0.3.5tag非latest小包发布、正确新包注册/索引。
- [x] 2.3 默认官方真实消费、Wiki公开reread、旧资产/latest不变（不管理旧registry，用户后续明确收窄）。

## Evidence

base公开main d532728，独立worktree；19run/首轮36uninstall检查通过。Windows/PHP8.1未实跑，旧fulllatest0.3.2不改，真实用户安装/PATH不操作。用户纠正归属同目标发行授权沿用，主Agent管理新Packagist注册/旧record可逆迁移提示。

固定本地候选证据：
- 19入口与37卸载检查PASS；严格metadata/diff/OpenSpec检查PASS。Wiki20页108本地目标0errors，仅既有导航warning与49外链未验证。
- 实际11source-identical成员25,793bytes ZIP：`/private/tmp/webman-aot-owner-035-candidate-r2-20261001/webman-aot-builder-0.3.5-composer.zip`，SHA256 `8f440d6b573ebb261d69240f77f31858daaabbdf5653c57490315b09c657db13`。
- 真ZIP迁移2.90秒PASS：实际旧包0.3.4与其他tool安装→仅移除旧global→新supdger0.3.5ZIP安装/新proxy→setup拒旧owner→PTY确认清旧state并保留新global→同锁根新setup写新owner后正确拒损坏fixture资源→新global真self-remove保留其他tool。证据`/private/tmp/aot-035-owner-candidate-consumer-20261001.json`与`.log`；temp夹具清理。不宣称完成原生完整runtime安装。
- 当前仅准备feature候选，尚未新tag/Release/Packagist注册或Wiki发布；更正035身份候选的准出等待独立审查。


实际公开结果：
- 独立固定PR [#47](https://github.com/supdger/webman-aot-builder/pull/47) head020a5caa准出PASS，merge/source/tag `v0.3.5`精确对应`8e13e5135b426a518949152ef45526dda2ba9c87`。独立19+37和trueZIP迁移13checks通过，日志`/private/tmp/aot-035-independent-migration-20261001.log`，fixture均清理。
- cleanmerge最终ZIP11source-identical成员25,793bytes，内容逐字等于fixed候选和merge git blobs，SHA256 `ea8435d52fdf2dcbce758da70fe3c4ba636b781eb57575a2679c30fc761aed2e`。最终包与候选仅归档元数据不同；[v0.3.5 Release](https://github.com/supdger/webman-aot-builder/releases/tag/v0.3.5)非latest，仅ZIP和SHA256SUMS。
- 主Agent按官方normal Submit使用原repository注册[正确Supdger包](https://packagist.org/packages/supdger/webman-aot-builder)成功，未改变URL字符绕duplicate。官方API创建需要认证且无可用CLI/APIcredential，因此复用已登录页面；未读取token。官方source唯一性按package name，repository为普通index，仅作提交前依据，实际正常注册另行证实。
- 官方p2 v0.3.5 source.reference精确8e13e/dist指向小ZIP。新registry回溯历史版本不改变历史代码/下载；正确身份契约从0.3.5开始，所有新安装和迁移命令约束`^0.3.5`。
- 默认官方`composer global require supdger/webman-aot-builder:^0.3.5`（无repositories覆盖）实际PASS8.43秒，真正下载ZIP摘要/11成员matchtag，help/version/list显示supdger，新全局包真实PTY self-remove成功，不prepare runtime，tempHOME/global/cache清理。证据`/private/tmp/aot-035-owner-public-consumer-20261001.json`及`.log`，原始日志不入repo。
- Wiki仅Install/Upgrade-Uninstall两页公开commit`f37fc50`，两页raw正文实读等于验证文件。native0.3.2/Composer0.3.3/0.3.4全部旧资产ID/名称/大小/digest/URL前后完全一致，latest仍v0.3.2；tag0.3.4仍ba29240，旧兼容tag未改。
- 旧Packagist弃用标记**未执行**：root打开旧页面redirect到search package_not_found，无Manage入口，未做任何oldmutation或delete，也未重建旧包。只读复核oldAPI JSON HTTP200且name旧身份/abandoned缺失；oldp2 HTTP404；oldpage最终为search。主Agent传回的独立nocache复核为旧API/p2均404、旧页面package_not_found；普通API200与nocache404不同，不能推断是本任务删除、改名或弃用，外部状态原因未知；历史GitHub发行仍可下载。对应临时证据`/private/tmp/aot-035-old-registry-package-final.json`、`p2-final.json`、`route-final.html`。
- Windows实机与PHP8.1未实跑；没有重建原生full包、编译器改动或真实用户安装/PATH操作。所有权修正不改变业务`--profile=saiadmin`和兼容页面。

## prepare-for-launch 最终评审
- 范围与质量：仅Supdger0.3.5轻量切片；19+37、独立13迁移与实际官方消费完成。closest staging为隔离temp真实Composer消费，不冒充Windows或runtime构建通过。
- 风险/回滚：确认卸载不可自动恢复；未知owner保留，旧owner需显式清理。遇误归属停止推荐，必要旧安装从原正式资源重装；不回退为错误包名的推荐。
- 物料：MIT/版本/11成员ZIP/校验和/README/CHANGELOG/Wiki已同步。CLI无新隐私权限、数据库迁移、商店或服务部署项目。
- 灰度：nonlatest补充小包，完整native latest固定0.3.2；监控终端非零失败、剩余路径、官方索引与下载身份。未承诺后台值守。
- 优化风险：有限扫描/精确身份/锁内再次owner检查/同锁inode保留，无新增明显高风险。压测Not run，无服务负载目标。
- 结论：**可进入灰度，仅本次Supdger Composer发行切片**。旧registry弃用setting不可管理未执行、Windows/PHP8.1缺口明示，不影响正确新包的实际公开消费结果。

用户后续文案收窄：引用“已装旧saiadmin时先remove”要求删除，并明确“不管saiadmin的”。当前README、ComposerREADME和Wiki两页去掉旧包迁移/remove指引，仅保留正确supdger安装、原生旧版本清理与业务SaiAdmin兼容说明。source legacy安全识别/tests、历史CHANGELOG/发行tag/ZIP不改；不再管理/重建/abandon旧registry。当前网页文本同步经文档提交完成，不为README一句重发0.3.5或升版。不同旧环境是否存在同名proxy须以实际入口版本核对，未宣称require后任意旧代理必然变成新代理。
