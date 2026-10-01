# Webman AOT Builder Composer 入口

通过 Composer 全局命令准备固定版本的 Webman AOT Builder，再在自己的项目目录构建 Linux x86_64 程序。支持 macOS Apple Silicon、Windows x64；入口版本为 0.3.4，使用原构建器 0.3.2 完整安装包。

源码与发行包见[本仓库](https://github.com/supdger/webman-aot-builder)。Composer 入口与仓库标签共用 0.3.4，使用固定的 0.3.2 完整运行时；可安装版本以 [Packagist 包页面](https://packagist.org/packages/saiadmin/webman-aot-builder)为准：

```sh
composer global require saiadmin/webman-aot-builder:^0.3.4
```

需系统 PHP 8.1 或更新版本、Composer，以及 macOS 的 curl/tar 或 Windows 的 curl.exe/Windows PowerShell 5.1。目前作者运行验证使用 PHP 8.4；PHP 8.1 和 Windows 实机验收尚待完成。运行 `composer global config bin-dir --absolute` 可查看命令目录，将它加入终端 PATH 后重新打开终端。

已有原版 `webman-aot` 时，将 Composer 命令目录放在旧命令目录之前，或使用代理完整路径，避免继续运行旧入口。先确认 `--version` 显示入口 0.3.4、目标构建器 0.3.2；这不表示平台资源已经安装。

macOS：

```sh
aot_bin="$(composer global config bin-dir --absolute)"
"$aot_bin/webman-aot" --version
```

Windows PowerShell：

```powershell
$aotBin = composer global config bin-dir --absolute
& (Join-Path $aotBin 'webman-aot.bat') --version
```

进入包含 `composer.json`、`composer.lock` 和 `start.php` 的 Webman 项目目录：

```sh
webman-aot build
# SaiAdmin 项目：
webman-aot build --profile=saiadmin
```

首次交互运行会下载、校验并安装对应平台的完整包，显示真实下载及安装进度；成功后在原项目目录执行原命令。私有 PHP 和工具链保存在独立 `webman-aot-composer` 用户数据目录，不覆盖原安装，不改系统 PATH。指定状态目录已有运行时或启动器而没有本入口所有权记录时会拒绝接管，需另选空目录。大型平台安装包不会放进 Composer 的 vendor 目录。

网络连续失败时，入口显示需要的**完整安装包文件名和下载链接**。下载后按回车检查常规 `Downloads` 目录，或将文件拖入终端输入完整路径。自定义下载目录无法保证自动找到；可明确指定：

```sh
webman-aot setup --archive="/完整包所在目录/对应完整安装包" --non-interactive
```

`components.zip` 只有编译资源，不能代替完整安装包。所有导入必须通过固定大小、SHA-256、平台和版本校验；不匹配的包不会执行。

非交互环境不会等待输入。首次准备可显式使用 `webman-aot setup --yes --non-interactive`，或前述本地包命令；资源已准备后直接运行构建。全局选项必须放在 `doctor`、`build` 等原命令之前；原命令后所有参数（含 `--`）原样转交构建器。`setup` 自身的选项可放在后面。`--state-dir=目录` 将运行时、缓存和启动器全部放在指定目录，适合隔离测试。`--help`、`--version` 不联网，只说明入口和目标版本，不表示构建器已经安装。

下载中可用 Ctrl+C 取消。准备失败时原项目命令不会运行；保留缓存方便重新校验重试。准备成功后会重新执行原命令，不提供编译断点续跑。

构建器详细安装、兼容性与 Linux 部署要求见[现有 Wiki](https://github.com/supdger/webman-aot-builder/wiki)。现有安装包与发行版的行为保持不变。Packagist 登记状态以[包页面](https://packagist.org/packages/saiadmin/webman-aot-builder)为准；没有自动镜像切换，网络不可用时使用已校验的本地完整包。

0.3.4 只表示仓库发行标签与 Composer 入口；原运行时固定 0.3.2。根 `composer.json` 注册元数据的 bin/autoload 路径与小 ZIP 保持同样的 `packages/composer-installer/` 布局。普通 Composer 安装取得轻量 Release ZIP，`--prefer-source` 会下载完整源码仓库。

0.3.4 新增统一卸载入口；旧 0.3.3 和原生 0.3.2 没有这个命令。请先更新 Composer 包，然后从代理完整路径启动：

```sh
webman-aot uninstall --list
webman-aot uninstall
```

清理旧安装后改用 Composer：

```powershell
composer global require saiadmin/webman-aot-builder:^0.3.4
if ($LASTEXITCODE -ne 0) { throw 'Composer 安装失败，未开始卸载。' }
$aotBin = (composer global config bin-dir --absolute).Trim()
& (Join-Path $aotBin 'webman-aot.bat') uninstall --list
& (Join-Path $aotBin 'webman-aot.bat') uninstall
& (Join-Path $aotBin 'webman-aot.bat') uninstall --list
& (Join-Path $aotBin 'webman-aot.bat') --version
```

按列表对旧原生版本、旧公开命令和历史备份选择 `y`；对“Composer 全局包”选择 `n` 保留入口。需要清空已有 Composer 私有运行时以重新准备时，单独确认该状态项；安装锁与用户文件保留。若也选了卸载 Composer 全局包，需再次运行首行安装命令，然后重新取得命令目录。最后版本应显示入口 0.3.4、目标 0.3.2；再从项目目录通过该代理运行 `build`，首次构建自动准备固定完整运行时。上述参数不修改用户 PATH，旧空 PATH 目录可另行核对整理。

先只读查看类型、静态版本和绝对路径，再逐项输入 `y` 卸载、回车保留或 `q` 结束；非交互环境始终保留。入口不会先下载或安装运行时。自定义状态目录使用 `webman-aot uninstall --state-dir="目录"`；自定义原生目录可用 `--home="目录"`、命令目录用 `--bin-dir="目录"`。仅清理可确认归属的选中对象，未知旧入口保留并显示精确路径；Composer 全局包由 Composer 移除这一包，其他全局工具保留。Composer 状态根的 `setup.lock` 与额外用户文件保留，避免并发安装换锁；项目产物、共享工具链与 PATH 保留，旧备份命令不会恢复；失败返回非零并列出残留。

开发检查（在原仓库根运行）：

```sh
composer validate --strict
php packages/composer-installer/tests/run.php
php packages/composer-installer/tests/uninstall.php
```

本地验收不需要发布。先建立一个临时 Composer 工作目录，在该目录创建 `composer.json`，其中 `url` 改成此包的绝对目录：

```json
{
  "repositories": [{"type": "path", "url": "/原仓库绝对路径", "options": {"symlink": false}}],
  "require": {"saiadmin/webman-aot-builder": "@dev"}
}
```

在临时目录运行 `composer install --no-plugins --no-scripts`，再执行 `vendor/bin/webman-aot --help`。准备与后续验证也使用同一个显式独立目录：

```sh
vendor/bin/webman-aot setup --state-dir="/临时目录/aot-state" --archive="/完整包路径" --non-interactive
vendor/bin/webman-aot --state-dir="/临时目录/aot-state" --non-interactive doctor
```

构建时进入自己的项目目录，以临时工作目录下 `vendor/bin/webman-aot` 的完整路径执行 `--state-dir="/临时目录/aot-state" --non-interactive build`。Windows 的 Composer 会生成对应 `.bat` 代理，使用该代理运行。以上用于验证本地修改；这套本地检查验证修改后的包布局与命令行为，公开发行与注册信息以 Release 和 Packagist 页面为准。
