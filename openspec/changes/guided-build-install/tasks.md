## 1. 共享引导与项目下一步

- [x] 1.1 实现共享菜单、参数、EOF安全退出及原生进程实时输出/日志；用真实子进程行为测试证明失败退出码、长任务状态、含空格中文参数及非TTY不等待。
- [x] 1.2 实现源码结果消费、安装确认/取消与新安装version验证；用隔离包与私有home证明取消无写入、拒绝缺失/损坏结果且不调用旧PATH安装。
- [x] 1.3 实现同一入口项目目录选择、build成功后自动verify及结果层次；使用隔离Webman项目验证成功链，并覆盖无效目录、build失败不继续verify及重选。

## 2. 源码原生入口和锁定材料

- [ ] 2.1 实现Windows build.cmd、自举复用与结果文件接口，保留旧PS/PHP选项；验证无系统PHP路径、SHA错误及旧自动化调用兼容。
- [x] 2.2 固定并核验macOS重链接材料输入及runtime摘要，实现build.command和macOS后端；无预装PHP情况下完成small/full构包，验证实际包清单与隔离安装version。
- [ ] 2.3 核对两平台下载进度、缓存复用、失败建议/Issues和当前源码revision；从人用入口实际观察且记录日志，不能以静态文本断言代替流程。

## 3. 安装包入口与资产

- [x] 3.1 添加包内install.cmd/install.command并由packager收集共享引导文件，保持原JSON结果协议；检查真实归档内容、macOS执行位及原机器调用结果。
- [ ] 3.2 从实际small/full包执行新安装入口至私有home/bin/no-path，验证确认、取消、安装后version及项目菜单；full无网络安装不得回退下载。

- [x] 3.3 实现独立setup生成器及自包含Windows/macOS模板，Mac额外生成含0755 command的启动器ZIP，从两份现有packager结果及实际包核验身份后绑定small/full URL/SHA；验证错误版本、flavor、摘要及转义输入均拒绝，原JSON默认接口不变。
- [ ] 3.4 从单独setup文件在无源码/无系统PHP环境走选择类型→下载/外层校验→安全解压→包内安装→项目下一步；覆盖损坏包、下载失败、归档逃逸、取消、EOF、私有安装参数与已下载包复用，验证外层失败绝不执行包代码。

## 4. 整链验收与交付

- [x] 4.1 按用户CLI优先要求，在原生macOS用CLI核验setup ZIP执行位→系统ditto解压→直接执行0755 command无需chmod与真实退出码，并分别从独立setup.command与源码build.command完成选择包类型→构包自检→选择安装→选择隔离项目→build/verify，并覆盖下载失败/损坏输入、空格中文路径和EOF，记录可核验证据。
- [ ] 4.2 在真实Windows从独立setup.cmd、源码build.cmd与包内install.cmd完成同等路径及异常测试；环境不可用保留具体缺口，不用macOS或静态检查替代。
- [x] 4.3 独立上下文Astra按固定候选审查跨平台契约及人用流程，复核真实日志/目标平台操作和未验证范围；关联问题在通过前修复复验。
- [x] 4.4 更新简明README入口与仓库外Wiki候选，核对直接下载安装不要求手工选资产/解压，用户只执行一个文件并选择即可完成；审查diff无临时产物及任务外改动，运行OpenSpec strict验证并记录交付状态。

## 5. 整合源码快照误判修复

- [x] 5.1 B将来源分支的ProjectMirror/SourceTreeSnapshot及tests/project-mirror.php整合到当前候选；执行真实并发writer回归，证明根/嵌套DS_Store无误报、其他隐藏输入保留、真实add/remove/modify仍阻断、安全有界相对路径及候选清理。
- [x] 5.2 B重新构建Mac small/full并校验包内两处修复与当前源码一致、payload清单及私有安装version；记录新大小/SHA并取代旧最终候选身份，不用旧包验收冒充。
- [x] 5.3 A仅触发实际ProjectMirror真实并发写入失败，验证经Flow显示变化类型/相对路径、原因、重试建议、非零退出及verify短路；不重复项目编译整链，除非实际证据要求不改Flow。
- [x] 5.4 C使用新small/full生成新setup/ZIP摘要绑定，私有安装确认包内修复后独自完成setup→真实109单元项目build→verify组合；未变的下载/TLS协议证据注明复用版本及理由，实际包身份与项目整链必须新跑。
- [x] 5.5 不同上下文独立验收当前整合候选、实际新包及A/C收据；通过后重新完成4.1/4.3限定勾选，Windows原生及目标Linux运行限制仍保留。

## Evidence

