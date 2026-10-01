## Why

断点续编源码已经独立验收，但公开 Composer 0.3.7 仍安装原生 0.3.2，用户无法取得该功能。用户要求发布并维护 Webman 市场，因此需要同一 0.4.0 版本的真实双平台组件、安装包、Composer 绑定与公开入口。

## What Changes

- 原生与 Composer 入口统一升级 0.4.0，完整包绑定使用实际新包摘要。
- 从已发布、完整摘要校验的组件制作包含新增 TypePHP 补丁的 0.4.0 组件；保持已配套 SDK ABI 与输入字节。
- 修复发行辅助脚本对旧组件版本的硬编码，支持本地新组件的安装包自检。
- 完成私有安装、真实续编与独立发行验收后，提交合并、创建不可变发行资产，并同步受影响 Wiki、Packagist 与市场内容。

## Capabilities

### New Capabilities

- `resumable-release-distribution`: 普通安装入口取得包含续编功能的同版原生运行时，且发行摘要来自实际打包结果。

### Modified Capabilities

无。

## Impact

影响版本常量、组件派生流水线、组件锁、安装包制作与 setup、Composer 资源绑定及发行文档。旧版本标签与资产不修改；不导出到远程 Windows 宿主，不宣称本机归档制作证明 Windows 物理机执行。
