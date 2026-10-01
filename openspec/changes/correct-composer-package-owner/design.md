## Decisions

发行者为supdger，Composer包supdger/webman-aot-builder，PSR4 Supdger\WebmanAotInstaller。bridge0.3.5固定使用既有runtime0.3.2。新Installer不接受历史saiadmin owner，拒绝时提示新proxy uninstall精确state-dir；未知owner不提供错误的历史迁移归因。

卸载名单只包含supdger/webman-aot-builder和历史saiadmin/webman-aot-builder，state须schema1；每item保存package，取得状态锁后再核owner，保留原setup.lock inode。globalhome按path+package列项，Composer remove参数按选中精确package。旧公有native模板/入口hash保留，仅追加新版bootstrap的namespace字节hash；未知launcher不执行。

两包使用相同bin名称，推荐先composer global remove旧包再require正确包。新proxy先列项/明确清旧state/native、n保留supdger全局包。旧state清完只留锁和用户数据，新setup可重新claim该目录，需实际验证该边界。旧tag/Release不可变，旧Packagist记录不删；新包publicindex及真实默认require通过后，主Agent精确设置旧record abandoned/replacement。

## Release Gate

19项入口检查及新增卸载迁移检查，严格metadata/11文件source-identical小ZIP、真实localZIP迁移/self-remove、独立审查；fixed PASS后PR合并/tag/nonlatestRelease/新包注册、真实官方消费、Wiki公开reread与旧资产不变。Windows物理导出auto_review曾拒，不再重试；PHP8.1/Windows实机明确未跑，不发新fullnative。


## Publication Outcome

正确新包0.3.5已注册、非latest轻量Release已公开，默认官方实际安装/自卸载通过；完整证据在本change任务记录。旧registry弃用replacement没有执行：旧管理页redirect package_not_found，而只读API/p2呈不同层状态；未尝试删除或重建，外部原因未知。历史GitHub发行/tag/下载不可变。最终准出可进入灰度仅Supdger Composer切片，Windows/PHP8.1及fullruntime重装未宣称通过。
