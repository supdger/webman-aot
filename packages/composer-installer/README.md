# Webman AOT Builder Composer 入口

通过 Composer 全局命令准备固定版本的 Webman AOT Builder，再在自己的项目目录构建 Linux x86_64 程序。支持 macOS Apple Silicon、Windows x64；入口版本为 0.3.3，使用原构建器 0.3.2 完整安装包。

源码与发行包见[本仓库](https://github.com/supdger/webman-aot-builder)。Composer 入口与仓库标签共用 0.3.3，使用固定的 0.3.2 完整运行时；可安装版本以 [Packagist 包页面](https://packagist.org/packages/saiadmin/webman-aot-builder)为准：

```sh
composer global require saiadmin/webman-aot-builder:^0.3.3
```

需系统 PHP 8.1 或更新版本、Composer，以及 macOS 的 curl/tar 或 Windows 的 curl.exe/Windows PowerShell 5.1。目前作者运行验证使用 PHP 8.4；PHP 8.1 和 Windows 实机验收尚待完成。运行 `composer global config bin-dir --absolute` 可查看命令目录，将它加入终端 PATH 后重新打开终端。

已有原版 `webman-aot` 时，将 Composer 命令目录放在旧命令目录之前，或使用代理完整路径，避免继续运行旧入口。先确认 `--version` 显示入口 0.3.3、目标构建器 0.3.2；这不表示平台资源已经安装。

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

0.3.3 只表示仓库发行标签与 Composer 入口；原运行时固定 0.3.2。根 `composer.json` 注册元数据的 bin/autoload 路径与小 ZIP 保持同样的 `packages/composer-installer/` 布局。普通 Composer 安装取得轻量 Release ZIP，`--prefer-source` 会下载完整源码仓库。

开发检查（在原仓库根运行）：

```sh
composer validate --strict
php packages/composer-installer/tests/run.php
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
