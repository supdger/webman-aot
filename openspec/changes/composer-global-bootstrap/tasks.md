## 1. 独立入口

- [x] 1.1 Composer元数据、离线help/version及参数解析
- [x] 1.2 固定完整包锁、下载和本地导入校验

## 2. 私有运行时

- [x] 2.1 平台安全解包、隔离安装及状态并发锁
- [x] 2.2 cwd/argv/退出码转发及交互恢复

## 3. 验证与文档

- [x] 3.1 行为回归、Composer代理和Mac真实完整包隔离安装
- [x] 3.2 简明README、许可与未完成Windows实机验收说明

## 本轮验证证据与边界

- 作者环境：macOS ARM64、PHP 8.4.18、Composer 2.9.5。18项行为检查通过，覆盖拒绝未owned状态、native参数和退出码保留、损坏/错架构/错版本资源拒绝。测试资源在 `/private/tmp/composer-aot-tests-ed403368fb53`。
- 临时 COMPOSER_HOME `/private/tmp/composer-aot-global-author-20261001` 真正 global install/reinstall 成功，代理调用 version/doctor；没有修改真实用户 Composer global 或 PATH。
- 固定正式fullmac包在中文空格目录通过离线setup。大小/SHA及原安装器7614条目校验通过，私有构建器为0.3.2，doctor为healthy。
- PTY交互故障恢复：测试driver仅把下载URL改为拒绝连接的127.0.0.1，原大小/SHA锁不变。真实curl有限重试失败后输入带引号正式缓存包路径，真实原安装器成功，owner/ready存在，本轮extract/partial已清理。状态目录 `/private/tmp/composer-aot-interactive-author-20261001/交互 空格 state`。
- 独立源码复审修复ownership与argv后通过。发行ZIP候选 `/private/tmp/composer-aot-package-author-20261001/saiadmin-webman-aot-builder-0.1.0-candidate.zip` 为12744字节，仅9个运行交付文件，逐一比对与当前源码一致。
- 独立使用者Mac切片验收通过：真实global path代理0.6秒、ZIP file-dist代理0.3秒；中文空格正式完整包setup19.4秒；path/ZIP两入口doctor分别3.6/5秒healthy；非TTY缺资源及components/wrongOS/corruptSHA均拒绝，失败无安装。冻结6文件摘要未变。此结果不是Windows或PHP8.1验收。
- Windows PowerShell尚未在Windows执行，PHP8.1最低版本未实跑；未完成新入口的Windows真实安装或项目编译。没有Packagist发布、提交推送；原0.3.2源码/包未修改。
