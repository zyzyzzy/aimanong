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
                            <span v-if="col.formatter === 'datetime'">@{{ formatDate(row[col.name]) }}</span>
                            <span v-else-if="col.formatter === 'map'">@{{ col.props.map[row[col.name]] ?? row[col.name] }}</span>
                            <span v-else-if="col.name === 'id'" class="badge">@{{ row[col.name] }}</span>
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
                <select v-if="f.type === 'select'" v-model="form[f.name]"
                        style="width:100%;padding:9px 12px;border:1px solid #dde3e0;border-radius:6px;font-size:13px;">
                    <option v-for="opt in f.props.options" :key="opt.value" :value="opt.value">@{{ opt.label }}</option>
                </select>
                <input v-else-if="f.type === 'switch'" type="checkbox" v-model="form[f.name]">
                <textarea v-else-if="f.type === 'textarea'" v-model="form[f.name]"
                          :rows="f.props.rows || 3"
                          style="width:100%;padding:9px 12px;border:1px solid #dde3e0;border-radius:6px;font-size:13px;"></textarea>
                <input v-else :type="f.type === 'number' ? 'number' : (f.type === 'email' ? 'email' : 'text')"
                       v-model="form[f.name]"
                       style="width:100%;padding:9px 12px;border:1px solid #dde3e0;border-radius:6px;font-size:13px;">
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
        };
    },
}).mount('#app');
</script>
</body>
</html>
