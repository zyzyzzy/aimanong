#!/usr/bin/env bash
#
# 发布 Composer 包到 Packagist（subtree split 方式）
#
# 用法：
#   ./scripts/publish-framework.sh           # 发布 framework + region
#   ./scripts/publish-framework.sh framework # 只发布 framework
#   ./scripts/publish-framework.sh region    # 只发布 region
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
echo "  发布 Composer 包（framework + region）"
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

    # 4. 同步历史 tag（在拆分分支的对应 commit 上打）
    echo "  → 同步版本 tag..."
    local count=0
    for t in $(git tag --sort=v:refname); do
        # 主仓库该 tag 上 packages/<pkg>/composer.json 的 blob
        local src_blob
        src_blob=$(git show "$t:$prefix/composer.json" 2>/dev/null || true)
        [ -z "$src_blob" ] && continue

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
            git tag -f "$t" "$target" >/dev/null 2>&1
            git push -q -f "$remote" "refs/tags/$t" 2>/dev/null && count=$((count+1))
        fi
    done
    info "同步了 $count 个版本 tag"

    # 5. 清理临时分支
    git branch -D "$branch" >/dev/null 2>&1
    info "完成：$pkg"
    echo "    Packagist: https://packagist.org/packages/aimanong/$pkg"
    echo "    安装命令:  composer require aimanong/$pkg"
}

# ── 主流程 ──
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
