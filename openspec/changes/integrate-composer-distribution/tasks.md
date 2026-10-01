## 1. 源码与发行元数据

- [x] 1.1 迁入已验收入口并仅将入口版本改0.3.3，核对固定0.3.2资源与18项行为回归。
- [x] 1.2 添加根注册元数据、嵌套bin/autoload与固定轻量dist URL，通过composer validate --strict。
- [x] 1.3 添加发行打包工具，验证仅九个源码一致文件、低于100KB、独立SHA256SUMS及非latest发布提示。

## 2. 使用与验证

- [x] 2.1 隔离Composer dist与source安装均验证真实代理、PSR-4自动加载、help/version且不准备平台资源。
- [x] 2.2 更新受影响README及现有Wiki安装页，核对路径/版本含义/离线恢复与原latest保持。
- [x] 2.3 完成独立审查和候选差异核验，区分本地通过与待公开注册/发行验收。

## 3. 后续公开门槛

- [ ] 3.1 获原main/新tag及Release/Packagist切仓与旧版保留/Wiki目标授权后发布，并核对公开p2使用轻量dist与默认globalrequire。当前不执行此项。

证据：候选基于原main159861d；PHP8.4本地行为18项PASS，第一版ZIP16864字节/九成员。Windows新入口与PHP8.1实测仍未完成。

作者验证：真实隔离Composer ZIP安装与源码path mirror均生成代理、PSR-4与version0.3.3正确；复用本任务独立ready0.3.2 state doctor healthy。path mirror不是VCS源码回退，真实VCS门槛交独立验收。Wiki候选位于/private/tmp/webman-aot-composer-wiki-20261001，仅Install与Sidebar，check_wiki本地104targets/20pages，0errors/1既有warning，48external未验证。公开registry门槛未执行。

独立验收：真实VCS源码与发行dist均通过Composer代理/PSR-4/版本检查，稳定文案最终ZIP16839字节、SHA256 58cbd8c1a130c1b0e4d360d101122cd6ea0d2cb01527ef7821241832d3e0301c，八core冻结不变。用户已明确授权“允许，完成合并、发布和仓库切换”；公开步骤执行后补录，3.1暂不标完成。
