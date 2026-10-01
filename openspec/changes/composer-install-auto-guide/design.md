## Context

基线公开 main 3b04473，0.3.6 为 library。Composer 不运行依赖包 scripts。本机 Composer 2.9.5 官方 RequireCommand 在 Installer.run 前派发 command；新插件在安装中才激活，因此首次错过 command。Installer 在成功 operations、autoload、bin 后派发 post-update-cmd；审计在之后，故此事件只表示安装阶段完成。

## Goals / Non-Goals

Goals：用户运行普通全局 require，首次确认 Composer 信任后直接选组件并进入现有项目菜单。

Non-Goals：不修改 Composer，不推测最终进程退出码，不使用 shutdown/Reflection/GLOBALS，不新增项目 UI，不改 PATH、原生发行或真实用户安装。

## Decisions

- 使用官方 PluginInterface / EventSubscriberInterface，activate 只记录状态。POST_UPDATE_CMD 低优先级启动固定本包 PHP 入口，最多一次。
- 已安装插件使用官方 command InputInterface；首次及替换插件使用原 argv，通过官方 Application / RequireCommand 输入定义解析。只支持明确 `global require` 且只含本包；别名、混合包或不明输入保守不自动启动。
- Composer::isGlobal、IO 交互、STDIN/STDOUT 真 TTY、CI/COMPOSER_NO_INTERACTION 和禁止选项共同限制。optional 插件要求 Composer >=2.5.3；无人初装不预写 allow-plugins，bin 仍可显式执行。
- 菜单在包安装和自动加载完成后启动，后续 Composer 安全审计正常执行。项目结束码单独明确显示，不把构建失败伪装为包安装失败或成功。过程保留原生实时输出。
- global 初装已切到 Composer 用户目录，公开插件 API 不能取得原始工作目录；菜单要求输入或拖入项目完整路径，不能猜测路径。

## Risks / Trade-offs

首次信任由 Composer 自己询问并保存。拒绝信任或禁用插件不会自动菜单，仍可显式运行 guide。实际 Windows 和 PHP 8.1 尚未验证；Mac 真 PTY 与真实 ZIP 安装覆盖本次路径，独立验收后才发布。
