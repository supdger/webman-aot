## ADDED Requirements

### Requirement: 显式全局安装自动引导

入口 SHALL 在用户交互式单独全局 require 本包且 Composer 完成安装与自动加载后，最多一次打开既有 guide；首次激活、升级和无变更重复 require SHALL 均支持。

#### Scenario: 首次信任并安装

- **WHEN** 用户运行 `composer global require supdger/webman-aot-builder` 并接受 Composer allow-plugins 信任
- **THEN** 安装与自动加载后显示系统适配资源菜单，再使用已有项目流程
- **AND** 取消不准备资源，后续 Composer 审计继续正常运行

#### Scenario: 重复与升级

- **WHEN** 用户再次单独全局 require 本包或从旧普通入口升级
- **THEN** 对应安装阶段完成后自动菜单，已存信任不重复询问

### Requirement: 保守激活边界

入口 MUST 不为其他命令、局部项目、混合安装、CI、非交互或非终端环境自动启动；MUST 尊重 Composer 禁用插件/脚本及 dry-run/no-install/no-update，解析、下载或安装失败不启动。

#### Scenario: 无人首次安装

- **WHEN** 用户在非交互环境安装且未授权插件
- **THEN** Composer optional 插件不自动引导或预写信任，普通入口仍可显式调用

#### Scenario: 非目标命令

- **WHEN** 用户安装其他包、运行 update/install/show/config/exec/remove 或在局部项目 require
- **THEN** 本插件不打开菜单或写私有状态

### Requirement: 结果与路径明确

入口 SHALL 保留真实资源和项目日志，并单独显示项目流程退出码与 Composer 包安装结果；首次 global 菜单 SHALL 要求项目完整路径，不假称能够恢复原工作目录。

#### Scenario: 项目失败

- **WHEN** 已安装入口的项目流程返回非零
- **THEN** 输出真实项目退出码和重试命令，保留 Composer 自身后续流程及结果
