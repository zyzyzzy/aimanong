{{--
  省市区字段的 JS 逻辑 —— **由插件自己提供**。

  这段代码在框架页面的 Vue `setup()` **作用域内**渲染，
  因此可以直接使用 `ref` / `form`，并通过 `Object.assign(pluginExports, ...)`
  把函数暴露给模板（与内置字段同等待遇）。
--}}
const regionTree = @json(\Aimanong\Region\Region::tree());
const regionProvinces = @json(\Aimanong\Region\Region::provinces());
const regionForm = ref({});

/** 取（必要时创建）某个字段的级联状态 */
function regionState(name) {
    if (!regionForm.value[name]) {
        regionForm.value[name] = {
            province: '', city: '', district: '',
            cities: [], districts: [],
        };
    }
    return regionForm.value[name];
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

/** 把三级选择拼成路径写入表单值 */
function regionSync(name) {
    const st = regionState(name);
    const p = (regionTree || []).find(x => x.code === st.province);
    const c = p ? (p.children || []).find(x => x.code === st.city) : null;
    const d = c ? (c.children || []).find(x => x.code === st.district) : null;

    form.value[name] = [p?.name, c?.name, d?.name].filter(Boolean).join('/');
}

/*
 * 打开表单时预置字段状态 —— 否则模板里 regionState(name).province
 * 会拿到 undefined。
 */
pluginFieldInit = function (f) {
    if (f.type === 'region') regionState(f.name);
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
