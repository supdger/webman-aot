## 1. 基线与版本

- [ ] 1.1 核对当前工作树、上一轮未提交改名差异及自有旧名清单，逐项标出历史资产和第三方引用；用 `git diff` 与定向 `rg` 复核范围。
- [ ] 1.2 恢复 GitHub DNS 与 `gh` 认证后读取远端仓库、标签、Release、更新源与 CI 状态，确认目标 v0.2.0 未占用或确定下一个未占用版本；记录实际查询结果及最终版本，不能仅凭本地推定。

## 2. 源码与数据身份

- [ ] 2.1 统一 CLI 入口、PHP 命名空间和引用、版本/帮助/诊断文案及 `WEBMAN_AOT_BUILDER_` 配置键；运行 PHP 语法检查和新命令 `version`、`help`、错误路径验证。
- [ ] 2.2 将 Mac/Windows 用户根目录、项目缓存键、自有 schema 与诊断/清单身份改为新名；在预置旧目录和旧环境变量的隔离环境验证新版不读取或修改旧状态。
- [ ] 2.3 核对新版 `self-update` 的默认 manifest URL、真实签名 manifest、受信公钥与新仓库 HTTPS 资产；在新根目录实跑签名验证、升级及回退，并验证旧版 `self-update` 不被当作命令和目录迁移路径。当前默认 URL 和 `update-trusted-keys.json` 缺失，本项不得仅凭源码通过；若发布时仍缺项，保持未完成并明确说明自动更新源不可用，不编造密钥。

## 3. 安装与构包

- [ ] 3.1 统一 Mac 与 Windows 安装/卸载入口、命令链接、目录权限与提示；分别验证新装、旧版并存、重装和卸载只作用于新版所有物。
- [ ] 3.2 更新构包脚本、发布清单、CI 与仓库 URL，生成四种新名候选安装包；逐一核对包内入口、版本、许可证和文件清单。
- [ ] 3.3 更新 `tools/sanitize-macos-cli-runtime.php` 的签名 identifier 与构建前缀，以及 `tools/build-macos-compiler-driver.sh` 的编译前缀；从锁定输入重新构建 Mac PHP CLI/compiler driver，验证签名、可执行性和新身份后，将实测二进制 SHA-256 写入 `installer/runtime.lock.json`。旧摘要未替换前本项不得勾选，也不得进入发布。
- [ ] 3.4 取得当前本机缺失的 Mac/Windows 精简组件 ZIP；从锁定输入重建两平台组件，或提供内容与清单验证后重封装的证据；更新组件锁、工具链指纹和所有 SHA-256，验证轻量包下载校验及完整包离线准备。
- [x] 3.5 生成并逐文件核对本地 v0.2.0 r2 的四种候选安装归档；`/private/tmp/webman-aot-builder-v020-r2-logs-20260927/archive-verification.log` 记录四包摘要及 payload 校验通过。最终文档与 NOTICE 更新后的 r3 仍须重构包并复核，故 3.2 未完成。
- [x] 3.6 修正并校验新版 Mac relink 材料：`/private/tmp/webman-aot-builder-v020-r2-logs-20260927/relink-verification-final.log` 记录 59,944 个文件、1,447 个目录、137,607,655 字节通过，最终归档 SHA-256 为 `380880904e1aaa444d1c3485eb0a2733d47bc4fd8ec6e2fd7499ed45d384eeda`；同目录 `relink-selftest-cli-modified.log` 与 `relink-selftest-driver-modified.log` 记录修改源码后的 CLI/driver 重链接自检通过。旧 r2 首包 `7f59d6cd0cf78c58962bf5464e752a334550258101eb7a91258b4c224d404c12` 缺少 README 所需 OpenSSL 链接参数，已被取代，不得发布。

## 4. 使用者说明与本地验收

- [ ] 4.1 更新 README、安装/构包/验收/兼容说明、图示及源码内公开链接，以新仓库与新版本给出可照做步骤；检查旧版 v0.1.3 仅作为历史和迁移说明出现，逐个验证链接与示例。
- [ ] 4.2 在 Mac 实跑轻量包、完整包的安装、`version`、`doctor`、`build`、`verify`、卸载及常见失败恢复；记录命令、结果和未验证范围。
- [ ] 4.3 在 Windows 实跑轻量包、完整包的安装、`version`、`doctor`、`build`、`verify`、卸载及旧版并存；记录命令、结果和未验证范围。
- [ ] 4.4 对 Mac 和 Windows 候选生成的 Linux amd64 发布目录核对静态属性、产物一致性及既定项目业务路径；按平台记录实际宿主、输入、结果和限制。
- [ ] 4.5 在 Mac 与 Windows 以 `tools/build-full-static-smoke.php` 的新 prefix 和同一锁定输入重跑完整静态 smoke，记录实际 normalized input 与 Linux 产物 SHA-256；两平台实测一致后刷新 `tools/windows-replay.ps1` 的 `expectedNormalizedInputSha256`、`expectedMacArtifactSha256` 并复跑比较。旧预期摘要未刷新或任一平台缺失时不得勾选，也不得宣称跨宿主一致。
- [x] 4.6 在 Mac 安装本地 r2 轻量包与完整包，并从中立 Webman 项目执行 `doctor`、`build`、`verify`；`/private/tmp/webman-aot-builder-v020-r2-logs-20260927/` 的安装、doctor、build、verify 日志显示成功。4.2 所含卸载与失败恢复尚未验收，故仍未完成。
- [x] 4.7 在 Mac 用新版 prefix 完成全静态 smoke，并在 Linux amd64 Docker 环境运行该 ELF；实测 normalized input SHA-256 为 `f18bac511781f372bb65e4fe4b4ac518535792a4d57b896f84b050f540419ba2`、ELF SHA-256 为 `95005fdcfa288464fefa5fd88f32886a0ae4cd30f7fa698103a2647a344f56e3`，见同目录 `full-static-smoke-r2.log` 与 `linux-static-runtime-r2.log`。Windows 同源复跑、裸机和业务路径未验收，故 4.4、4.5 未完成。

## 5. 固定候选与发布

- [ ] 5.1 审查实际 diff、源码和资产旧名残留、锁/哈希/签名关系，执行项目既定回归、`openspec validate --strict` 和发布候选独立验收；确认自更新的真实 manifest、受信公钥和升级回退证据齐全，否则在候选及发布说明中标为未完成且不宣称自动更新可用。
- [ ] 5.2 在两平台与 Linux 发布门槛通过后，将 GitHub 仓库更名为 `supdger/webman-aot-builder`，提交并推送 feature branch 的获授权变更，按既定合并流程进入发布分支；核对远端内容和新仓库地址。
- [ ] 5.3 为已确认版本打 tag，创建 Release 并上传四种安装包、组件和 SHA-256 清单；核对发布页文件名、摘要、下载、安装和更新源，记录已发布与线上验证事实。
