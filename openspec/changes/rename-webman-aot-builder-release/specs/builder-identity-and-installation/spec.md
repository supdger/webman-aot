## Purpose

定义 Webman AOT Builder 下一版本面向使用者和调用方的统一名称、安装位置及迁移边界，使 Mac 和 Windows 用户能从新仓库取得工具，并按文档完成构建任务。

## ADDED Requirements

### Requirement: 新版公开身份一致
下一版本的源码仓库、发布页面、安装包、安装入口、主命令、版本输出、帮助与诊断文案 SHALL 使用 `webman-aot-builder` 产品身份；公开示例 SHALL 指向真实可用的新版本资产和命令。

#### Scenario: 从发布页安装后调用
- **WHEN** Mac 或 Windows 用户依照新版本安装说明下载适配平台的安装包并完成安装
- **THEN** 用户可以调用 `webman-aot-builder version`、`doctor`、`build` 和 `verify`，看到与发布版本一致的名称及版本

#### Scenario: 使用源码说明
- **WHEN** 开发者查看仓库首页、构包说明和发布文档
- **THEN** 仓库地址、安装包文件名、示例命令和链接均指向 `supdger/webman-aot-builder` 的相应入口；历史 v0.1.3 被清楚标为旧发布物

### Requirement: 新版配置与用户数据隔离
新版 SHALL 使用 `WEBMAN_AOT_BUILDER_` 环境变量前缀及独立的 `webman-aot-builder` 用户数据根目录，并使其缓存、版本、日志和工具链仅由新版管理。新版 SHALL NOT 将旧 `webman-aot` 根目录下未经验证的缓存视为可用。

#### Scenario: 并存旧版安装
- **WHEN** 同一开发电脑已有 v0.1.3 的旧目录和旧命令
- **THEN** 新版安装及首次运行在新目录建立自己的状态，且不删除、覆盖或信任旧目录内容

#### Scenario: 环境变量配置
- **WHEN** 用户按新说明设置新版数据目录覆盖变量
- **THEN** 工具使用该目录，并在诊断信息中显示实际生效路径

### Requirement: 命名迁移说明准确
新版安装、更新和卸载文档 SHALL 说明旧命令、旧用户目录及新安装包之间的关系；旧版 `self-update` SHALL NOT 被描述为可完成新版命令或目录迁移。

#### Scenario: 旧版用户升级
- **WHEN** 仅安装 v0.1.3 的用户要改用新名称
- **THEN** 文档给出从新仓库下载安装、验证新命令及按需处理旧版的可执行步骤，并说明不会自动搬运旧缓存

#### Scenario: 卸载新版
- **WHEN** 用户运行新版随包卸载入口
- **THEN** 仅新版所拥有的安装入口与目录被处理，旧版数据的保留或删除行为被明确说明

### Requirement: 项目构建输出可识别
新版生成的项目缓存与工具自有清单、诊断和更新格式 SHALL 使用新版身份，并保持工具的可观察构建结果、Linux 目标与校验能力。

#### Scenario: 构建 Webman 项目
- **WHEN** 用户依照新版文档在受支持 Webman 项目运行 `doctor`、`build` 和 `verify`
- **THEN** 工具产出可校验的 Linux amd64 静态发布目录，诊断和项目缓存归属于新版身份