验证更新：2026-09-28。实现基线HEAD `61634057a3eb8dee571ac79e044afe030da73a27`，当前源码含本任务未提交变更；产物revision明确带`-dirty`，不是已发布同名版本的字节一致证明。下列路径均以任务临时根 `/Users/supdger/.tmp/guided-build-install-20260928` 为前缀；原始日志留在仓库外。

- E1：`php tools/test-guided.php <new-private-directory>`；A回报25/25通过，过程日志`a/regression-5/results.log`。真实子进程包含预期失败场景，日志出现非零是断言输入，不等于整套失败。
- E2：`php tools/test-setup.php <new-private-directory>`；C回报34/34通过，日志`c/regression-9/results.log`。涵盖生成器实际归档/摘要/元数据拒绝、Mac ZIP执行位、EOF及错误退出；Windows资产检查不是Windows原生运行。
- E3：`php tools/test-source-bootstrap.php <new-private-directory>`；B回报5/5通过，最终自举日志`b/bootstrap-fixtures-4/results.log`，旧fixtures-1存在已修错误，不复用为最终证据。最终两包索引`b/final-refined-source-results.json`；Windows PHP参数兼容检查`b/windows-php-interface-checks.json`仅限该解释器接口。
- E4：真实命令 `build.command --flavor=full --install --home=<private> --bin-dir=<private> --no-path --project=<new-real-Webman-fixture>`。`a/refined-source/result.json`与`source-chain-approved.log`证明退出0：源码后端33.5秒、私有安装14.3秒、真实109/109编译28.1秒、verify0.5秒，scope=`build-host-structure-and-integrity`。无服务启动、无数据库、无真实PATH修改；未验证Linux业务运行。首次沙箱无法写worktree产物，申请权限后重跑成功。
- E5：`c/native-https-3/native-result.json`及small/full-setup.log：本机受信任HTTPS测试源，small/full独立setup下载与私有安装均退出0，full安装无公网依赖。HTTP404→22、SHA不符→65、CA不信任→60，全部neverInstalled=true。该专用HTTPS服务已停止、端口已关闭；这不是公开Release下载证明。
- E6：`review/refined/targeted-results.json`独立5/5返工复验：非TTY管道、SHA错误、壳层UTF-8错误退出、真实子进程权限错误分类、HTTP错误分类。独立最终限定判定见E10。
- E7：Windows SSH8秒超时、exit255，未认证；无本地pwsh，真实Windows验收未执行。精确feature分支push/手动触发workflow候选已审，尚未提交、推送或运行CI。Windows门槛不能用Mac或静态检查抵销。
- E8：主控已核对README、CHANGELOG与仓库外Wiki候选、help及17个链接；本次`openspec validate guided-build-install --strict`通过。`.agents/skills`六份为初始化复制的通用技能模板，用户级已有对应技能，非本次仓库交付必要文件：保留磁盘，后续提交排除。`openspec/config.yaml`记录项目schema及中文规划上下文，属于正式规格入口，应纳入。`dist`、vendor、原始日志/fixtures均不纳入。

- E9：`c/native-setup-project/result.json`与`setup-project.log`证明独立setup完整包入口使用显式`--archive=<本次已下载且摘要验证的full包>`、私有home/bin/no-path及新真实Webman项目，退出0，总耗时45.161秒；安装14.1秒、version1.0秒、109/109编译27.1秒、verify0.5秒，scope=`build-host-structure-and-integrity`，17文件校验，targetLdd=`not-run-on-build-host`。full摘要与最终候选一致；无生产代码改动、服务或数据库，子进程已停止。此组合复用本地已下载包，网络阶段的真实HTTPS证据单独来自E5，不伪称同一执行下载再编译。

- E10：独立上下文 `astramedium__accept_guided_workflow` 最终判定：Mac/core通过，无剩余源码阻断；独立核对E9入口与HTTPS3 setup字节一致、finalfull外SHA绑定、日志严格UTF-8及真实ELF SHA；结合E4源码整链、E5真实HTTPS下载安装、E9已下载包项目整链，4.1 CLI适用链闭合。Windows4.2仍未原生运行；Finder/quarantine未测；Linux运行、数据库及业务验收不在通过范围。精确feature分支workflow push/job.if仅静态审查通过，未声称CI实际运行。

整合前历史Mac包身份（仅对应E1-E10，已不是本次整合最终候选）：small 8,530,127字节，SHA-256 `e7c5eccd819119e1989b06b7c95a434618207d331347772575f6d8f34928ccfc`；full 265,190,773字节，SHA-256 `2190d84639850c84df9dbbd984921ebbb03ed1e5c0409312e8baddebcb17463d`。清单和私有安装version均通过；包内Flow/install.command与候选源码一致。

