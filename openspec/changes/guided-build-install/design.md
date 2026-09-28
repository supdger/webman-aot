## Context

权威源码为 https://github.com/supdger/webman-aot-builder.git，基线 61634057a3eb8dee571ac79e044afe030da73a27。动机见 proposal。Windows tools/build-windows-installer.ps1 已能自举锁定 PHP、调用 PHP 构包并在临时目录执行安装/version；tools/build-windows-installer.php 已复用 NativeDownloader 并验证 ZIP 清单。package-installers.php 输出机器 JSON，macOS 包要求 PHP runtime、compiler driver、许可和 PHP 源归档；目前缺统一 macOS 源码入口，旧 Wiki 手写下载重链接材料和参数。两平台安装器已有事务恢复和完整包 offline-prepare。Application 的 build 使用工作目录，verify 可检查 dist-aot 并输出实际 scope。

## Goals / Non-Goals

**Goals:** 保留现有下载、锁定和安装事务，以薄原生启动层和共享引导流程承载菜单、结果及项目下一步。默认面向中文使用者，术语首次出现说明用途。

**Non-Goals:** 不建立 GUI、通用任务框架或新下载框架；不自动下载业务项目依赖、修业务源码、启动服务或部署 Linux。源码已有 Git checkout 或下载后解压是源码路径前提。直接安装路径由独立setup文件自动下载与解压，包内install入口仅作为离线/已有包的补充，不能替代独立下载入口。

## Decisions

1. 源码根提供 `build.cmd`、`build.command`；包根提供 `install.cmd`、`install.command`。名称直接表达动作，README只保留入口及支持平台。原生文件定位自身目录，正确处理空格/中文，限制支持平台并保留退出码。macOS command须带可执行位且 tar 保留；系统下载隔离/首启安全提示须按真实系统记录，不能声称绕过系统限制。
2. 共用 PHP 引导入口 `tools/guided.php`，核心落 `src/Guided/`。模式为 `--mode=source|install|project`，显式路径传参，复用现有 autoload。source模式在自举 PHP 后显示包类型菜单，再调用平台构包后端；install模式由包内PHP启动。流程逻辑与项目 build/verify 只在共享层维护。原生层负责尚无 PHP 时自举、独立setup的包类型选择及锁定下载、平台进程约定和终端停留；取得已核验包内PHP后立即交给共享层，安装/项目菜单不在原生层重复实现。相比两套菜单实现，减少跨平台错误/下一步分叉；不把已有CLI默认行为改为交互。
3. 平台源码后端保持现有 Windows接口；新增 macOS 构包后端，将 Wiki 重链接材料流程移入仓库。用任务所需 source-build 材料锁记录 macOS relink archive HTTPS 地址、摘要和内部相对路径；它必须与 runtime.lock 中二进制摘要联查，不能只信 Wiki 或旧已安装 generation。Windows PHP自举抽取最小可复用准备步骤供新入口和旧入口调用，不复制下载算法。Mac自举前无法使用PHP读取JSON，启动层可用系统工具或专用锁定字段读取方式，但必须验证严格字段且保持一个锁定来源。锁内 relink材料摘要需实现者向权威发布记录/实际下载验证，再固化。
4. 统一 source后端结果文件用于引导消费（可新增显式 `--result`/`-Result` 参数），包含平台、flavor、revision、archive绝对路径、size、sha256和自检范围。只有成功才产生结果；引导核对实际文件再显示成功。底层package-installers的原JSON stdout保持不变，内部JSON进入日志/结果文件，人用入口展示摘要。包构建后由共享层解压到新私有目录并启动包内installer，选择安装前不写真实用户安装。既有Win自检可复用，不重复两次。
5. 实时执行器使用参数数组启动子进程，连续读取输出并落本次日志；机器JSON捕获范围只限明确的结果接口，其他输出及时转述/显示，长任务5秒补充阶段和耗时。输出最终错误时包含阶段、退出码、原错误摘要和恢复建议；网络/DNS/TLS/HTTP/摘要错误按可证实错误分类，不凭猜测改网络。日志只本地保留，不自动发送；明确Issues入口。重试复用已校验缓存，不允许未验证输入继续。
6. 安装前显示平台默认目录和PATH影响，确认之后调用现有 install.sh/install.ps1；可显式指定 home/bin/no-path 支持隔离验收。安装后绑定本次新安装绝对启动器及WEBMAN_AOT_BUILDER_HOME运行version，full沿用offline自检；small只确认工具可运行，并说明首次构建会自动下载组件。不得用用户已有PATH命令冒充新安装。
7. 同一菜单提供构建项目或结束；目录输入是用户选择，可重选。共享层设置子进程cwd后调用新安装 `build`，成功后调用 `verify`，用现有机器结果/人话结果显示真实验证范围与产物。失败不继续下游，不自动执行doctor命令之外的新修复机制。安装后运行不强迫用户重开终端。CLI既有build/verify默认和JSON兼容。
8. 自动化通过显式参数选择flavor/操作/项目路径，安装必须显式opt-in；EOF视为安全取消，非TTY不pause。双击窗口保留结果，但子进程失败仍保留非零退出码。安装确认、取消、无效输入、含空格/中文目录、损坏摘要、子进程失败均必须行为验收。

