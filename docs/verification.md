# 已验证的范围

这些记录保存在公开的[开发与验证分支](https://github.com/supdger/webman-aot/tree/development/evidence)。
下面使用固定提交链接，保证以后调整仓库目录时仍能打开。证据文件不进入
`main` 分支的源码下载 ZIP，也不进入安装包。

| 检查 | 已有证据 | 边界 |
| --- | --- | --- |
| SaiAdmin 全量编译与业务运行 | [编译、覆盖清单及隔离 Linux 四条业务路径](https://github.com/supdger/webman-aot/blob/2ce04a4c5f6be438bb2c5fb5ff43925b20ab5272/evidence/2026-09-25-saiadmin-full-static-business-runtime.json) | 四条路径在 Ubuntu 24.04 隔离虚拟机通过，不是每台目标服务器都通过 |
| Mac / Windows 产物一致 | [跨宿主构建记录](https://github.com/supdger/webman-aot/blob/2ce04a4c5f6be438bb2c5fb5ff43925b20ab5272/evidence/2026-09-25-saiadmin-full-static-business-runtime.json) | 已比较同一项目的 ELF、覆盖和资源摘要；修复运行数据排除后 Windows 未重复全量构建 |
| 两台现有 Linux 目标机 | [CentOS 7 虚拟机](https://github.com/supdger/webman-aot/blob/2ce04a4c5f6be438bb2c5fb5ff43925b20ab5272/evidence/2026-09-25-centos7-vm-saiadmin-runtime.json) · [Alibaba Cloud Linux 3 ECS](https://github.com/supdger/webman-aot/blob/2ce04a4c5f6be438bb2c5fb5ff43925b20ab5272/evidence/2026-09-25-alinux3-ecs-saiadmin-runtime.json) | 同一 ELF 的静态检查、启动和验证码通过；未在两台机器上做数据库登录验收 |
| 最新打包边界 | [运行数据排除与复核](https://github.com/supdger/webman-aot/blob/2ce04a4c5f6be438bb2c5fb5ff43925b20ab5272/evidence/2026-09-25-runtime-export-package-fix.json) | 历史导出数据不再进入候选包 |
| v0.1.0 Windows 安装包 | [公开验收运行记录](https://github.com/supdger/webman-aot/actions/runs/36174257212) | 新包安装、`doctor --repair`、中立 Webman 2.2.4 项目编译及 `verify` 通过；53 个直接编译、6 个 AOT 替代。此运行没有在 Linux 启动生成的程序 |

`webman-aot verify` 证明包结构和覆盖清单，不等于目标机业务验收。新增插件、
更换依赖版本或修改业务代码后，应重新编译并执行项目自己的接口回归。