当前未提交、推送或发布；真实用户安装/PATH及远端服务不在本轮验证范围。按用户纠正仅CLI验收，Finder双击/浏览器quarantine首启未测，不阻塞本轮源码实现。行为测试技能未提供，使用仓库直接行为测试。任务勾选仅表示上述适用条款与证据，保留跨平台条目中未完成的Windows部分。


## Integration evidence status

2026-09-28用户要求整合交付后，历史4.1/4.3通过记录保留在E4/E9/E10，但任务重新打开等待包含源码快照修复的新候选。历史菜单/协议/下载安全检查可在对应文件未变时引用，包摘要、镜像一致性、A真实错误呈现和C项目整链必须刷新。5.1-5.5均有整合收据及独立限定通过，4.1/4.3已按Mac/core原CLI范围重新完成；证据目录为`integration/`。

修正先前任务勾选批量脚本索引错误：E1-E10已经记录通过但1.1/1.2/1.3/2.2/3.1/3.3/4.4未实际写入x，现按原收据补正。新增范围以第5组及重开的4.1/4.3跟踪，不将历史包冒充新候选。与Windows原生有关的2.1/2.3/3.2/3.4/4.2保持未勾选。


- I1：`integration/mirror-integrated.log`记录 `php tests/project-mirror.php`实际并发writer通过元数据排除、隐藏输入保留、真实PHP新增/删除/修改保护、安全有界相对路径及失败候选清理；原来源补丁与当前整合候选一致。独立验收者另跑镜像回归1.111秒通过。
- I2：`integration/packages-index.json`记录源冻结摘要`4ac5d5e69e6092a2831ae60ecdeb54b901616b22feb105ef6f9d40e4f0101d5d`不变；新Mac small为8,530,155字节/SHA`dd3bef5262c7c63a60e9dfbf74d15d89b1e96e33a7207779d611b2faee550b2a`，full为265,191,423字节/SHA`22c7ca61fc1aed886da0506be4943c8c57f666f0adb941f014b192ef11b92f93`。两包payload清单(141/142项)和私有安装版本自检通过；ProjectMirror、SourceTreeSnapshot、Flow及install.command包内成员与当前源码逐字节匹配，独立integrated-package-members检查通过。这是当前整合最终本地包候选，项目组合及独立判定见I4/I5。
- I3：`integration/a/receipt.json`及`flow-output.log`记录实际ProjectMirror并发异常经生产ExitCode映射78→真实Flow/ProcessRunner退出78，6/6断言通过，耗时1.442秒。added/removed/modified实际相对路径、转义控制字符、有界长度、底层retry和人话建议均保留；失败不verify、不报成功、不激活镜像且临时候选清理。A未改生产代码、未重新构包或编译项目，证明Flow无需额外修改。

- I4：`integration/c/result.json`及`setup-project.log`证明新setup ZIP SHA`afb3937535b93c66b834b91eb8f89f1c5a69a0131676f4d1267ee834534d509c`、3,020字节，绑定I2的新small/full摘要；解压0755入口通过`--archive=<newfull>`先校验外层摘要，再私有home/bin/no-path安装并构建新真实Webman项目。退出0，总44.427秒；安装14.4秒、version0.1秒、109/109编译27.4秒、verify0.4秒。两处已安装Project源码逐字节匹配当前修复，测试.DS_Store未进入实际镜像，17文件结构/完整性校验通过，targetLdd未在本机运行；ELF SHA`e7af12fd2d9a138bb7831adce71f6a9ba74d637b54a88483e56564c60d8fa1c6`。无生产源码改动、服务/数据库/PATH写入，子进程已wait结束。本组合复用本地下载包，不声称新SHA已经公网下载。
- I5：不同上下文 `astramedium__accept_guided_workflow` 最终整合Mac/core独立验收通过，无新增必须修复项。`review/mirror-integration-result.json`记录独立真实回归退出0/1.111秒，`review/integrated-package-members.json`记录新两包实际摘要/成员字节匹配；独立复核A真实78错误链、安全路径及无verify，再核新setup ZIP实际hash/0755/执行字节、双flavor摘要绑定、新安装源码、镜像无DSStore、109单元和实际ELF。以上支持4.1/4.3限定范围及5.4/5.5，不代表Windows原生、Finder/quarantine或Linux/DB业务验收。

整合最终状态：Mac/core本地候选通过。I2的newsmall/newfull与I4的newsetup为当前最终身份；E1-E10旧包和旧项目链只作历史证据。引导菜单、下载/TLS协议实现未因ProjectMirror/SourceTreeSnapshot补丁改变，故E1/E2/E5/E6对应协议证据复用，实际新包身份和项目路径已由I2-I5刷新。Windows2.1/2.3/3.2/3.4/4.2仍未全勾；未提交、推送或发布，不声称新版本已公开。
