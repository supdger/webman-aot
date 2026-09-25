# SaiAdmin AOT 兼容与迁移

当前验收组合是 SaiAdmin 6.1.5、Webman 2.2.4、Workerman 5.2.2、
PHP 8.4.25、TypePHP 0.9.2 和仓库锁定的 Composer 依赖及静态 SDK。
Mac ARM64 构建的 Linux amd64 产物已在隔离的 Ubuntu 24.04 x86_64
虚拟机通过验证码、登录、用户信息和权限拒绝验收。Windows x64 同版
SaiAdmin 完整重建的 ELF、规范化输入及分发文件摘要与 Mac 产物一致；
裸机发行版矩阵与最终发布验收尚未完成。依赖版本或源码结构漂移时，
工具会失败并要求重新适配，不声称兼容所有 SaiAdmin 或第三方插件版本。

## 使用

目标项目不需要安装 AOT Composer 插件。安装全局 `webman-aot` 后，在
SaiAdmin 后端目录运行：

```sh
webman-aot doctor
webman-aot doctor --repair
webman-aot build --profile=saiadmin
webman-aot verify --path=dist-aot
```

`doctor` 只检查；只有 `--repair` 才下载、校验并准备私有工具链。
`build` 会在隔离副本里生成 AOT 适配，不改普通 PHP 源码。Mac ARM64
和 Windows x64 都以同一套源码构建 Linux amd64 musl 全静态产物；
Windows 可执行文件不是本阶段目标。`verify` 证明包完整性和静态结构，
不代替目标 Linux 上的启动、业务接口与数据库验收。

## 已处理的运行差异

- Webman、Workerman、ThinkORM、Carbon、Symfony 与 SaiAdmin 的编译期
  改写均受版本、源文件摘要和生成结果摘要约束；未知写法不会被静默排除。
- TypePHP 生成的 Symfony Intl polyfill 会与静态 PHP SDK 的原生 Intl
  函数重复注册。AOT 副本只保留 SDK 缺少的两个补充函数，其余由原生
  Intl 提供；原始 Composer 包不改。
- `webman/captcha` 1.0.5 的五个字体文件是运行资源，已纳入受摘要校验的
  产物清单。缺少字体时，即使服务启动，验证码仍会返回业务错误。
- TypePHP 生成匿名类时会给部分方法补 `mixed` 返回类型；原方法中
  合法的 `return;` 因此需要同时改成等价的 `return null;`。特例
  `parent` 类型必须保留为 PHP 关键字，不能解析成命名空间类名。
  这两处在锁定 TypePHP 0.9.2 源码上以小补丁修正，不改业务或依赖源码。
- Windows 的构建 PHP 可以加载 OPcache，Mac 的构建 PHP 则没有。TypePHP
  原本据此选择不同的匿名类嵌入方式，导致 Windows ELF 带入宿主路径。
  全静态 Linux 目标统一使用源码嵌入；不改变 TypePHP 的普通目标模式。
- TypePHP 曾将构建宿主的 `PHP_EOL` 直接写入生成代码，且会把 Windows
  独有的三个 `sapi_windows_cp_*` 函数当成 Linux 内建函数。全静态目标
  分别固定 Linux 换行值、排除这些 Windows 函数；否则两端会生成不同
  的代码和函数编号。
- 全静态目标的 `PHP_MAXPATHLEN` 和 `T_*` Token 编号不能取构建宿主的
  PHP 值：前者按锁定的 Linux musl SDK 为 4096，后者从目标 SDK 的
  `zend_language_parser.h` 读取。
- TypePHP 扫描目录时必须按统一为 `/` 的路径比较键排序；直接比较本机
  路径会让 Mac 的 `目录/文件` 与 Windows 的 `目录\文件` 排序不同，
  连带改变类注册和稳定编号。此修正只改变编译器的扫描顺序，不拆分
  SaiAdmin 业务源码。
- Linux 全静态目标不得从 Windows 构建 PHP 反射 `sapi_windows_*` 的
  参数和返回信息；这些函数不是目标 Linux PHP 的内建函数。该屏蔽仅在
  `--full-static` 下启用，普通 PHP 与其他 TypePHP 目标模式保持原状。
- `dist-aot/.env` 是部署时外置文件，不从源码项目复制。更改数据库连接
  不需要重编业务 ELF；需重新运行真实接口验收。

## 普通 PHP 8.4 的独立迁移点

SaiAdmin 6.1.5 的 `plugin/saiadmin/exception/SystemException.php` 使用
`Throwable $previous = null`。普通 PHP 8.4 在 `E_ALL` 下首次触及权限
拒绝时会发出隐式可空参数弃用警告，项目异常处理器可能把它转为
业务 500。这不是 AOT 编译失败。等价修正是：

```php
public function __construct($message, $code = 400, ?Throwable $previous = null)
```

该改动已在隔离的普通 PHP 8.4.18 回归副本中验证：保留原始
`config/app.php` 的 `E_ALL`，隔离文件缓存后，验证码、登录、
用户信息和权限拒绝四条路径均通过；一次性数据库及账号已清理，
隔离副本的源码已恢复。工具不会擅自修改目标项目。
回归时应保留 `E_ALL`，不要靠关闭弃用警告掩盖这个问题。

## 新代码约束

业务 PHP 不应依赖顶层执行语句、变量变量、`switch` 隐式落空、
非可变参数接收额外参数、动态调用中的隐式引用、
`Closure::bind()`/`bindTo()`/`call()`、动态 `include`/`eval`、
不稳定的魔术静态调用，或同一局部变量跨不兼容类型重赋值。
配置、模板和明确登记的第三方动态文件可以随包；自有业务 PHP 和
已安装插件的业务 PHP 必须直接编译或由可追溯的 AOT 副本替代。
新增插件后应重新执行构建和业务验收，不能把 ELF 生成视为功能成功。
中立插件示例见 [`tests/fixtures/neutral-plugin`](../tests/fixtures/neutral-plugin/README.md)；
其控制器在隔离构建中直接编译，Linux 与普通 PHP 的 HTTP 路由均已实测。
