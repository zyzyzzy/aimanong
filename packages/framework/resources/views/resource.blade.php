<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $label }} · AI 码农</title>
    <script src="https://unpkg.com/vue@3/dist/vue.global.prod.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "PingFang SC", "Microsoft YaHei", sans-serif;
            background: #f5f7fa; color: #101B16;
        }
        .header {
            background: #fff; border-bottom: 1px solid #e8edea;
            padding: 0 24px; height: 56px;
            display: flex; align-items: center; justify-content: space-between;
        }
        .header h1 { font-size: 17px; font-weight: 600; }
        .header .user { font-size: 13px; color: #6b746f; }
        .container { max-width: 1100px; margin: 0 auto; padding: 24px; }
        .toolbar {
            display: flex; gap: 10px; align-items: center;
            margin-bottom: 16px;
        }
        .toolbar input {
            padding: 8px 12px; border: 1px solid #dde3e0;
            border-radius: 6px; font-size: 13px; width: 240px;
        }
        .toolbar input:focus { outline: none; border-color: #3FBF6F; }
        .btn {
            padding: 8px 14px; border-radius: 6px; font-size: 13px;
            border: 1px solid #dde3e0; background: #fff; cursor: pointer;
        }
        .btn:hover { border-color: #3FBF6F; color: #3FBF6F; }
        .btn-primary { background: #3FBF6F; color: #fff; border-color: #3FBF6F; }
        .btn-primary:hover { background: #35a75f; color: #fff; }
        .card {
            background: #fff; border-radius: 10px;
            box-shadow: 0 2px 8px rgba(16,27,22,.05); overflow: hidden;
        }
        table { width: 100%; border-collapse: collapse; }
        th {
            background: #fafcfb; text-align: left; padding: 12px 16px;
            font-size: 12px; font-weight: 600; color: #6b746f;
            border-bottom: 1px solid #eef2f0; white-space: nowrap;
        }
        th.sortable { cursor: pointer; user-select: none; }
        th.sortable:hover { color: #3FBF6F; }
        td { padding: 12px 16px; font-size: 13px; border-bottom: 1px solid #f2f5f3; }
        tr:last-child td { border-bottom: none; }
        .pagination {
            display: flex; gap: 8px; align-items: center;
            padding: 14px 16px; border-top: 1px solid #f2f5f3; font-size: 13px;
        }
        .pagination .info { color: #8a948f; margin-left: auto; }
        .badge {
            display: inline-block; padding: 2px 8px; border-radius: 10px;
            font-size: 11px; background: #e8f5e9; color: #2e7d4f;
        }
        /* 标签：值映射 / 布尔 */
        .tag {
            display: inline-block; padding: 2px 9px; border-radius: 4px;
            font-size: 12px; background: #eef2f0; color: #4a544e;
        }
        .tag-on  { background: #e8f5e9; color: #2e7d4f; }
        .tag-off { background: #f0f2f1; color: #8a948f; }
        .muted   { color: #c3ccc7; }
        /* 条件高亮（如库存不足） */
        .hl-danger  { color: #c0392b; font-weight: 600; }
        .hl-warning { color: #d68910; font-weight: 600; }
        .hl-success { color: #2e7d4f; font-weight: 600; }
        /* 进度条 */
        .progress-wrap { display: inline-flex; align-items: center; gap: 8px; }
        .progress-bar {
            display: inline-block; width: 70px; height: 6px;
            background: #eef2f0; border-radius: 3px; overflow: hidden;
        }
        .progress-bar i { display: block; height: 100%; background: #3FBF6F; }
        .progress-num { font-size: 12px; color: #6b746f; }
        .empty { padding: 40px; text-align: center; color: #a5aea9; font-size: 13px; }
        /* 分步表单 */
        .steps-bar { display: flex; gap: 4px; margin-bottom: 20px; border-bottom: 1px solid #eef2f0; padding-bottom: 14px; }
        .step-item { display: flex; align-items: center; gap: 6px; font-size: 12px; color: #a5aea9; flex: 1; }
        .step-item.active { color: #3FBF6F; font-weight: 600; }
        .step-item.done { color: #6b746f; cursor: pointer; }
        .step-dot {
            width: 20px; height: 20px; border-radius: 50%; background: #eef2f0;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 11px; flex-shrink: 0;
        }
        .step-item.active .step-dot { background: #3FBF6F; color: #fff; }
        .step-item.done .step-dot { background: #d6ede0; color: #2e7d4f; }
        .loading { padding: 40px; text-align: center; color: #8a948f; font-size: 13px; }
    </style>
</head>
<body>
<div id="app">
    <div class="header">
        <h1>{{ $label }} <span style="font-weight:400;color:#a5aea9;font-size:13px;">/ {{ $uri }}</span></h1>
        <div class="user">{{ $user->name ?? $user->username ?? '' }}</div>
    </div>

    <div class="container">
        <div class="toolbar">
            <input v-model="keyword" @keyup.enter="load(1)" placeholder="搜索…" v-if="hasSearch">
            <button class="btn" @click="load(currentPage)">刷新</button>
            <button class="btn" v-if="exportable" @click="doExport">导出</button>
            <button class="btn btn-primary" @click="openCreate">新增</button>
        </div>

        <div class="card">
            <div v-if="loading" class="loading">加载中…</div>
            <div v-else-if="rows.length === 0" class="empty">暂无数据</div>
            <table v-else>
                <thead>
                    <tr>
                        <th
                            v-for="col in columns"
                            :key="col.name"
                            :class="{ sortable: col.sortable }"
                            @click="col.sortable && toggleSort(col.name)"
                        >
                            @{{ col.label }}
                            <span v-if="sortField === col.name">@{{ direction === 'asc' ? '↑' : '↓' }}</span>
                        </th>
                        <th style="width:120px;">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id">
                        <td v-for="col in columns" :key="col.name" :class="cellClass(col, row)">
                            <!-- 日期时间 -->
                            <span v-if="col.formatter === 'datetime'">@{{ formatDate(cellValue(col, row)) }}</span>

                            <!-- 值 → 标签映射 -->
                            <span v-else-if="col.formatter === 'map'"
                                  class="tag" :class="tagClass(row[col.name])">
                                @{{ mapLabel(col, cellValue(col, row)) }}
                            </span>

                            <!-- 布尔：渲染为是/否标签，而非 true/false -->
                            <span v-else-if="col.formatter === 'bool'"
                                  class="tag" :class="row[col.name] ? 'tag-on' : 'tag-off'">
                                @{{ cellValue(col, row) ? (col.props.trueLabel || '是') : (col.props.falseLabel || '否') }}
                            </span>

                            <!-- 徽章 -->
                            <span v-else-if="col.formatter === 'badge'" class="badge">
                                @{{ cellValue(col, row) }}
                            </span>

                            <!-- 图片 -->
                            <img v-else-if="col.formatter === 'image'" :src="row[col.name]"
                                 style="height:32px;border-radius:4px;" />

                            <!-- 链接 -->
                            <a v-else-if="col.formatter === 'link'" :href="row[col.name]" target="_blank"
                               style="color:#3FBF6F;">@{{ col.props.text || row[col.name] }}</a>

                            <!-- 进度条 -->
                            <span v-else-if="col.formatter === 'progress'" class="progress-wrap">
                                <span class="progress-bar"><i :style="{width: Math.min(100, Number(row[col.name]) || 0) + '%'}"></i></span>
                                <span class="progress-num">@{{ row[col.name] }}</span>
                            </span>

                            <!-- 金额 -->
                            <span v-else-if="col.formatter === 'money'">
                                @{{ col.props.symbol || '¥' }}@{{ formatMoney(row[col.name]) }}
                            </span>

                            <!-- ID 默认徽章 -->
                            <span v-else-if="col.name === 'id'" class="badge">@{{ row[col.name] }}</span>

                            <!-- 空值占位 -->
                            <span v-else-if="row[col.name] === null || row[col.name] === ''" class="muted">—</span>

                            <span v-else-if="isTree && col.name === treeTitleColumn"
                                  :style="{ paddingLeft: (row._depth || 0) * 20 + 'px' }">
                                <span v-if="row._depth > 0" class="muted">└ </span>@{{ row[col.name] }}
                            </span>
                            <span v-else>@{{ cellValue(col, row) }}</span>
                        </td>
                        <td>
                            <button class="btn" style="padding:4px 8px;font-size:12px;" @click="edit(row)">编辑</button>
                            <button class="btn" style="padding:4px 8px;font-size:12px;" @click="remove(row)">删除</button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div class="pagination" v-if="total > 0 && !isTree">
                <button class="btn" :disabled="currentPage <= 1" @click="load(currentPage - 1)">上一页</button>
                <span>@{{ currentPage }} / @{{ lastPage }}</span>
                <button class="btn" :disabled="currentPage >= lastPage" @click="load(currentPage + 1)">下一页</button>
                <span class="info">共 @{{ total }} 条</span>
            </div>
        </div>
    </div>

    <!-- 新增/编辑弹窗 -->
    <div v-if="showCreate" style="position:fixed;inset:0;background:rgba(16,27,22,.4);display:flex;align-items:center;justify-content:center;" @click.self="showCreate = false">
        <div style="background:#fff;border-radius:10px;padding:24px;width:420px;max-height:80vh;overflow:auto;">
            <h3 style="margin-bottom:16px;font-size:15px;">@{{ editing ? '编辑' : '新增' }}</h3>

            <!-- 分步表单：步骤指示器 -->
            <div v-if="isStepped" class="steps-bar">
                <div v-for="(st, i) in steps" :key="i"
                     class="step-item"
                     :class="{ active: i === currentStep, done: i < currentStep }"
                     @click="goStep(i)">
                    <span class="step-dot">@{{ i < currentStep ? '✓' : (i + 1) }}</span>
                    <span class="step-name">@{{ st.title }}</span>
                </div>
            </div>

            <div v-for="f in visibleFields" :key="f.name" style="margin-bottom:14px;">
                <label style="display:block;font-size:13px;color:#4a544e;margin-bottom:6px;">
                    @{{ f.label }}<span v-if="f.required" style="color:#c0392b;">*</span>
                </label>
                <!-- 下拉选择 -->
                <select v-if="f.type === 'select'" v-model="form[f.name]"
                        style="width:100%;padding:9px 12px;border:1px solid #dde3e0;border-radius:6px;font-size:13px;">
                    <option :value="null">请选择…</option>
                    <option v-for="opt in f.props.options" :key="opt.value" :value="opt.value">@{{ opt.label }}</option>
                </select>

                <!-- 单选组 -->
                <div v-else-if="f.type === 'radio'" style="display:flex;gap:16px;flex-wrap:wrap;">
                    <label v-for="opt in f.props.options" :key="opt.value"
                           style="display:flex;align-items:center;gap:5px;font-size:13px;font-weight:400;cursor:pointer;">
                        <input type="radio" :name="f.name" :value="opt.value" v-model="form[f.name]">
                        @{{ opt.label }}
                    </label>
                </div>

                <!-- 多选组 -->
                <div v-else-if="f.type === 'checkbox'" style="display:flex;gap:16px;flex-wrap:wrap;">
                    <label v-for="opt in f.props.options" :key="opt.value"
                           style="display:flex;align-items:center;gap:5px;font-size:13px;font-weight:400;cursor:pointer;">
                        <input type="checkbox" :value="opt.value" v-model="form[f.name]">
                        @{{ opt.label }}
                    </label>
                </div>

                <!-- 开关 -->
                <input v-else-if="f.type === 'switch'" type="checkbox" v-model="form[f.name]">

                <!-- 省市区联动（由 aimanong/region 插件提供） -->
                <div v-else-if="f.type === 'region'" style="display:flex;gap:8px;">
                    <select v-model="ensureRegion(f.name).province" @change="onProvinceChange(f.name)"
                            style="flex:1;padding:9px 12px;border:1px solid #dde3e0;border-radius:6px;font-size:13px;">
                        <option value="">请选择省</option>
                        <option v-for="p in (f.props.data || []).length ? f.props.data : regionProvinces"
                                :key="p.code" :value="p.code">@{{ p.name }}</option>
                    </select>
                    <select v-model="ensureRegion(f.name).city" @change="onCityChange(f.name)"
                            style="flex:1;padding:9px 12px;border:1px solid #dde3e0;border-radius:6px;font-size:13px;">
                        <option value="">请选择市</option>
                        <option v-for="c in ensureRegion(f.name).cities" :key="c.code" :value="c.code">@{{ c.name }}</option>
                    </select>
                    <select v-if="(f.props.level || 3) === 3" v-model="ensureRegion(f.name).district"
                            style="flex:1;padding:9px 12px;border:1px solid #dde3e0;border-radius:6px;font-size:13px;">
                        <option value="">请选择区</option>
                        <option v-for="d in ensureRegion(f.name).districts" :key="d.code" :value="d.code">@{{ d.name }}</option>
                    </select>
                </div>

                <!-- 多选（复选框组）—— 多对多关联常用 -->
                <div v-else-if="f.type === 'multiselect'" style="display:flex;gap:14px;flex-wrap:wrap;">
                    <label v-for="opt in (f.props.options || [])" :key="opt.value"
                           style="display:flex;align-items:center;gap:5px;font-size:13px;font-weight:400;cursor:pointer;">
                        <input type="checkbox" :value="opt.value" v-model="form[f.name]">
                        @{{ opt.label }}
                    </label>
                    <span v-if="!(f.props.options || []).length" class="muted" style="font-size:12px;">（无可选项）</span>
                </div>

                <!-- 颜色选择器 -->
                <input v-else-if="f.type === 'color'" type="color" v-model="form[f.name]"
                       style="width:64px;height:36px;padding:2px;border:1px solid #dde3e0;border-radius:6px;">

                <!-- 滑块 -->
                <div v-else-if="f.type === 'slider'" style="display:flex;align-items:center;gap:10px;">
                    <input type="range" v-model="form[f.name]"
                           :min="f.props.min ?? 0" :max="f.props.max ?? 100" :step="f.props.step ?? 1"
                           style="flex:1;">
                    <span style="font-size:13px;color:#6b746f;min-width:36px;">@{{ form[f.name] }}</span>
                </div>

                <!-- 评分 -->
                <div v-else-if="f.type === 'rate'" style="display:flex;gap:4px;align-items:center;">
                    <span v-for="n in (f.props.max || 5)" :key="n"
                          @click="form[f.name] = n"
                          :style="{cursor:'pointer',fontSize:'20px',color: (form[f.name] >= n) ? '#f5b041' : '#dde3e0'}">★</span>
                    <span style="font-size:12px;color:#8a948f;margin-left:6px;">@{{ form[f.name] || 0 }} / @{{ f.props.max || 5 }}</span>
                </div>

                <!-- 标签输入（逗号分隔） -->
                <input v-else-if="f.type === 'tags'" type="text" v-model="form[f.name]"
                       :placeholder="f.props.placeholder || '多个用逗号分隔'"
                       style="width:100%;padding:9px 12px;border:1px solid #dde3e0;border-radius:6px;font-size:13px;">

                <!-- 日期区间 -->
                <div v-else-if="f.type === 'daterange'" style="display:flex;gap:8px;align-items:center;">
                    <input type="date" v-model="form[f.name + '_start']" style="flex:1;padding:9px 12px;border:1px solid #dde3e0;border-radius:6px;font-size:13px;">
                    <span class="muted">至</span>
                    <input type="date" v-model="form[f.name + '_end']" style="flex:1;padding:9px 12px;border:1px solid #dde3e0;border-radius:6px;font-size:13px;">
                </div>

                <!-- 多行文本 -->
                <textarea v-else-if="f.type === 'textarea'" v-model="form[f.name]"
                          :rows="f.props.rows || 3"
                          :placeholder="f.props.placeholder || ''"
                          style="width:100%;padding:9px 12px;border:1px solid #dde3e0;border-radius:6px;font-size:13px;"></textarea>

                <!-- 日期 -->
                <input v-else-if="f.type === 'date'" type="date" v-model="form[f.name]"
                       style="width:100%;padding:9px 12px;border:1px solid #dde3e0;border-radius:6px;font-size:13px;">

                <!-- 日期时间 -->
                <input v-else-if="f.type === 'datetime'" type="datetime-local" v-model="form[f.name]"
                       style="width:100%;padding:9px 12px;border:1px solid #dde3e0;border-radius:6px;font-size:13px;">

                <!-- 普通输入框 -->
                <input v-else :type="inputType(f)" v-model="form[f.name]"
                       :step="f.type === 'decimal' ? (f.props.decimals ? Math.pow(10, -f.props.decimals) : '0.01') : null"
                       :placeholder="f.props.placeholder || ''"
                       :readonly="f.readonly"
                       style="width:100%;padding:9px 12px;border:1px solid #dde3e0;border-radius:6px;font-size:13px;">

                <!-- 帮助文本 -->
                <div v-if="f.props.help" style="font-size:12px;color:#8a948f;margin-top:4px;">@{{ f.props.help }}</div>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;">
                <button class="btn" @click="showCreate = false">取消</button>
                <button v-if="isStepped && currentStep > 0" class="btn" @click="currentStep--">上一步</button>
                <button v-if="isStepped && currentStep < steps.length - 1" class="btn btn-primary" @click="currentStep++">下一步</button>
                <button v-if="!isStepped || currentStep === steps.length - 1" class="btn btn-primary" @click="save">保存</button>
            </div>
        </div>
    </div>
</div>

<script>
const { createApp, ref, computed, onMounted } = Vue;

createApp({
    setup() {
        const schema = @json($schema);
        const uri = @json($uri);
        const treeConfig = schema.grid?.tree ?? null;
        const isTree = treeConfig !== null;

        const rows = ref([]);
        const loading = ref(false);
        const total = ref(0);
        const currentPage = ref(1);
        const lastPage = ref(1);
        const keyword = ref('');
        const sortField = ref('');
        const direction = ref('asc');
        const showCreate = ref(false);
        const editing = ref(false);
        const form = ref({});

        const columns = computed(() => schema.grid?.columns ?? []);
        const formFields = computed(() => (schema.form?.fields ?? []).filter(f => !f.hidden));
        const hasSearch = computed(() => columns.value.some(c => c.searchable));
        const exportable = computed(() => schema.grid?.exportable === true);
        const treeTitleColumn = computed(() => treeConfig?.titleColumn || 'name');

        // 分步表单
        const isStepped = computed(() => schema.form?.stepped === true);
        const steps = computed(() => schema.form?.steps ?? []);
        const currentStep = ref(0);

        /** 当前步骤应显示的字段（非分步时显示全部） */
        const visibleFields = computed(() => {
            if (!isStepped.value || steps.value.length === 0) return formFields.value;
            const st = steps.value[currentStep.value];
            return st ? st.fields.filter(f => !f.hidden) : formFields.value;
        });

        const csrf = document.querySelector('meta[name="csrf-token"]').content;
        const base = `/admin/api/${uri}`;

        async function load(page = 1) {
            loading.value = true;
            const params = new URLSearchParams({ page: String(page) });
            if (keyword.value) params.set('keyword', keyword.value);
            if (sortField.value) {
                params.set('sort', sortField.value);
                params.set('direction', direction.value);
            }
            try {
                const res = await fetch(`${base}?${params}`, {
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                });
                const json = await res.json();
                rows.value = json.data ?? [];
                total.value = json.meta?.total ?? 0;
                currentPage.value = json.meta?.currentPage ?? 1;
                lastPage.value = json.meta?.lastPage ?? 1;
            } catch (e) {
                console.error(e);
            } finally {
                loading.value = false;
            }
        }

        /** 把嵌套树拍平成带缩进层级的行 */
        function flattenTree(nodes, depth = 0) {
            const out = [];
            for (const n of nodes) {
                out.push({ ...n, _depth: depth });
                if (n.children && n.children.length) {
                    out.push(...flattenTree(n.children, depth + 1));
                }
            }
            return out;
        }

        /** 加载树形数据 */
        async function loadTree() {
            loading.value = true;
            try {
                const res = await fetch(`${base}/tree`, {
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                });
                const json = await res.json();
                if (json.error) {
                    console.error('[aimanong] 树形加载失败:', json.message);
                    rows.value = [];
                    return;
                }
                rows.value = flattenTree(json.data ?? []);
                total.value = rows.value.length;
                lastPage.value = 1;
                currentPage.value = 1;
            } catch (e) {
                console.error(e);
            } finally {
                loading.value = false;
            }
        }

        /** 导出：带上与当前列表相同的筛选条件 */
        function doExport() {
            const params = new URLSearchParams();
            if (keyword.value) params.set('keyword', keyword.value);
            if (sortField.value) {
                params.set('sort', sortField.value);
                params.set('direction', direction.value);
            }
            window.location.href = `${base}/export?${params}`;
        }

        function toggleSort(name) {
            if (sortField.value === name) {
                direction.value = direction.value === 'asc' ? 'desc' : 'asc';
            } else {
                sortField.value = name;
                direction.value = 'asc';
            }
            load(1);
        }

        function formatDate(v) {
            if (!v) return '';
            return String(v).replace('T', ' ').slice(0, 19);
        }

        /**
         * 取单元格值 —— 支持关联路径（如 category.name）。
         *
         * 关联列在 schema 里以点号命名，后端已预加载，此处直接深取。
         */
        function cellValue(col, row) {
            if (!col.name.includes('.')) return normalizeCell(row[col.name]);

            let v = row;
            for (const part of col.name.split('.')) {
                if (v === null || v === undefined) return '';

                // 多对多：关联值是数组，取每个成员的该字段
                if (Array.isArray(v)) {
                    return v.map(item => (item == null ? '' : item[part]))
                            .filter(x => x !== null && x !== undefined && x !== '')
                            .join(' / ');
                }

                v = v[part];
            }

            return normalizeCell(v);
        }

        /**
         * 归一化单元格值。
         *
         * 多对多关联返回数组（如 [{name:'Vue'},{name:'Laravel'}]），
         * 直接插值会渲染成 [object Object] —— 必须拼成可读文本。
         */
        function normalizeCell(v) {
            if (v === null || v === undefined) return '';
            if (!Array.isArray(v)) return v;

            return v
                .map(item => {
                    if (item === null || item === undefined) return '';
                    if (typeof item === 'object') {
                        // 优先取常见展示字段
                        return item.name ?? item.title ?? item.label ?? JSON.stringify(item);
                    }
                    return String(item);
                })
                .filter(x => x !== '')
                .join(' / ');
        }

        // 省市区插件状态
        const regionProvinces = @json(\Aimanong\Region\Region::provinces());
        const regionTree = @json(\Aimanong\Region\Region::tree());
        const regionForm = ref({});

        /** 确保字段状态存在 */
        function ensureRegion(name) {
            if (!regionForm.value[name]) {
                regionForm.value[name] = { province: '', city: '', district: '', cities: [], districts: [] };
            }
            return regionForm.value[name];
        }

        function onProvinceChange(name) {
            const st = ensureRegion(name);
            st.city = '';
            st.district = '';
            st.districts = [];
            const p = (regionTree || []).find(x => x.code === st.province);
            st.cities = p ? (p.children || []) : [];
            syncRegionValue(name);
        }

        function onCityChange(name) {
            const st = ensureRegion(name);
            st.district = '';
            const p = (regionTree || []).find(x => x.code === st.province);
            const c = p ? (p.children || []).find(x => x.code === st.city) : null;
            st.districts = c ? (c.children || []) : [];
            syncRegionValue(name);
        }

        /** 把三级选择拼成路径写入表单值 */
        function syncRegionValue(name) {
            const st = ensureRegion(name);
            const p = (regionTree || []).find(x => x.code === st.province);
            const c = p ? (p.children || []).find(x => x.code === st.city) : null;
            const d = c ? (c.children || []).find(x => x.code === st.district) : null;

            const parts = [p?.name, c?.name, d?.name].filter(Boolean);
            form.value[name] = parts.join('/');
        }

        /** 条件高亮的 CSS 类 */
        function cellClass(col, row) {
            const hl = col.props?.highlight;
            if (!hl) return '';

            const v = Number(cellValue(col, row));
            const target = Number(hl.value);
            if (!isFinite(v) || !isFinite(target)) return '';

            const hit = {
                '<': v < target,
                '<=': v <= target,
                '>': v > target,
                '>=': v >= target,
                '==': v === target,
                '!=': v !== target,
            }[hl.operator] ?? false;

            return hit ? 'hl-' + (hl.level || 'danger') : '';
        }

        /** 表单输入框的 HTML input type */
        function inputType(f) {
            const map = {
                number: 'number',
                decimal: 'number',
                email: 'email',
                url: 'url',
                hidden: 'hidden',
                password: 'password',
            };
            return map[f.type] || 'text';
        }

        /** 值映射标签：优先 props.map，回退原值 */
        function mapLabel(col, value) {
            const map = col.props?.map;
            if (map && map[value] !== undefined) return map[value];
            return value ?? '';
        }

        /** 根据值给出标签配色（状态类字段的语义色） */
        function tagClass(value) {
            const v = String(value ?? '').toLowerCase();
            const positive = ['1', 'true', 'yes', 'active', 'enabled', 'success', 'paid', 'on_sale', 'published', 'resolved'];
            const negative = ['0', 'false', 'no', 'disabled', 'failed', 'closed', 'sold_out', 'draft', 'pending'];
            if (positive.includes(v)) return 'tag-on';
            if (negative.includes(v)) return 'tag-off';
            return '';
        }

        /** 金额格式化：保留两位小数，千分位分隔 */
        function formatMoney(v) {
            const n = Number(v);
            if (!isFinite(n)) return v ?? '';
            return n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }

        /**
         * 跳转到指定步骤。
         *
         * 分步表单的两条规则：
         *   - 新增：只能往前走（防止跳过必填项）
         *   - **编辑：可以自由跳转** —— 用户需要查看/修改任意步骤的字段，
         *     锁死在第 1 步会导致后面的字段根本改不了。
         */
        function goStep(i) {
            if (editing.value || i <= currentStep.value) {
                currentStep.value = i;
            }
        }

        /** 打开新增弹窗（含插件字段初始化） */
        function openCreate() {
            editing.value = false;
            currentStep.value = 0;
            form.value = {};

            // 多选类字段必须初始化为数组，否则 v-model 无法绑定
            for (const f of (schema.form?.fields ?? [])) {
                if (f.type === 'multiselect' || f.type === 'checkbox') {
                    form.value[f.name] = [];
                }
            }

            // 插件字段需要预置状态，否则模板取值会报 undefined
            for (const f of (schema.form?.fields ?? [])) {
                if (f.type === 'region') ensureRegion(f.name);
            }

            showCreate.value = true;
        }

        function edit(row) {
            editing.value = true;
            currentStep.value = 0;

            // 初始化插件字段状态
            for (const f of (schema.form?.fields ?? [])) {
                if (f.type === 'region') ensureRegion(f.name);
            }

            form.value = { ...row };

            /*
             * 归一化表单值 —— 这是「编辑回填」的关键。
             *
             * 多对多关联字段（如 tags）API 返回的是对象数组
             * [{id:1,name:'Laravel'}, ...]，而 checkbox 的 :value 是标量 '1'。
             * Vue 的 v-model 用 === 严格相等匹配 → 对象永远不等于标量
             * → **一个都勾不上**，用户随手保存就会静默清空原有标签。
             *
             * 因此在进入表单前把关联对象提取成标量 id 数组。
             */
            for (const f of (schema.form?.fields ?? [])) {
                const v = form.value[f.name];

                if (Array.isArray(v) && v.length > 0 && typeof v[0] === 'object' && v[0] !== null) {
                    // 取 id（无 id 时退化为 name/label）
                    const key = ('id' in v[0]) ? 'id' : (('value' in v[0]) ? 'value' : 'name');
                    form.value[f.name] = v.map(item => {
                        const x = item == null ? '' : item[key];
                        return x === undefined || x === null ? '' : String(x);
                    }).filter(x => x !== '');
                }

                // 选项的 value 也统一成字符串，保证 === 能匹配
                if (Array.isArray(form.value[f.name]) && Array.isArray(f.props?.options)) {
                    const allowed = new Set(f.props.options.map(o => String(o.value)));
                    form.value[f.name] = form.value[f.name].map(String).filter(x => allowed.has(x));
                }
            }

            showCreate.value = true;
        }

        async function save() {
            const isEdit = editing.value;
            const url = isEdit ? `${base}/${form.value.id}` : base;
            const method = isEdit ? 'PUT' : 'POST';
            try {
                const res = await fetch(url, {
                    method,
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                    },
                    body: JSON.stringify(form.value),
                });
                if (!res.ok) {
                    const err = await res.json().catch(() => ({}));
                    alert('保存失败: ' + (err.message ?? JSON.stringify(err.errors ?? err)));
                    return;
                }
                showCreate.value = false;
                editing.value = false;
                form.value = {};
                load(currentPage.value);
            } catch (e) {
                alert('保存失败: ' + e.message);
            }
        }

        async function remove(row) {
            if (!confirm('确认删除 #' + row.id + '？')) return;
            await fetch(`${base}/${row.id}`, {
                method: 'DELETE',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            });
            load(currentPage.value);
        }

        onMounted(() => {
            if (isTree) { loadTree(); } else { load(1); }
        });

        return {
            rows, loading, total, currentPage, lastPage, keyword,
            sortField, direction, showCreate, editing, form,
            columns, formFields, hasSearch,
            load, toggleSort, formatDate, edit, save, remove,
            exportable, doExport, isTree, treeTitleColumn, loadTree,
            isStepped, steps, currentStep, visibleFields,
            inputType, mapLabel, tagClass, formatMoney, cellValue, cellClass, normalizeCell,
            regionProvinces, regionTree, regionForm, onProvinceChange, onCityChange, ensureRegion, openCreate, goStep,
        };
    },
}).mount('#app');
</script>
</body>
</html>
