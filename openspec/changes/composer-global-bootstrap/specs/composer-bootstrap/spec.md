## Purpose

为具备 Composer 和系统 PHP 的 Webman 开发者提供独立全局入口，明确显示资源准备状态，在网络失败后仍可安全导入锁定完整包，并保持项目命令的目录、参数和退出状态。

## ADDED Requirements

### Requirement: Offline informational commands
入口 SHALL 在 help/version 时不联网、不安装，显示入口版本及目标构建器版本。

#### Scenario: Fresh help
- **WHEN** 未准备运行时且执行 help
- **THEN** 展示安装说明而不修改安装目录

### Requirement: Fixed isolated bootstrap
入口 SHALL 只支持 macOS ARM64 和 Windows x64，按固定版本、平台、大小、SHA256 及包身份校验完整安装包，隔离安装且不修改 PATH 或既有安装。

#### Scenario: Matching complete archive
- **WHEN** 指定匹配本地完整包
- **THEN** 校验后安装私有运行时及组件

#### Scenario: Invalid archive
- **WHEN** 指定组件资源档或摘要、架构不匹配包
- **THEN** 拒绝执行包内代码

### Requirement: Recoverable downloads
入口 SHALL 显示真实下载状态、有限重试，失败后提示准确文件名和公开链接，交互模式允许常规下载目录匹配候选或输入带引号完整路径。

#### Scenario: Offline import
- **WHEN** 网络失败且用户指定匹配本地包
- **THEN** 使用该包完成准备而非要求再次联网

#### Scenario: Noninteractive missing resources
- **WHEN** 非交互缺资源且未给 archive 或 yes
- **THEN** 清晰非零退出，不等待输入

### Requirement: Preserve invocation
入口 SHALL 准备成功后调用私有运行时保留原 cwd、argv 和退出码，不能通过 PATH 调用自身或承诺断点续编。

#### Scenario: Forward build
- **WHEN** 资源已就绪且执行 build 加参数
- **THEN** 在原目录执行原命令并传播退出码
