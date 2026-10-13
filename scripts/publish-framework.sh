#!/usr/bin/env bash
#
# 发布 Composer 包到 Packagist（subtree split 方式）
#
# 用法：
#   ./scripts/publish-framework.sh           # 发布 core + laravel + framework + region
#   ./scripts/publish-framework.sh laravel   # 只发布 laravel 驱动
#   ./scripts/publish-framework.sh core      # 只发布 core（拆包后的内核）
#   ./scripts/publish-framework.sh framework # 只发布 framework
#   ./scripts/publish-framework.sh region    # 只发布 region
#
# ⚠️ core 必须先发 —— framework 依赖它。只传 framework 时依赖守卫会拦。
#
# 原理：
#   monorepo 里的 packages/<pkg> 拆成独立可发布单元。
#   Composer 的 GitHub 驱动只读仓库根目录 composer.json，不支持子目录
#   （官方文档：package-path 选项只对 SVN 驱动有效）。
#
# ⚠️ 关键：tag 必须在**拆分分支**的 commit 上打。
#   直接推主仓库的 tag 会让 Composer 只看到 dev-main、识别不出版本号
#   （那些 tag 指向的 commit 不含 packages/<pkg>/composer.json）。
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

REMOTE_BASE="git@github.com:zyzyzzy"
ONLY="${1:-all}"

# ── 颜色输出 ──
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
info()  { echo -e "${GREEN}✅${NC} $*"; }
warn()  { echo -e "${YELLOW}⚠️ ${NC} $*"; }
error() { echo -e "${RED}❌${NC} $*" >&2; exit 1; }

# ── 前置检查 ──
command -v git >/dev/null || error "需要 git"

echo "════════════════════════════════════════"
echo "  发布 Composer 包（core + framework + region）"
echo "════════════════════════════════════════"

