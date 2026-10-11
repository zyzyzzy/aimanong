{{--
  上传能力（公共部分）。

  单独抽出来是因为「个人中心」也要传头像 —— 复制一份上传逻辑，
  迟早会出现「后台能传、个人中心传不了」这种漂移。

  用法：
    @include('aimanong::partials.upload', ['uploadConfig' => $schema['upload'] ?? []])
    @include('aimanong::partials.upload', ['uploadConfig' => $upload])

  挂到 window.AimanongUpload：
    .config     上传配置（URL 模板、接口地址、白名单、体积上限）
    .csrf       CSRF token
    .url(v)     相对路径 → 可访问 URL
    .name(v)    相对路径 → 文件名
    .component  Vue 组件定义，用 app.component('am-upload', ...) 注册

  ⚠️ 内部变量一律包在 IIFE 里。顶层写 const UPLOAD 会与页面脚本
  里同名的 const 撞车 —— 全局词法环境重复声明 = 整个 script 不执行。
--}}
<script>
(function () {
  var CONFIG = @json($uploadConfig ?? []);
  var CSRF = (document.querySelector('meta[name=csrf-token]') || {}).content || '';

  /** 相对路径 → 可访问 URL（完整 URL / data: / 绝对路径原样返回） */
  function url(value) {
    if (!value || typeof value !== 'string') return '';
    if (/^(https?:)?\/\//.test(value) || value.indexOf('data:') === 0 || value.charAt(0) === '/') return value;
    return String(CONFIG.urlTemplate || '/{path}').replace('{path}', value);
  }

  /** 取文件名（路径最后一段） */
  function name(value) {
    if (!value || typeof value !== 'string') return '';
    var clean = value.split('?')[0].split('#')[0];
    var last = clean.substring(clean.lastIndexOf('/') + 1);
    try { return decodeURIComponent(last); } catch (e) { return last; }
  }

  window.AimanongUpload = { config: CONFIG, csrf: CSRF, url: url, name: name, component: null };
})();
</script>
<script>
(function () {
  var AU = window.AimanongUpload;

  /*
   * 自己解构 Vue 的响应式 API，**不依赖页面脚本的 const 解构**。
   *
   * 抽这个 partial 的初衷就是「任何页面都能用」。上一版让它闭包引用
   * 页面顶层的 ref/computed —— 结果个人中心只解构了 createApp/ref，
   * 组件一渲染就 ReferenceError: computed is not defined，
   * 而页面上只是"头像那一栏空了"，看不出是报错。
   */
  var Vue_ = window.Vue;
  var ref = Vue_.ref;
  var computed = Vue_.computed;

  window.AimanongUpload.component = {
      props: {
          modelValue: { default: null },
          multiple: { type: Boolean, default: false },
          kind: { type: String, default: 'file' },
          accept: { type: String, default: '' },
          maxSize: { type: Number, default: 0 },
          directory: { type: String, default: '' },
      },
      emits: ['update:modelValue'],
      setup(props, { emit }) {
          const input = ref(null);
          const uploading = ref(false);
          const error = ref('');

          /** 归一化成数组：后端可能返回 JSON 字符串（模型没做 array cast） */
          const items = computed(() => {
              const v = props.modelValue;
              if (v === null || v === undefined || v === '') return [];
              if (Array.isArray(v)) return v.filter(Boolean);
              if (typeof v === 'string') {
                  const t = v.trim();
                  if (t.startsWith('[')) {
                      try {
                          const parsed = JSON.parse(t);
                          return Array.isArray(parsed) ? parsed.filter(Boolean) : [];
                      } catch (e) {
                          return [v];
                      }
                  }
                  return [v];
              }
              return [v];
          });

          function emitValue(list) {
              emit('update:modelValue', props.multiple ? list : (list[0] || ''));
          }

          const acceptAttr = computed(() => {
              if (props.accept) return props.accept;
              const exts = props.kind === 'image' ? (AU.config.imageExtensions || []) : (AU.config.fileExtensions || []);
              return exts.map(e => '.' + e).join(',');
          });

          const limit = computed(() => props.maxSize || (props.kind === 'image'
              ? (AU.config.imageMaxSize || 0)
              : (AU.config.fileMaxSize || 0)));

          function pick() {
              error.value = '';
              if (input.value) input.value.click();
          }

          /** 上传前的即时反馈（服务端仍会再校验一次） */
          function precheck(file) {
              if (limit.value > 0 && file.size > limit.value * 1024) {
                  return '「' + file.name + '」超过 ' + limit.value + ' KB';
              }
              const dot = file.name.lastIndexOf('.');
              const ext = dot >= 0 ? file.name.substring(dot + 1).toLowerCase() : '';
              const allowed = (props.kind === 'image' ? AU.config.imageExtensions : AU.config.fileExtensions) || [];
              if (allowed.length && ext && !allowed.includes(ext)) {
                  return '「' + file.name + '」类型不在允许列表（' + allowed.join('/') + '）';
              }
              return '';
          }

          async function onPick(e) {
              const files = Array.from(e.target.files || []);
              e.target.value = '';
              if (files.length === 0) return;

              const list = items.value.slice();
              const room = props.multiple ? files.length : 1;
              const todo = files.slice(0, room);

              uploading.value = true;
              error.value = '';

              try {
                  for (const file of todo) {
                      const bad = precheck(file);
                      if (bad) { error.value = bad; continue; }

                      const fd = new FormData();
                      fd.append('file', file);
                      fd.append('kind', props.kind);
                      if (props.accept) fd.append('accept', props.accept);
                      if (props.directory) fd.append('directory', props.directory);

                      const res = await fetch(AU.config.endpoint, {
                          method: 'POST',
                          headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': AU.csrf },
                          body: fd,
                      });
                      const json = await res.json().catch(() => ({}));

                      if (!res.ok) {
                          error.value = json.message || ('上传失败（HTTP ' + res.status + '）');
                          continue;
                      }

                      const path = json.data && json.data.path;
                      if (!path) { error.value = '服务端未返回文件路径'; continue; }

                      if (props.multiple) list.push(path);
                      else { list.length = 0; list.push(path); }
                  }
                  emitValue(list);
              } catch (err) {
                  error.value = err.message || '上传失败';
              } finally {
                  uploading.value = false;
              }
          }

          function removeAt(i) {
              const list = items.value.slice();
              list.splice(i, 1);
              emitValue(list);
          }

          return {
              input: input, items: items, uploading: uploading, error: error,
              acceptAttr: acceptAttr, limit: limit, pick: pick, onPick: onPick, removeAt: removeAt,
              // 模板里仍叫 uploadUrl / uploadName
              uploadUrl: AU.url, uploadName: AU.name,
          };
      },
      template: `
        <div class="am-upload" :class="{'am-upload--multiple': multiple}">
          <template v-if="kind === 'image'">
            <div class="am-upload__grid">
              <div v-for="(item, i) in items" :key="item + i" class="am-upload__thumb">
                <img :src="uploadUrl(item)" :alt="uploadName(item)">
                <button type="button" class="am-upload__del" title="移除" @click="removeAt(i)">&times;</button>
              </div>
              <button v-if="multiple || items.length === 0" type="button" class="am-upload__add"
                      :disabled="uploading" @click="pick">
                <template v-if="uploading">上传中…</template>
                <template v-else><span class="am-upload__plus">＋</span><span>选择图片</span></template>
              </button>
            </div>
          </template>

          <template v-else>
            <ul v-if="items.length" class="am-upload__files">
              <li v-for="(item, i) in items" :key="item + i">
                <a :href="uploadUrl(item)" target="_blank" rel="noopener">@{{ uploadName(item) }}</a>
                <button type="button" class="am-btn am-btn--sm am-btn--ghost" @click="removeAt(i)">移除</button>
              </li>
            </ul>
            <button v-if="multiple || items.length === 0" type="button" class="am-btn am-btn--sm"
                    :disabled="uploading" @click="pick">@{{ uploading ? '上传中…' : '选择文件' }}</button>
          </template>

          <div v-if="error" class="am-upload__err">@{{ error }}</div>
          <div v-else-if="limit && kind === 'image'" class="am-field__help">单个文件不超过 @{{ limit }} KB</div>
          <input ref="input" type="file" :accept="acceptAttr" :multiple="multiple" hidden @change="onPick">
        </div>
      `,
  };
})();
</script>
