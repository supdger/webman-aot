# Webman AOT

Webman AOT 是一个独立的全局命令行工具项目，目标是在 macOS ARM64 和
Windows x64 主机上构建 Linux amd64 musl 全静态 Webman/SaiAdmin
可执行文件。

当前首先实施全静态 SDK 可行性门。没有通过静态链接、跨主机一致性和目标
Linux 运行验证前，本项目不会把“生成了二进制”标记为可发布。

## 开发入口

```bash
php tools/toolchain.php self-check
php tests/run.php
```

工具链脚本只读写本仓库的 `build/`、`dist/` 和后续定义的用户私有工具目录，
不要求目标 Webman 项目安装 Composer 插件。

