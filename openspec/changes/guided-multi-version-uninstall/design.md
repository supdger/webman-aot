## Context

使用者是装过多个版本的 Webman AOT 构建者，知道打开终端，但不应自行推导历史目录与所有权。入口为 `webman-aot uninstall`；成功必须明确哪些项卸载、哪些保留与哪些失败。现有 native 卸载默认删除全部 current/versions 并恢复 .previous-launcher，Composer setup 使用 owner.json 与独立 state。

## Goals / Non-Goals

目标：有限范围发现、静态版本识别、逐项默认保留、可复跑失败与移除精确对象。非目标：全磁盘搜索、项目 dist 清理、共享工具链 purge、PATH 修改、真实安装/PATH操作、native完整包发布。公开发行仅v0.3.4轻量Composer入口，native完整运行时继续0.3.2。

## Decisions

统一单文件 PHP 引擎随 Composer 轻量包与原生 app 打包；无需先 setup。只读标准根、显式 `--home`/`--state-dir`、PATH 固定名称、标准 Composer global metadata。按规范化真实路径去重，同目录多个版本保持独立。

原生版本须有有效代次 manifest 或已知 managed current layout 的版本命名空间/入口文件；删除前重新验证且拒绝符号链接、reparse（Windows 扫描包装器与 PHP realpath 检查）、系统/用户/项目根、未知附加内容。只删除被选对象，不删整个管理根或 toolchains。当前/active代次移除同时撤销可证明归属且绑定该安装的公开入口，不还原旧备份；清楚预告此影响。

Composer 状态只删除 owner.json 证明的白名单子树与元数据；保留 setup.lock 及根目录，避免解锁后并发 setup 换锁 inode。Mac 路径保留实际字节，仅 Windows 将反斜杠作为分隔符归一化。全局包由精确 package 名的 Composer CLI remove 管理，禁用 scripts/plugins。包移除排最后，已有运行时仍可失败恢复。包入口自身不可由手工递归删除，项目 require 不操作。

Windows native launcher 转给当前 app 的 PowerShell 卸载器；先复制受信 private runtime 与引擎到系统临时目录，再同步运行副本，不锁选中安装。不具备 runtime时清楚失败并建议用 Composer/系统 PHP 入口；不执行未知旧 launcher。

## Risks / Trade-offs

没有所有权证据的旧命令显示未知且保留，用户需核对来源；不以目录名称冒充版本。原生完整包需后续正式制作发布，旧0.3.2不会自动得到源码能力。局部删除I/O失败返回非零并列残留，不能宣称原子回滚。Windows目标平台实测单列，不用Mac证据代替。


## v0.3.4 发布评审

- 变更范围：只发行11文件Composer入口0.3.4与逐项卸载引擎；完整运行时锁定现有0.3.2。新原生wrapper源码随main合并，旧原生0.3.2下载包不会因此更新。
- 风险：用户主动确认的卸载不可自动恢复；未知归属必须保留。Windows物理验证尚受TEMP文件上传许可阻塞，PHP8.1未实跑。使用完整Composer代理路径绕过旧PATH优先级。
- 回滚方案：暂停推荐0.3.4并将后续安装约束回退至0.3.3；公开旧资产保持不可变。已经确认删除的旧安装需从对应正式包重新安装，不能假称数据回滚。
- 监控与告警：终端逐项打印结果、失败非零与剩余列表；公开发行后核对Packagist版本/dist和真实消费日志。遇误归属、误删、self-remove失败或旧入口重新活动即停止推荐。
- 值班与窗口：本任务发行过程中主Agent验收、此子任务唯一写者处理同范围返工；未承诺后台值守。
- 灰度是否需要：是，补充Release明确非latest；先隔离包消费及独立验收，再发布小包，官方Packagist实际安装验证完成后给用户清理入口。无需生产流量百分比放量。
- 物料与合规：MIT LICENSE保留，CHANGELOG/README/Wiki同步，固定版本/资源校验/小ZIP清单；CLI无隐私权限新增，无数据库迁移、服务配置、客户端商店或签名操作。
- 优化风险速扫：只读有限管理根/PATH/metadata，无全盘扫描；删除前重复归属校验；setup.lock保持inode，避免并发换锁；无明显剩余高风险。压测Not run，此CLI卸载路径无服务负载目标。
- 预发验证：本地实际ZIP消费者通过；独立现有Mac/common安全验收通过，新的0.3.4候选独立消费与Windows受限验证等待结论。发布准出不将缺失平台层伪装为通过。
- 发行前阶段结论：物理Windows上传许可受阻；未据此声称Windows通过。主Agent随后依据独立Composer切片准出PASS，确认只发布轻量小包并保留完整原生发行。
- 本次最终结论：可进入灰度（Composer0.3.4轻量切片）；PR/tag/非latestRelease/Wiki/官方索引和实际默认消费均完成。精确发行结果见本change任务记录；Windows实机、PHP8.1和新原生完整包验证仍单列未完成。
