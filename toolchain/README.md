# Toolchain

本目录保存可审计的工具链定义、构建 recipe 和补丁。下载的源码、SDK 与产物
只能写入忽略版本控制的 `build/`，不得提交二进制或引用相邻项目目录。

## Windows x64 跨宿主重放

OpenSpec 1.7 使用纯命令行入口，不调用 Docker、安装器 UI、系统 PHP、Git 或
`patch`。PowerShell 会把缺失归档下载到指定缓存，逐项校验
`toolchain.lock.json` 中的 SHA-256，并只在指定的空工作目录中解包和构建：

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tools\windows-replay.ps1 `
  -Artifacts "$env:LOCALAPPDATA\webman-aot\artifacts" `
  -WorkRoot "$env:LOCALAPPDATA\webman-aot\replay-0.9.2"
```

输出 `windows-replay.json` 同时记录规范化输入摘要和 ELF SHA-256。任何下载
漂移、源码结构漂移、补丁重复/漏命中、动态 ELF 或与 Mac 基线摘要不一致都会
以非零状态终止。实体 Windows x64 的跨宿主重放及 Mac 字节对比见
[运行证据](../evidence/2026-09-24-windows-x64-cross-host-full-static.json)；
当前源码的 Windows 原生 runner
[自动复核](https://github.com/supdger/webman-aot/actions/runs/36153995918)
也已通过。自动复核只覆盖静态 smoke，不代替 SaiAdmin 业务验收。

`patches/typephp/0.9.2/` 中的补丁以
[TypePHP v0.9.2](https://github.com/swoole/typephp/tree/v0.9.2)
为基线，`manifest.json` 记录被修改文件的前后 SHA-256。该版本上游
`composer.json` 声明 `GPL-3.0-only`；本仓库的 MIT 许可不能被理解为
重新许可上游 TypePHP 源码。公开源码或二进制前仍需核定补丁与聚合产物的
许可标注和再分发义务；[候选包许可预检](../evidence/2026-09-25-exportguard-license-preflight.json)
只记录已核实的事实，不作合规结论。
