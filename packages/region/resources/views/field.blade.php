{{--
  省市区三级联动字段 —— **由插件自己提供**。

  这个片段原先是硬编码在框架核心的 resource.blade.php 里的，
  导致 framework 隐式依赖 region：只装核心包的用户打开列表页直接 Fatal error。

  现在它归插件所有，框架只负责在合适的字段类型上 include 它。

  可用变量（由框架注入）：
    pluginType —— 本片段的字段类型名
  以及页面已注入的 Vue 上下文：form / fieldOptions / pluginExports 等。

  ⚠️ 注释里不要写 blade 指令名（包括带 @ 的 PHP 块标记）——
     blade 的解析不受注释保护，会把它们当真实指令处理，
     导致注释块提前结束、模板结构错乱。
--}}
<div v-else-if="f.type === '{{ $pluginType }}'" class="am-flex am-gap-2">
  <select v-model="regionState(f.name).province" @change="regionOnProvince(f.name)" class="am-select">
    <option value="">请选择省</option>
    <option v-for="p in (f.props.data || []).length ? f.props.data : regionProvinces"
            :key="p.code" :value="p.code">@{{ p.name }}</option>
  </select>

  <select v-model="regionState(f.name).city" @change="regionOnCity(f.name)" class="am-select">
    <option value="">请选择市</option>
    <option v-for="c in regionState(f.name).cities" :key="c.code" :value="c.code">@{{ c.name }}</option>
  </select>

  {{-- 区级也必须 @change 回写：漏了它，area 只会存到「市」
       （实测：选完省/市/区，库里是「北京市/市辖区」—— 区没了且不报错）

       这里用 `f.props.level === 3`，不再用 `(f.props.level || 3)` ——
       level 现在由 RegionField 的构造函数保证一定下发，
       前端不该再用 `|| 3` 猜默认值（改了默认值两边会不一致）。 --}}
  <select v-if="f.props.level === 3"
          v-model="regionState(f.name).district"
          @change="regionSync(f.name)" class="am-select">
    <option value="">请选择区</option>
    <option v-for="d in regionState(f.name).districts" :key="d.code" :value="d.code">@{{ d.name }}</option>
  </select>
</div>
