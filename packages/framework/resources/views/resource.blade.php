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
        /* 进度条 */
        .progress-wrap { display: inline-flex; align-items: center; gap: 8px; }
        .progress-bar {
            display: inline-block; width: 70px; height: 6px;
            background: #eef2f0; border-radius: 3px; overflow: hidden;
        }
        .progress-bar i { display: block; height: 100%; background: #3FBF6F; }
        .progress-num { font-size: 12px; color: #6b746f; }
        .empty { padding: 40px; text-align: center; color: #a5aea9; font-size: 13px; }
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
            <button class="btn btn-primary" @click="showCreate = true">新增</button>
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
                        <td v-for="col in columns" :key="col.name">
                            <!-- 日期时间 -->
                            <span v-if="col.formatter === 'datetime'">@{{ formatDate(row[col.name]) }}</span>

                            <!-- 值 → 标签映射 -->
                            <span v-else-if="col.formatter === 'map'"
                                  class="tag" :class="tagClass(row[col.name])">
                                @{{ mapLabel(col, row[col.name]) }}
                            </span>

                            <!-- 布尔：渲染为是/否标签，而非 true/false -->
                            <span v-else-if="col.formatter === 'bool'"
                                  class="tag" :class="row[col.name] ? 'tag-on' : 'tag-off'">
                                @{{ row[col.name] ? (col.props.trueLabel || '是') : (col.props.falseLabel || '否') }}
                            </span>

                            <!-- 徽章 -->
                            <span v-else-if="col.formatter === 'badge'" class="badge">
                                @{{ row[col.name] }}
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

                            <span v-else>@{{ row[col.name] }}</span>
                        </td>
                        <td>
                            <button class="btn" style="padding:4px 8px;font-size:12px;" @click="edit(row)">编辑</button>
                            <button class="btn" style="padding:4px 8px;font-size:12px;" @click="remove(row)">删除</button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div class="pagination" v-if="total > 0">
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
            <div v-for="f in formFields" :key="f.name" style="margin-bottom:14px;">
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
                <button class="btn btn-primary" @click="save">保存</button>
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

        function edit(row) {
            editing.value = true;
            form.value = { ...row };
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

        onMounted(() => load(1));

        return {
            rows, loading, total, currentPage, lastPage, keyword,
            sortField, direction, showCreate, editing, form,
            columns, formFields, hasSearch,
            load, toggleSort, formatDate, edit, save, remove,
            inputType, mapLabel, tagClass, formatMoney,
        };
    },
}).mount('#app');
</script>
</body>
</html>