## Risks / Trade-offs

- [原生Windows目前未证实可用] → 本机Darwin arm64有PHP/ssh，无pwsh且~/.ssh/config不存在；独立环境检查已确认保存的Windows SSH连接8秒超时(exit 255)，尚未达到认证，当前无通用remoteexecAPI。不能以shell语法或macOS构建ZIP代替Windows原生验收。
- [重链接材料较大，初次自举需下载] → 锁定输入缓存、校验复用、明确当前下载材料及实际进度；不要求先安装系统PHP。
- [包内新入口遗漏或权限丢失] → package-installers显式收集及archive检查，从实际输出包启动验收；不仅运行源码中的脚本。
- [旧安装/源码变更混淆产物身份] → 记录当前revision与dirty状态，不能仅因version相同宣称等同官方包；自检私有home。
- [原生外壳失败后窗口关闭] → 原生层对bootstrap错误同样输出原因/Issues并交互停留，保留退出状态。

## Migration Plan

新增入口保持旧脚本可调用，安装事务/卸载语义不替换。发布前同时验收small/full两平台包、原JSON调用方和新菜单；本轮只交付源码与本地证据，无commit/push/release。详细说明放独立Wiki候选，原始过程日志放任务tmp，不进入仓库。OpenSpec初始化生成的技能是CLI附带入口，不扩展成另一套任务板。

## Implementation Handoff

共享入口合同固定为 `tools/guided.php --mode=source|install|project`；源码模式root由文件定位。包入口传 `--package-root=<absolute>`，用包内PHP运行包内 `payload/app/tools/guided.php`；源码自举后用本次锁定PHP运行源码同入口。项目模式/安装后绑定实际 `--home` 与 `--bin-dir`，`--no-path`供隔离安装。支持显式 `--flavor=small|full`、`--install` 和 `--project=<absolute>`，无`--install`不在非交互情况下安装。参数最终解析和帮助只由包A维护，B/C不重复实现菜单。

