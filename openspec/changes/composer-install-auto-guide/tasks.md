## 1. 最小插件

- [x] 1.1 官方安装完成事件、命令与终端判定、first-install fallback、单次固定入口启动。
- [x] 1.2 元数据、版本与轻量 ZIP 包清单同步，optional Composer 版本门明确。

## 2. 实际安装行为

- [x] 2.1 真实 ZIP / Composer PTY 首次信任、无变化重复、旧版升级、拒绝信任与关键负例。
- [x] 2.2 真实完整资源包导入及原项目菜单、退出和失败结果分开显示。

## 3. 固定候选

- [x] 3.1 README 与 Wiki 安装页仅保留普通安装命令和真实信任步骤。
- [x] 3.2 直接相关回归、静态、OpenSpec 与归档身份通过，提交独立验收；此前不发布。

## 作者实际检查与固定候选

- Composer 2.9.5 / PHP 8.4.18 / macOS ARM64，真实固定R2本地 ZIP + 隔离 HOME/global/cache/PTY 34项通过：首次信任、重复无变化、0.3.6 升级、已加载插件替换、坏归档失败、拒绝信任、命令及无人边界，以及明确 require 0.3.7 时最低 Composer 平台门。测试 finally 清理各自资源。日志 `/private/tmp/aot-037-plugin-lifecycle-r2-final-20261001.log`。平台设为2.5.2时裸 require 会按 Composer 正常规则选择兼容旧0.3.6；不是新插件支持旧Composer。
- 既有桥接19、引导18项通过；旧卸载引擎和 console helper 字节不变，不扩大重测。Composer strict、PHP语法、OpenSpec strict、diff check 通过；Wiki草稿20页107本地目标/0错误/1原导航警告。
- 作者从实际裸 require 自动回调菜单导入原0.3.2完整包，264098182字节/f4b6bed622788d7201cc5cfb1fdf8b5fdc95da91403e938313d7c1a26dcc87d0；离线7614条校验、prepare17.9秒后进入原项目Flow。中文空格空目录失败78，原重试菜单及诊断可见；结束后明确显示项目78、入口已安装，Composer审计照常并返回0。此不是业务构建成功。日志 `/Users/supdger/.tmp/webman-aot-guided-9181c61380f4cc69/guided.log`。
- R2固定ZIP `/private/tmp/aot-037-plugin-candidate-r2-20261001/webman-aot-builder-0.3.7-composer.zip`，13成员/30449字节，SHA256 `25cd2f29dbe7ece081497038e1f35fcd8899cd746d398169c8f51c258d9c47f7`。清单和临时仓库seed位于 `/private/tmp/aot-037-plugin-fixed-20261001`；元数据及所有运行体与已实跑R1一致，仅README澄清一条裸命令。
- 独立安全验收：固定R2源码与13包成员一致，12项真实边界检查通过，无必须修复项。独立冷用户从普通裸 require 实际完成首次信任、重复安装、拒绝信任、非交互、完整包回调导入和原Flow重选；项目78与入口已安装/Composer0分别可见。
- 主控已按本次用户“可以”接受插件机制及同目标既有发布授权准出精确R2；此记录时尚未提交、合并、发tag/Release、推Wiki或更新Packagist，公开消费另行核对。Windows物理、PHP8.1、新联网下载以及Linux业务流程仍未运行，未写真实用户global/PATH或导出Windows材料。
