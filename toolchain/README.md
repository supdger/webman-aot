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
以非零状态终止。该入口在真实 Windows x64 主机验收前仅是待执行的重放工具，
不能作为跨宿主一致性已经通过的证据。
