@extends('aimanong::partials.layout')

@section('content')
<div id="app" v-cloak>
  <div class="am-page-head">
    <div>
      <h1 class="am-page-head__title">{{ $label }}</h1>
      <div class="am-page-head__desc">
        <span v-if="!loading">共 @{{ total }} 条记录</span>
        <span v-else>加载中…</span>
      </div>
    </div>
    <div class="am-page-head__actions">
      <input v-if="hasSearch" v-model="keyword" @keyup.enter="load(1)"
             class="am-input am-input--search" style="width:220px" placeholder="搜索…">
      <button class="am-btn" @click="load(currentPage)">
        @include('aimanong::partials.icon', ['name' => 'refresh', 'size' => 15]) 刷新
      </button>
      <button class="am-btn" v-if="exportable" @click="doExport">
        @include('aimanong::partials.icon', ['name' => 'download', 'size' => 15]) 导出
      </button>
      {{-- 只读资源（如审计日志）不显示新增：按钮点了必然 403 --}}
      <button class="am-btn am-btn--primary" v-if="!readonly" @click="openCreate">
        @include('aimanong::partials.icon', ['name' => 'plus', 'size' => 15]) 新增
      </button>
    </div>
  </div>

  <div class="am-card">
    {{-- 加载态：骨架屏（改造前是整个表格消失变一行字，高度剧烈跳动） --}}
    <div v-if="loading" class="am-card__body">
      <div class="am-skeleton am-skeleton--title" style="width:32%"></div>
      <div class="am-skeleton am-skeleton--text" v-for="i in 5" :key="i" style="margin-top:12px"></div>
    </div>

    {{-- 空状态：有图标 + 说明 + 引导动作（改造前只有一行灰字） --}}
    <div v-else-if="rows.length === 0" class="am-empty">
      <div class="am-empty__icon">@include('aimanong::partials.icon', ['name' => 'inbox', 'size' => 22])</div>
      <div class="am-empty__title">暂无数据</div>
      <div class="am-empty__desc">还没有任何{{ $label }}记录，点击下方按钮创建第一条</div>
      <div class="am-empty__actions" v-if="!readonly">
        <button class="am-btn am-btn--primary" @click="openCreate">
          @include('aimanong::partials.icon', ['name' => 'plus', 'size' => 15]) 新增{{ $label }}
        </button>
      </div>
    </div>

    <div v-else class="am-table-wrap am-table-wrap--stack">
      {{-- min-width 按列数动态算（CSS 见 design-system 的 --col-count 规则）：
           固定的 980px 只够 9 列，12 列的表格仍会把中文压成竖排。 --}}
      <table class="am-table" :style="{'--col-count': columns.length}">
        <thead>
          <tr>
            {{-- 表头：排序状态用 .is-sortable / .is-sorted（设计系统的 API） --}}
            <th v-for="col in columns" :key="col.name"
                :class="{'is-sortable': col.sortable, 'is-sorted': sortField === col.name, 'am-num': isNumeric(col)}"
                :data-dir="sortField === col.name ? direction : null"
                @click="col.sortable && toggleSort(col.name)"
                :tabindex="col.sortable ? 0 : null"
                @keydown.enter="col.sortable && toggleSort(col.name)">
              <span class="am-th-sort">
                @{{ col.label }}
                <svg v-if="col.sortable" class="am-th-sort__icon" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                  <path d="m7 10 5-5 5 5M7 14l5 5 5-5"/>
                </svg>
              </span>
            </th>
            <th class="am-table__actions" v-if="!readonly">操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in rows" :key="row.id">
            {{-- :data-l 供窄屏卡片化时生成字段标签（见 design-system 的 --stack 规则）--}}
            <td v-for="col in columns" :key="col.name"
                :data-l="col.label"
                :class="[cellClass(col, row), {'am-num': isNumeric(col)}]">
              <template v-if="col.formatter === 'datetime'">
                <span class="am-nowrap">@{{ formatDate(cellValue(col, row)) }}</span>
              </template>
              <template v-else-if="col.formatter === 'money'">@{{ formatMoney(cellValue(col, row), col) }}</template>
              <template v-else-if="col.formatter === 'bool'">
                <span class="am-badge" :class="cellValue(col, row) ? 'am-badge--success' : 'am-badge--muted'">
                  <i style="width:5px;height:5px;border-radius:50%;background:currentColor;display:inline-block"></i>@{{ cellValue(col, row) ? (col.props.trueLabel || '是') : (col.props.falseLabel || '否') }}
                </span>
              </template>
              <template v-else-if="col.formatter === 'badge' || col.formatter === 'map' || col.formatter === 'enum'">
                <span class="am-badge" :class="tagClass(col, row)">@{{ mapLabel(col, cellValue(col, row)) }}</span>
              </template>
              <template v-else-if="col.formatter === 'image'">
                <img v-if="cellValue(col, row)" :src="cellValue(col, row)" class="am-avatar am-avatar--sm" style="border-radius:6px">
                <span v-else class="am-text-muted">—</span>
              </template>
              <template v-else-if="col.formatter === 'link'">
                <a v-if="cellValue(col, row)" :href="cellValue(col, row)" target="_blank" class="am-text-brand">打开 ↗</a>
                <span v-else class="am-text-muted">—</span>
              </template>
              <template v-else-if="col.formatter === 'progress'">
                <span class="am-progress"><i :style="{width: Math.min(100, Number(cellValue(col,row)) || 0) + '%'}"></i></span>
              </template>
              <template v-else>
                <span :class="{'am-text-muted': cellValue(col, row) === '' || cellValue(col, row) === null}">
                  @{{ cellValue(col, row) === '' || cellValue(col, row) === null ? '—' : cellValue(col, row) }}
                </span>
              </template>
            </td>
            <td class="am-table__actions" data-l="" v-if="!readonly">
              <div class="am-flex am-gap-1 am-nowrap">
                <button class="am-btn am-btn--sm am-btn--ghost" @click="edit(row)">编辑</button>
                <button class="am-btn am-btn--sm am-btn--ghost" @click="remove(row)">删除</button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="am-pagination" v-if="!loading && rows.length > 0">
      <button class="am-btn am-btn--sm" :disabled="currentPage <= 1" @click="load(currentPage - 1)">上一页</button>
      <span class="am-pagination__page">@{{ currentPage }} / @{{ lastPage }}</span>
      <button class="am-btn am-btn--sm" :disabled="currentPage >= lastPage" @click="load(currentPage + 1)">下一页</button>
      <span class="am-pagination__spacer"></span>
      <span class="am-text-sm am-text-muted">共 @{{ total }} 条</span>
    </div>
  </div>

  {{-- 表单弹窗 --}}
  <div class="am-overlay" v-if="showCreate" @click.self="showCreate = false">
    <div class="am-modal" :class="{'am-modal--lg': isStepped}">
      <div class="am-modal__head">
        <span class="am-modal__title">@{{ editing ? '编辑' : '新增' }}{{ $label }}</span>
        <button class="am-modal__close" @click="showCreate = false" aria-label="关闭">
          @include('aimanong::partials.icon', ['name' => 'x', 'size' => 16])
        </button>
      </div>

      <div class="am-modal__body">
        {{-- 分步表单 --}}
        <div class="am-steps" v-if="isStepped">
          <div v-for="(s, i) in steps" :key="i" class="am-step"
               :class="{'is-active': i === currentStep, 'is-done': i < currentStep}"
               :data-clickable="(editing || i <= currentStep) ? 'true' : 'false'"
               @click="goStep(i)">
            <span class="am-step__dot">@{{ i < currentStep ? '✓' : i + 1 }}</span>
            <span class="am-step__label">@{{ s.title }}</span>
            <span class="am-steps__line" v-if="i < steps.length - 1"></span>
          </div>
        </div>

        <div v-for="f in visibleFields" :key="f.name" class="am-field" v-show="!f.hidden">
          <label class="am-field__label">
            @{{ f.label }}<span v-if="f.required" class="am-field__required">*</span>
          </label>

          <select v-if="f.type === 'select'" v-model="form[f.name]" class="am-select">
            <option value="">请选择…</option>
            <option v-for="o in fieldOptions(f)" :key="o.value" :value="o.value">@{{ o.label }}</option>
          </select>

          <div v-else-if="f.type === 'radio'" class="am-check-group">
            <label v-for="o in fieldOptions(f)" :key="o.value" class="am-check">
              <input type="radio" :value="o.value" v-model="form[f.name]"><span>@{{ o.label }}</span>
            </label>
          </div>

          <div v-else-if="f.type === 'checkbox' || f.type === 'multiselect'" class="am-check-group">
            <label v-for="o in fieldOptions(f)" :key="o.value" class="am-check">
              <input type="checkbox" :value="o.value" v-model="form[f.name]"><span>@{{ o.label }}</span>
            </label>
          </div>

          <label v-else-if="f.type === 'switch'" class="am-switch">
            <input type="checkbox" v-model="form[f.name]"><span class="am-switch__track"></span>
            <span>@{{ form[f.name] ? '开' : '关' }}</span>
          </label>

          <div v-else-if="f.type === 'region'" class="am-flex am-gap-2">
            <select v-model="ensureRegion(f.name).province" @change="onProvinceChange(f.name)" class="am-select">
              <option value="">请选择省</option>
              <option v-for="p in (f.props.data || []).length ? f.props.data : regionProvinces" :key="p.code" :value="p.code">@{{ p.name }}</option>
            </select>
            <select v-model="ensureRegion(f.name).city" @change="onCityChange(f.name)" class="am-select">
              <option value="">请选择市</option>
              <option v-for="c in ensureRegion(f.name).cities" :key="c.code" :value="c.code">@{{ c.name }}</option>
            </select>
            <select v-if="(f.props.level || 3) === 3" v-model="ensureRegion(f.name).district" class="am-select">
              <option value="">请选择区</option>
              <option v-for="d in ensureRegion(f.name).districts" :key="d.code" :value="d.code">@{{ d.name }}</option>
            </select>
          </div>

          <input v-else-if="f.type === 'color'" type="color" v-model="form[f.name]" class="am-input" style="width:56px;padding:2px">
          <input v-else-if="f.type === 'slider'" type="range" v-model="form[f.name]" class="am-input" style="padding:0">
          <div v-else-if="f.type === 'rate'" class="am-flex am-gap-1">
            <span v-for="n in (f.props.max || 5)" :key="n" @click="form[f.name] = n"
                  :style="{cursor:'pointer',fontSize:'18px',color:(form[f.name] >= n) ? 'var(--warning-solid)' : 'var(--border-strong)'}">★</span>
          </div>
          <div v-else-if="f.type === 'daterange'" class="am-flex am-gap-2">
            <input type="date" v-model="form[f.name + '_start']" class="am-input">
            <input type="date" v-model="form[f.name + '_end']" class="am-input">
          </div>

          <textarea v-else-if="f.type === 'textarea'" v-model="form[f.name]" class="am-textarea" :rows="f.props.rows || 4"></textarea>
          <input v-else-if="f.type === 'date'" type="date" v-model="form[f.name]" class="am-input">
          <input v-else-if="f.type === 'datetime'" type="datetime-local" v-model="form[f.name]" class="am-input">
          <input v-else :type="inputType(f)" v-model="form[f.name]" class="am-input">

          <div v-if="f.props.help" class="am-field__help">@{{ f.props.help }}</div>
        </div>
      </div>

      <div class="am-modal__foot">
        <button class="am-btn" @click="showCreate = false">取消</button>
        <button v-if="isStepped && currentStep > 0" class="am-btn" @click="currentStep--">上一步</button>
        <button v-if="isStepped && currentStep < steps.length - 1" class="am-btn am-btn--primary"
                @click="currentStep++">下一步</button>
        <button v-else class="am-btn am-btn--primary" @click="save">保存</button>
      </div>
    </div>
  </div>

  {{-- ══ Toast 容器 ══
       右上角堆叠，成功自动消失；失败停留更久并显示原因。
       用 Teleport 到 body 避免被父容器的 overflow 裁切。 --}}
  <teleport to="body">
    <div class="am-toasts" v-if="toasts.length">
      <div v-for="t in toasts" :key="t.id" class="am-toast" :class="'am-toast--' + t.type"
           role="status" @click="dismissToast(t.id)">
        <span class="am-toast__icon">
          <svg v-if="t.type === 'success'" width="16" height="16" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="m5 13 4 4L19 7"/></svg>
          <svg v-else-if="t.type === 'danger'" width="16" height="16" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg>
          <svg v-else width="16" height="16" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>
        </span>
        <div class="am-toast__body">
          <div class="am-toast__title">@{{ t.title }}</div>
          <div class="am-toast__desc" v-if="t.desc">@{{ t.desc }}</div>
        </div>
      </div>
    </div>
  </teleport>

  {{-- ══ 删除确认 ══
       替代原生 confirm()：显示记录的可读标识（而非自增 ID），
       并明确提示不可撤销。 --}}
  <div class="am-overlay" v-if="confirmState.open" @click.self="confirmState.open = false">
    <div class="am-modal am-modal--sm">
      <div class="am-modal__head">
        <span class="am-modal__icon am-modal__icon--danger">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>
        </span>
        <span class="am-modal__title">确认删除</span>
      </div>
      <div class="am-modal__body">
        <p style="margin:0 0 8px">即将删除：<strong>@{{ confirmState.label }}</strong></p>
        <p class="am-text-sm am-text-muted" style="margin:0">此操作不可撤销。</p>
      </div>
      <div class="am-modal__foot">
        <button class="am-btn" @click="confirmState.open = false">取消</button>
        <button class="am-btn am-btn--danger" @click="doDelete">确认删除</button>
      </div>
    </div>
  </div>
