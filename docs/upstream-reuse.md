# 上游复用边界

核对基线：`Tinywan/webman-typephp` 主分支 `416f96c1de84b365b3d7ae9db16c9571b1620f03`。
本工具不实现新的 PHP 编译器，也不重做 Webman 项目生成规则。

| 上游能力 | 本工具处理 |
| --- | --- |
| `ProjectGenerator`、`main.php` stub、Webman 兼容改写 | 锁定基于上游的 `supdger/webman-typephp` SaiAdmin PR 归档及文件摘要，在隔离项目副本中直接调用；不复制一份生成器实现。 |
| 新增 Webman 插件源码 | 上游普通 Webman 生成清单列出 `app`，但未自动列出后来安装的 `plugin/*` 业务 PHP。本工具在生成后只把已发现且尚未覆盖的插件业务文件逐项加入编译 `sources`；保留上游的 `ignore`，安装脚本、配置和模板不混入编译。覆盖校验及真实编译任一失败即不出包。 |
| TypePHP 编译器、PHPX 和全静态 SDK | 使用锁定的 TypePHP/SDK 源与产物，由 TypePHP 生成和编译目标代码；只为实测缺口应用有摘要保护的小补丁。 |
| `typephp:package --static` | 上游在 Linux Docker builder 内调用 TypePHP `--full-static --compiler=clang`。保留为 Linux 构建对照，不重复实现它的项目编译入口；上游当前主分支没有 SaiAdmin profile。 |
| 上游 Docker builder 镜像 | `docker/Dockerfile.static` 固定 `tinywan/typephp-linux-x64-static:v0.9.0` 镜像；它不能直接充当 Mac/Windows 的无 Docker 工具链。本工具锁定 TypePHP 0.9.2，不能未经版本和 ABI 验证就替换为该镜像内容。 |
| `doctor` 与 `dist` 打包 | 上游检查 Docker 并整理 Linux 产物。本工具只补全局私有安装、无 Docker 的跨宿主准备、业务 PHP 覆盖与泄漏校验、静态 ELF 和业务运行验收。 |

后续只针对上游尚未覆盖、且有复现证据的缺口修改：SaiAdmin 适配、Mac/Windows
对同一 Linux 目标的编译一致性，以及目标发行版的业务运行。若上游提供同版本、
可校验、可在无 Docker 宿主使用的 SDK 或构建接口，优先替换本工具对应实现。