包A拥有 src/Guided/**、tools/guided.php 及专属行为测试；实现进程/日志/选择、解压安装衔接、项目build/verify。包B拥有 build.cmd/build.command、tools/build-windows-installer.ps1/php及新增源码后端/自举辅助和源码材料锁；不修改包A/C文件。Windows后端新增 `-Result <absolute>` 传至PHP `--result=<absolute>`；macOS后端为 `tools/build-macos-installer.sh --flavor=small|full --result=<absolute>`。结果schema为 `webman-aot-builder-source-build-result-v1`，字段 `platform, flavor, revision, archive, size, sha256, verified`，`archive`为绝对路径，`verified`数组含 `payload-manifest` 与 `isolated-install-version`。结果须在整条后端成功（包含自检）后才发布。包A按这些字段验真后显示结果。首次bootstrap复用B负责的锁定准备能力，实际PHP路径仅作为子进程路径，不从shell字符串拼接执行。

包C拥有 installer/windows/install.cmd、installer/macos/install.command、必要平台引导辅助与tools/package-installers.php；产物根复制为 install.cmd/install.command，包内共用引导工具通过packager显式收集。已有底层install.ps1/install.sh仅在确有流程缺口时由C改动，需保持参数兼容。C测试从实际包解压目录启动，A以相同入口接入构包后的安装；B使用已有底层安装器做隔离自检，无需等待新菜单。

B和C可以在上述文件边界并行；共享合同需要更改时先通知主控和另一实现者。实现者只回报自身任务结果，tasks.md由协调指定单一写者更新。Wiki候选和README另行指定所有权。

Windows入口须支持 `-InstallRoot`、`-BinDir`、`-NoPath` 隔离参数透传，并映射到共享流程的 home/bin/no-path；安装后使用本次绝对launcher及私有 WEBMAN_AOT_BUILDER_HOME。远端Windows当前缺口由独立环境包记录，不阻止无依赖源码实现，但限制Windows原生通过声明。

Windows现有源码PS把scratch创建在源码盘根，隐含盘根写权限；未在相关安装器/工具链准备器找到明确禁止空格或MAX_PATH的合同。包B须修复普通用户无需提权的落点，优先源码已可写dist内私有短名目录（同盘），保留长路径清理和任务所有权。不得直接要求管理员或盲目依赖全局TEMP；如实际Windows工具存在短路径约束，须以原生失败证据调整可写目录选择。空格/中文/较长路径原生验收未通过前不得声称该路径兼容。

## Direct Download Setup

这是原用户要求中遗漏的直接下载安装路径，属于同一目标补全。产品分三种入口：源码build、独立下载setup、包内install。独立setup不能依赖源码、预装PHP或用户手工解压；不改变A/B已固定合同。

包C增加 `tools/package-setup.php` 和 `installer/windows/setup.cmd.in`、`installer/macos/setup.command.in` 模板（或同等职责的少量模板文件）。独立生成器接受 `--small-result=<packager-json>`、`--full-result=<packager-json>`、`--base-url=<https-release-directory>`、`--output=<directory>`。输入复用现有package-installers JSON文件，明确选择同平台对应package；若JSON含多个平台，必须用显式 `--platform=windows-x86_64|macos-arm64`，不得猜测。生成器读取两份实际产物并校验摘要、size、package.json版本/平台/flavor/revision一致性，然后从实际归档basename构建两个HTTPS地址。脚本常量包含version/platform及small/full的filename/url/sha256/size；无需额外远端manifest或新注册表。

每个平台产出一个自包含版本命名的 `webman-aot-builder-<version>-<platform>-setup.cmd|command`。Windows单cmd可内嵌PowerShell引导段，不要求另下载ps1；macOS以系统sh/curl/tar/shasum启动。模板只允许替换已严格校验和正确转义的固定值，拒绝带命令语法/换行的revision、路径和URL。生成器不上传、不创建Release、不声称远端资产已存在；生产URL绑定将在发布阶段单独验证。setup在包外生成，包hash不会包含setup自身，不存在循环摘要；原packager stdout schema和默认操作不变。

setup流程为平台检查→选择small/full或取消→显示版本与下载量→下载到用户可写私有缓存→验证外层SHA-256→检查归档条目及路径不得逃逸私有解压目录→解压→调用包内install入口（或直接包内PHP tools/guided.php --mode=install --package-root=...）→共享安装确认及项目下一步。禁止在外层摘要验证前执行包内任何代码。归档拒绝绝对路径、上级跳转、可逃逸链接等危险条目；解压目标新建且不复用不明既有目录。下载只HTTPS并保持TLS校验，已验证缓存可复用。完整包下载仍需联网；取得完整包后的安装保持离线，这是两个不同阶段。

setup自动化参数沿用平台习惯：Windows `-Flavor small|full -Install -InstallRoot ... -BinDir ... -NoPath -Project ...`；macOS `--flavor=small|full --install --home=... --bin-dir=... --no-path --project=...`。参数映射A既有合同，无显式安装同意时非交互不安装，EOF不隐式同意。可接受显式 `--archive`/`-Archive` 指向已下载包用于离线复用与隔离验收，但仍匹配脚本内对应flavor的SHA，不支持任意覆盖SHA或关闭TLS。引导未进入共享PHP前的错误也须实时状态、原退出码、日志位置/Issues和交互停留；进入共享层后复用A。

C负责producer、独立bootstrap模板与测试，不改A/B文件。生成本地可审阅脚本属于授权实现，外部发布需要后续授权。Mac默认分发为独立setup ZIP，ZIP内仅包含可执行command（可用单层目录），UNIX文件模式固定0100755；生成器另保留raw command用于维护者单命令运行，但不能将裸下载command作为默认双击资产。使用者下载ZIP后用系统打开归档，再打开其中启动文件，均为系统打开动作，不要求手写解压或chmod命令。Safari是否自动展开依用户设置，不能作为唯一前提。浏览器quarantine及系统首启必要确认须真实验收，不移除quarantine、不绕Gatekeeper；无需.app、Apple证书或新GUI框架。


### macOS launcher distribution evidence

本机临时夹具 `/Users/supdger/.tmp/guided-build-install-20260928/mac-launch-proof-1790579175/result.json`：使用系统ditto生成ZIP，归档entry模式0100755；系统ditto解压后含中文名称`启动安装.command`为0755，无chmod直接exec输出MAC_SETUP_FIXTURE_OK并exit0。它证明ZIP保持执行位与原生可执行性，不证明浏览器下载/ArchiveUtility/Finder双击。

Finder原生UI验证调用Computer Use失败，明确返回`Computer Use was not approved to use Finder`；这不是本地Hook或自动审核拒绝，不通过open/osascript换入口绕过。用户随后明确纠正“你为什么要用Finder，你不能用cli吗”。本轮停止所有Finder/GUI操作，不申请GUI权限；通过CLI完成ZIP元数据、系统解压、直接执行和退出码验收。Finder/ArchiveUtility双击及真实浏览器quarantine首启作为单独未测范围如实报告，不阻塞当前源码实现，不宣称双击整链已通过。

## Integrated source snapshot fix

用户“整合交付”要求将已验收的 `.DS_Store` 误判修复纳入本变更；不另建change。来源为 `/Users/code/project/webman-aot` 的 `codex/mirror-source-snapshot`：仅 `src/Project/ProjectMirror.php`、`src/Project/SourceTreeSnapshot.php` 与 `tests/project-mirror.php`。B为这三处唯一写者并负责新Mac small/full构包，A仅验证实际并发镜像错误经Flow呈现；C负责新setup/ZIP→私有安装→109单元项目build/verify的唯一完整组合，避免A重复编译；独立上下文再验收。既有composer.lock问题用户已自行解决，不扩入本次源码修复。

两处递归过滤均精确匹配文件名 `.DS_Store`，避免仅快照忽略而镜像仍复制的不一致。保留原`capture()`返回合同；新增`captureWithFiles()`供镜像前后比较每文件摘要，失败描述包含added/removed/modified、安全相对路径、最多5条及剩余条数，长路径截断，控制字符JSON转义，不泄漏文件内容。失败候选清理沿用现有事务。

现有 `src/Guided/ProcessRunner.php` 实时转发子进程stdout/stderr，`Flow::execute`保留末2000字错误摘要，镜像诊断回归限制在1000字内；默认恢复建议附原错误及同入口重试。因此无需预先修改Flow。A必须用实际修改后的镜像失败触发引导错误路径，确认可读相对路径和重试建议、非零退出及verify短路；若实测丢失原因才按最小失败证据修改，不新增推测性错误框架。

历史E1-E10仅证明整合前候选。菜单/参数/下载/TLS行为在对应文件未变时仍可复用；源码指纹、镜像错误与包内源码已变，新包必须重新算摘要、重生setup绑定、由C私有安装后确认修复并走项目build/verify；A仅刷新实际错误呈现证据。旧small/full SHA绝不继续标作最终候选。原始整合收据落任务tmp `/Users/supdger/.tmp/guided-build-install-20260928/integration`，不得将fixture/log/vendor/dist纳入仓库。Windows原生未验，提交/推送/发布无授权，保持边界。