# ── 发布单个包 ──
publish_one() {
    local pkg="$1"
    local prefix="packages/$pkg"
    local remote="${REMOTE_BASE}/aimanong-${pkg}.git"
    local branch="split-${pkg}"

    echo ""
    echo "── $pkg ──────────────────────────────"

    [ -d "$prefix" ] || error "目录 $prefix 不存在"
    [ -f "$prefix/composer.json" ] || error "$prefix/composer.json 不存在"

    # 1. 检查 version 字段（缺了 Packagist 识别不出版本号）
    local ver
    ver=$(python3 -c "import json; print(json.load(open('$prefix/composer.json')).get('version',''))" 2>/dev/null || echo "")
    if [ -z "$ver" ]; then
        error "$pkg 的 composer.json 缺少 version 字段 —— Packagist 将无法识别版本号"
    fi
    info "version: $ver"

    # 1.5 它依赖的 aimanong/* 包必须**已经**在 Packagist 上。
    #
    # 拆包之后 framework 依赖 aimanong/core。如果 core 还没发布就发 framework，
    # 结果是：Packagist 上有一个永远装不上的包，而发布过程本身一切正常 ——
    # 典型的「关卡不报错但产物是坏的」。
    local deps
    deps=$(python3 -c "
import json
d = json.load(open('$prefix/composer.json'))
for k in d.get('require', {}):
    if k.startswith('aimanong/'):
        print(k)
" 2>/dev/null || echo "")

    if [ -n "$deps" ]; then
        for dep in $deps; do
            if curl -s --max-time 30 --retry 2 --retry-delay 1 \
                "https://repo.packagist.org/p2/${dep}.json" 2>/dev/null \
                | grep -q "\"${dep}\""; then
                info "依赖已发布: $dep"
            else
                error "依赖尚未发布到 Packagist: $dep —— 现在发 $pkg，用户会装不上。先发布该依赖。"
            fi
        done
    fi

    # 2. subtree split
    echo "  → subtree split..."
    git subtree split --prefix="$prefix" -b "$branch" >/dev/null 2>&1 \
        || error "subtree split 失败"
    info "拆分完成"

    # 3. 推送主分支
    echo "  → 推送到 $remote"
    if ! git ls-remote --exit-code "$remote" main >/dev/null 2>&1; then
        # 仓库还没有 main 分支，先推
        git push -q "$remote" "$branch:main" || error "推送 main 失败"
        info "main 已推送"
    else
        git push -q "$remote" "$branch:main" || error "推送 main 失败"
        info "main 已更新"
    fi

    # 4. 同步历史 tag（把拆分分支上的对应 commit 推成包仓库的 tag）
    #
    # ⚠️ 2026-10-13 修：原实现在这里做 `git tag -f "$t" "$target"`，
    #    把**本地** tag 改写成了拆分 commit。后果（真实发生）：
    #      · 主仓库里 15 个 tag 全部指向「只有 packages/<pkg> 的树」，
    #        git show v1.8.0 看到的是框架源码而不是主仓库
    #      · 本脚本自己再也认不出这些 tag —— 它靠
    #        `git show "$t:$prefix/composer.json"` 定位拆分 commit，
    #        tag 被改写后这个路径不存在 → 静默 continue → **不幂等**
    #      · 谁跑一次 `git push --tags origin`，就会把污染的 tag
    #        覆盖到主仓库远端（远端目前是对的，纯属没人这么干）
    #
    #    正确做法：直接推「某个对象 → 远端 tag ref」，完全不碰本地 tag。
    echo "  → 同步版本 tag..."
    local count=0 skipped=0
    for t in $(git tag --sort=v:refname); do
        # 主仓库该 tag 上 packages/<pkg>/composer.json 的 blob
        local src_blob
        src_blob=$(git show "$t:$prefix/composer.json" 2>/dev/null || true)
        if [ -z "$src_blob" ]; then
            # 正常情况不会走到这里。走到说明本地 tag 已被污染
            # （或该 tag 本就不属于这个包），必须说出来而不是静默跳过。
            skipped=$((skipped+1))
            continue
        fi

        # 在拆分分支历史里找包含相同 blob 的 commit
        local target=""
        for c in $(git log --format=%H "$branch"); do
            local dst_blob
            dst_blob=$(git show "$c:composer.json" 2>/dev/null || true)
            if [ "$dst_blob" = "$src_blob" ]; then
                target="$c"
                break
            fi
        done

        if [ -n "$target" ]; then
            # 不建本地 tag，直接把目标对象推成远端 tag
            git push -q -f "$remote" "$target:refs/tags/$t" 2>/dev/null && count=$((count+1))
        fi
    done
    info "同步了 $count 个版本 tag"

    # 4.5 首次发布：包仓库上还没有任何版本 tag 时，用 composer.json 的
    #     version 建一个。
    #
    #    为什么需要：Packagist 的「稳定版本」来自 git tag，不是 composer.json
    #     的 version 字段 —— 没有 tag 时它只认 dev-main，
    #     用户 `composer require aimanong/core:^1.0` 会解析失败。
    #     （历史 tag 同步逻辑只覆盖「主仓库已有对应 tag」的情况，
    #      新拆出来的包没有历史 tag，所以会漏掉这一步。）
    #
    #    只在「一个版本 tag 都没有」时执行 —— 之后照常走版本 tag 同步，
    #    不会因为忘记升版本号而重复打 tag。
    local existing_tags
    existing_tags=$(git ls-remote --tags "$remote" 2>/dev/null | grep -c 'refs/tags/' || true)

    if [ "${existing_tags:-0}" -eq 0 ]; then
        local ver_tag="v${ver}"
        echo "  → 包仓库还没有版本 tag，用 composer.json 的 version 建 ${ver_tag}"
        if git push -q "$remote" "$(git rev-parse "$branch"):refs/tags/${ver_tag}" 2>/dev/null; then
            info "已创建 ${ver_tag}"
        else
            warn "创建 ${ver_tag} 失败 —— 请手动在包仓库上打 tag，否则 Packagist 只认 dev-main"
        fi
    fi
    if [ "$skipped" -gt 0 ]; then
        # 两种可能，必须都提到，否则会误导：
        #   ① 该 tag 属于另一个包（如 v1.0.0-region）—— 正常
        #   ② 本地 tag 被历史版本的脚本改写成了拆分 commit —— 要修
        warn "有 $skipped 个 tag 在 $prefix 下解析不出 composer.json，已跳过"
        warn "  · 属于本包但被跳过 → 本地 tag 已被污染，执行修复："
        warn "      git fetch origin --tags --force"
    fi

    # 5. 清理临时分支
    git branch -D "$branch" >/dev/null 2>&1
    info "完成：$pkg"
    echo "    Packagist: https://packagist.org/packages/aimanong/$pkg"
    echo "    安装命令:  composer require aimanong/$pkg"
}

# ── 主流程 ──
#
# ⚠️ 顺序有意义：**core → laravel → framework**。
# 后者依赖前者，顺序反了会得到一个
# 「永远装不上」的包（发布过程本身却一切正常）。
# publish_one 里的依赖守卫也会硬拦这种情况，这里是第一道。
if [ "$ONLY" = "core" ] || [ "$ONLY" = "all" ]; then
    publish_one core
fi

if [ "$ONLY" = "laravel" ] || [ "$ONLY" = "all" ]; then
    publish_one laravel
fi

if [ "$ONLY" = "framework" ] || [ "$ONLY" = "all" ]; then
    publish_one framework
fi

if [ "$ONLY" = "region" ] || [ "$ONLY" = "all" ]; then
    publish_one region
fi

echo ""
echo "════════════════════════════════════════"
info "全部完成"
echo ""
echo "  Webhook 会自动通知 Packagist（约 1 分钟内生效）"
echo "  验证: composer show aimanong/<pkg> --all"
echo "════════════════════════════════════════"