</div>
@endsection

@push('scripts')
{{-- Vue 从框架自带的静态资源加载（不依赖外部 CDN）--}}
<script src="{{ \Aimanong\Aimanong::asset()->url('js/vue.global.prod.js') }}"></script>
<script>
const { createApp, ref, computed, onMounted } = Vue;

createApp({
    setup() {
        const schema = @json($schema);
        const uri = @json($uri);
        const treeConfig = schema.grid?.tree ?? null;
        const isTree = treeConfig !== null;

        /* ── Toast 通知 ──
           toast(title, desc, type) —— type: success | danger | warning | info
           自动消失（成功 2.6s / 失败 4.5s，失败留久一点让用户看清原因）。 */
        const toasts = ref([]);
        let toastSeq = 0;

        function toast(title, desc = '', type = 'info') {
            const id = ++toastSeq;
            toasts.value.push({ id, title, desc, type });
            setTimeout(() => {
                toasts.value = toasts.value.filter(t => t.id !== id);
            }, type === 'danger' ? 4500 : 2600);
        }

        function dismissToast(id) {
            toasts.value = toasts.value.filter(t => t.id !== id);
        }

        /* ── 删除确认弹窗 ── */
        const confirmState = ref({ open: false, id: null, label: '' });

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

        /**
         * 只读资源：后端已声明 Resource::readonly() 返回 true。
         * 前端据此隐藏「新增 / 编辑 / 删除」入口 —— 后端也会 403，
         * 但让用户点到一个必然失败的按钮是设计缺陷。
         */
        const readonly = computed(() => schema.readonly === true);

        const csrf = document.querySelector('meta[name="csrf-token"]').content;
        const base = `/admin/api/${uri}`;

        /**
         * 把「选项」统一成 [{value, label}] 数组。
         *
         * Schema 编译层输出的就是数组（`array_column($options, 'value')` 依赖这一点），
         * 但插件历史上可能塞过 `{值: 文案}` 映射表 —— 这里一并兼容。
         *
         * ⚠️ 早期模板把选项当**对象**遍历（`v-for="(text, val) in options"`），
         * 结果是：`val` 拿到数组下标、`text` 拿到整个 `{value,label}` 对象，
         * 于是下拉框把 JSON 原样打印出来、`:value` 全变成 0/1/2……
         * 必填下拉框因此永远保存不了值。
         */
        function fieldOptions(f) {
            const raw = f.props?.options;
            if (!raw) return [];
            if (Array.isArray(raw)) {
                return raw.map((o) => {
                    if (o && typeof o === 'object') {
                        return { value: o.value ?? o.id ?? '', label: o.label ?? o.name ?? String(o.value ?? '') };
                    }
                    return { value: o, label: String(o) };
                });
            }
            return Object.entries(raw).map(([value, label]) => ({ value, label }));
        }

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
        /** 判断列是否应该右对齐（数字/金额用等宽数字并右对齐） */
        function isNumeric(col) {
            const t = col.jsonType || col.props?.jsonType;
            return t === 'number' || t === 'integer' || col.formatter === 'money';
        }

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
            // 值为空时给个占位符，否则徽章会渲染成一个没有文字的灰点
            if (value === null || value === undefined || value === '') return '—';
            return value;
        }

        /** 根据值给出标签配色（状态类字段的语义色） */
        /**
         * 状态值 → 徽章样式类。
         *
         * 注意类名必须与 design-system.css 一致（am-badge--*），
         * 用旧类名（tag-on/tag-off）不会生效、会退化成默认灰。
         */
        /**
         * 状态值 → 徽章样式类。
         *
         * ⚠️ 签名是 (col, row) —— 模板里传的是列定义与行数据，
         * 不是直接传值。之前签名写成 (value) 导致收到的是 col 对象，
         * String(col) 变成 "[object object]"，永远落到 default 分支。
         */
        function tagClass(col, row) {
            const raw = cellValue(col, row);
            const v = String(raw ?? '').toLowerCase();

            /*
             * 数据字典声明的颜色优先。
             *
             * 下面那张中英文关键词表是「猜」—— 猜不出来就退化成品牌色，
             * 而且项目自定义的状态值（如 'partial_refund'）必然猜错。
             * 字典里显式写了 color，就不再猜。
             */
            const dictColors = col.props?.dictColors;
            if (dictColors) {
                const c = dictColors[String(raw ?? '')];
                if (c) return 'am-badge--' + c;
            }
            // 英文状态值
            const positive = ['1', 'true', 'yes', 'active', 'enabled', 'success', 'paid',
                              'on_sale', 'published', 'resolved', 'completed', 'approved'];
            const warning  = ['pending', 'review', 'draft', 'processing', 'waiting',
                              'reviewing', 'submitted'];
            const negative = ['0', 'false', 'no', 'disabled', 'failed', 'closed',
                              'sold_out', 'refunded', 'archived', 'rejected', 'cancelled'];
            // 中文状态值（map() 映射后的显示文本也要能识别）
            // 信息类（进行中但非终态）
            const info = ['shipped', 'shipping', 'delivering', 'confirmed', 'submitted', 'accepted'];
            const zhPositive = ['已发布', '已完成', '已付款', '在售', '启用', '是', '正常', '成功'];
            const zhInfo     = ['已发货', '已受理', '已确认', '已提交'];
            const zhWarning  = ['待审', '待审核', '草稿', '处理中', '待付款', '审核中', '待处理'];
            const zhNegative = ['已下架', '售罄', '已归档', '已退款', '已取消', '禁用', '否', '失败', '已驳回'];

            if (positive.includes(v) || zhPositive.includes(v)) return 'am-badge--success';
            if (info.includes(v) || zhInfo.includes(v)) return 'am-badge--info';
            if (warning.includes(v)  || zhWarning.includes(v))  return 'am-badge--warning';
            if (negative.includes(v) || zhNegative.includes(v)) return 'am-badge--muted';
            return 'am-badge--brand';
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

            /*
             * 每个字段都必须有初值，否则 v-model 会绑定 undefined。
             *
             * 后果（真实复现）：`<select v-model>` 被赋值 undefined 时
             * 匹配不到任何 <option>，浏览器把 selectedIndex 置为 -1
             * → 必填下拉框显示为**空白**，用户看不到「请选择…」提示，
             * 以为框是坏的。同理 checkbox 需要数组、其余需要空串。
             */
            for (const f of (schema.form?.fields ?? [])) {
                if (f.type === 'multiselect' || f.type === 'checkbox') {
                    form.value[f.name] = [];
                } else if (f.type === 'switch') {
                    form.value[f.name] = f.default ?? false;
                } else {
                    form.value[f.name] = f.default ?? '';
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

            /*
             * 补齐缺失字段 —— 与 openCreate 同理：API 返回 null / 缺字段时，
             * 若直接塞给 <select v-model>，selectedIndex 会变成 -1，
             * 编辑弹窗里的下拉框显示空白，用户无法判断当前值。
             */
            for (const f of (schema.form?.fields ?? [])) {
                if (form.value[f.name] === undefined || form.value[f.name] === null) {
                    form.value[f.name] = (f.type === 'multiselect' || f.type === 'checkbox')
                        ? []
                        : (f.type === 'switch' ? false : '');
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
                    // 校验错误（422）逐字段展示，比丢一坨 JSON 给用户友好得多
                    const fieldErrors = err.errors && typeof err.errors === 'object'
                        ? Object.values(err.errors).flat().slice(0, 3).join('；')
                        : '';
                    toast(
                        editing.value ? '保存失败' : '创建失败',
                        fieldErrors || err.message || '请检查表单填写',
                        'danger'
                    );
                    return;
                }
                showCreate.value = false;
                editing.value = false;
                form.value = {};
                // ⚠️ 顺序很重要：上面刚把 editing 置为 false，
                // 若在这里读 editing.value 永远走 else 分支，
                // 编辑保存也会提示「新记录已创建」。
                toast('已保存', isEdit ? '修改已生效' : '新记录已创建', 'success');
                load(currentPage.value);
            } catch (e) {
                toast('保存失败', e.message || '网络异常，请重试', 'danger');
            }
        }

        /**
         * 删除确认。
         *
         * ⚠️ 不再用原生 confirm()：
         *   1. 原生弹窗展示的是浏览器默认样式，与设计系统完全脱节
         *   2. 之前把「#12」这类**自增 ID 直接暴露**给用户（无业务含义、还泄漏内部计数）
         *   3. 原生 confirm 无法承载富文本（比如显示记录标题让用户确认删对了）
         *
         * 改为自定义弹窗：显示记录的主字段值（比 ID 有意义），并明确提示不可撤销。
         */
        function remove(row) {
            const label = rowLabel(row);
            confirmState.value = {
                open: true,
                id: row.id,
                label: label,
            };
        }

        /**
         * 取一条记录的「人类可读标识」，用于确认框。
         * 优先用列定义里的第一个可搜索文本列（通常是名称/标题），
         * 拿不到就退回 ID —— 但不显示 "#" 前缀，避免像内部编号。
         */
        function rowLabel(row) {
            const cols = schema.grid?.columns ?? [];
            for (const c of cols) {
                if (c.searchable && !c.name.includes('.')) {
                    const v = row[c.name];
                    if (v !== null && v !== undefined && String(v).trim() !== '') {
                        return String(v).slice(0, 60);
                    }
                }
            }
            // 退化：用前两个非空文本列拼一个
            const parts = [];
            for (const c of cols) {
                if (c.name.includes('.')) continue;
                const v = row[c.name];
                if (typeof v === 'string' && v.trim() !== '') parts.push(v.trim().slice(0, 24));
                if (parts.length >= 2) break;
            }
            return parts.length ? parts.join(' · ') : '这条记录';
        }

        async function doDelete() {
            const id = confirmState.value.id;
            confirmState.value.open = false;
            try {
                const res = await fetch(`${base}/${id}`, {
                    method: 'DELETE',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                });
                if (!res.ok) {
                    const err = await res.json().catch(() => ({}));
                    toast('删除失败', err.message || '可能已被他人删除', 'danger');
                } else {
                    toast('已删除', '', 'success');
                }
            } catch (e) {
                toast('删除失败', e.message || '网络异常', 'danger');
            }
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
            toasts, toast, dismissToast, confirmState, doDelete,
            regionProvinces, regionTree, regionForm, onProvinceChange, onCityChange, ensureRegion, openCreate, goStep,
            fieldOptions, readonly,
            isNumeric,
        };
    },
}).mount('#app');
</script>
@endpush
