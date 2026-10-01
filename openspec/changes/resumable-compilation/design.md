## Context

见 proposal.md。基线 69b65084 每次清空 build 并传 `--force`。锁定 TypePHP 0.9.2 已有递归 generated 输入 key，但完成记录没有对象摘要且非原子；链接缓存只看命令和 mtime。其子进程无法保证跨 Windows 继承 PHP flock。

## Goals / Non-Goals

**Goals:** 保留成功编译单元，正常重试无额外选择；实际输入变化和不完整缓存安全失效。使用者是已有依赖、会进入项目目录编译的开发者，成功结果仍为校验完成的 dist-aot。

**Non-Goals:** 不从单元内部恢复；不恢复生成器执行；不改动已有安装或运行项目；不公开发布 0.4.0、不写入远程 Windows。

## Decisions

- 每次新建独立 attempt 镜像，项目 flock 覆盖生成、编译、链接及打包。旧子进程只写旧 attempt，避免同目录残留进程与 Windows FD 继承问题。只保存完整缓存对象，拒绝复用整个旧 build。
- 上游补丁使用已有输入 key，加入 wrapper 的实际工具链 fingerprint，并把 attempt 路径归一到稳定项目身份。每个成功单元通过私有候选目录复制对象、记录 size/digest 后原子发布；导入前再次校验。不完整候选不命中。
- fingerprint 覆盖实际 PHP/编译器/objcopy 字节、TypePHP/PHPX/sysroot/phprc 内容、锁与补丁、目标配置及 SDK；源码与生成头由单元 key 覆盖。
- 每次 attempt 没有旧 ELF 和 link cache，强制走最终链接、现有 objcopy/ELF/path/distribution 校验。`--fresh` 禁止读取缓存，但可以留下新的完整单元。
- native 0.4.0 与 Composer 后续发行必须绑定新 native 运行时与新增补丁；现有 0.3.x URL 和资产保持当前身份。候选验证不能代替新版本公开资产验收。

## Risks / Trade-offs

- [attempt 目录占用磁盘] → 保留用于诊断及断点安全，不自动删除可能仍被孤儿进程使用的目录；显式工作区清理仍受所有权约束。
- [完整工具链扫描有耗时] → 真实内容身份优先；阶段反馈说明正在校验，不虚构进度。
- [路径归一影响命中] → 真实多单元中断恢复验证；若生成内容变化则安全重编。
- [Windows 未实跑] → 执行可移植文件与并发测试，记录真实 Windows 强退恢复为发行门槛。

## Migration Plan

仅新候选读取新 schema 的对象 checkpoint。旧裸 metadata 不接受。回滚到旧入口只会全量重建；旧发布内容不被改写。
