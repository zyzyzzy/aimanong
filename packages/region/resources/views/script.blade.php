{{--
  省市区字段的 JS 逻辑 —— **由插件自己提供**。

  这段代码在框架页面的 Vue `setup()` **作用域内**渲染，
  因此可以直接使用 `ref` / `form`，并通过 `Object.assign(pluginExports, ...)`
  把函数暴露给模板（与内置字段同等待遇）。
--}}
const regionTree = @json(\Aimanong\Region\Region::tree());
const regionProvinces = @json(\Aimanong\Region\Region::provinces());
const regionForm = ref({});

/** 字段定义（regionSync 要读 storeCode） */
const regionFields = {};

/**
 * 按编码路径或名称路径回填级联状态。
 *
 * 存进去的值可能是「广东省/深圳市/南山区」（默认），也可能是编码路径
 * （->storeCode()）。两种都要认得，否则编辑已有记录时下拉框是空的。
 */
function regionFill(st, path) {
    const parts = String(path || '').split('/').filter(Boolean);
    if (!parts.length) return;

    const byCode = /^\d+$/.test(parts[0]);
    const find = (nodes, key) => (nodes || []).find(x => (byCode ? x.code : x.name) === key);

    const p = find(regionTree, parts[0]);
    if (!p) return;
    st.province = p.code;
    st.cities = p.children || [];

    if (parts.length < 2) return;
    const c = find(st.cities, parts[1]);
    if (!c) return;
    st.city = c.code;
    st.districts = c.children || [];

    if (parts.length < 3) return;
    const d = find(st.districts, parts[2]);
    if (!d) return;
    st.district = d.code;
}

/**
 * 取（必要时创建）某个字段的级联状态。
 *
 * 🔴 必须能**自愈回填** —— 原因是框架的调用顺序：
 *
 *     edit(row)：
 *       pluginFieldInit(f)     ← 插件在这里初始化状态
 *       form.value = {...row}  ← 表单值**之后**才有
 *
 * 也就是钩子跑的时候 `form.value[name]` 还是空的。照做就会得到一个
 * **永远空白的三级下拉**，而库里明明有值（编辑时看到三个空框）。
 *
 * 所以改成：只要「状态是空的、表单值有内容」就回填。这样无论是
 * 编辑回填、还是异步拿到值，都会自动补上。
 */
function regionState(name) {
    if (!regionForm.value[name]) {
        regionForm.value[name] = {
            province: '', city: '', district: '',
            cities: [], districts: [],
        };
    }

    const st = regionForm.value[name];

    if (!st.province) {
        regionFill(st, form.value ? form.value[name] : '');
    }

    return st;
}

function regionOnProvince(name) {
    const st = regionState(name);
    st.city = '';
    st.district = '';
    st.districts = [];
    const p = (regionTree || []).find(x => x.code === st.province);
    st.cities = p ? (p.children || []) : [];
    regionSync(name);
}

function regionOnCity(name) {
    const st = regionState(name);
    st.district = '';
    const p = (regionTree || []).find(x => x.code === st.province);
    const c = p ? (p.children || []).find(x => x.code === st.city) : null;
    st.districts = c ? (c.children || []) : [];
    regionSync(name);
}

/**
 * 把三级选择拼成路径写入表单值。
 *
 * 🔴 这里必须处理 `storeCode`。
 *
 * 字段类里有 `->storeCode()`（类文档也写了「或编码（通过 ->storeCode() 切换）」），
 * 但它**原先零消费方** —— 全仓库搜 `storeCode` 只命中字段类自己和它的测试，
 * 前端从不读这个 props。
 *
 * 症状：使用者写了 `$form->region('area')->storeCode()`，
 * 库里存的仍然是「广东省/深圳市/南山区」这种名称路径。
 * 不报错、不符合预期，而且**从界面上看不出来**。
 */
function regionSync(name) {
    const st = regionState(name);
    const p = (regionTree || []).find(x => x.code === st.province);
    const c = p ? (p.children || []).find(x => x.code === st.city) : null;
    const d = c ? (c.children || []).find(x => x.code === st.district) : null;

    const def = regionFields[name];
    const useCode = !!(def && def.props && def.props.storeCode);

    form.value[name] = useCode
        ? [p?.code, c?.code, d?.code].filter(Boolean).join('/')
        : [p?.name, c?.name, d?.name].filter(Boolean).join('/');
}

/*
 * 打开表单时预置字段状态 —— 否则模板里 regionState(name).province
 * 会拿到 undefined。
 */
pluginFieldInit = function (f) {
    if (f.type !== 'region') return;

    regionFields[f.name] = f;   // regionSync 要读 storeCode
    regionState(f.name);        // 回填在 regionState 内部自愈处理
};

/*
 * 暴露给模板。
 *
 * ⚠️ 必须包含 regionProvinces / regionTree ——
 * 它们是 const，**不会自动出现在模板作用域里**。
 * 漏掉 regionProvinces 的症状：省份下拉只剩「请选择省」，
 * 页面不报错、控制台也干净，只有真的去点下拉才发现是空的。
 */
Object.assign(pluginExports, {
    regionState, regionOnProvince, regionOnCity, regionSync,
    regionProvinces, regionTree,
});
