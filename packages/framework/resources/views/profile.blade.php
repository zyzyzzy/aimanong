@extends('aimanong::partials.layout')

@section('content')
<div id="app" v-cloak>
  <div class="am-page-head">
    <div>
      <h1 class="am-page-head__title">个人中心</h1>
      <div class="am-page-head__desc">
        <span>@{{ user.name || user.username }}</span>
        <span class="am-text-muted"> · </span>
        <span class="am-text-muted">@{{ user.username }}</span>
      </div>
    </div>
  </div>

  <div class="am-tabs">
    <button v-for="t in tabs" :key="t.key" type="button"
            class="am-tabs__item" :class="{'is-active': tab === t.key}"
            @click="tab = t.key">@{{ t.label }}
      <span v-if="t.key === 'ops' && operations.length" class="am-tabs__count">@{{ operations.length }}</span>
    </button>
  </div>

  {{-- ── 基本资料 ── --}}
  <div class="am-card" v-show="tab === 'basic'">
    <div class="am-card__body">
      <div class="am-field">
        <label class="am-field__label">头像</label>
        <am-upload v-model="form.avatar" kind="image"></am-upload>
      </div>

      <div class="am-field">
        <label class="am-field__label">账号</label>
        <input class="am-input" :value="user.username" disabled>
        <div class="am-field__help">账号由管理员分配，不能自行修改。</div>
      </div>

      <div class="am-field">
        <label class="am-field__label">姓名<span class="am-field__required">*</span></label>
        <input class="am-input" v-model="form.name" placeholder="请输入姓名">
      </div>

      <div class="am-field">
        <label class="am-field__label">邮箱</label>
        <input class="am-input" v-model="form.email" placeholder="用于接收系统通知">
      </div>

      <div class="am-field">
        <label class="am-field__label">手机号</label>
        <input class="am-input" v-model="form.phone" placeholder="选填">
      </div>

      <div class="am-flex am-gap-2" style="margin-top:var(--sp-4)">
        <button class="am-btn am-btn--primary" :disabled="saving" @click="saveProfile">
          @{{ saving ? '保存中…' : '保存资料' }}
        </button>
        <span class="am-text-sm am-text-muted" v-if="basicError" style="color:var(--danger-text)">@{{ basicError }}</span>
      </div>
    </div>
  </div>

  {{-- ── 修改密码 ── --}}
  <div class="am-card" v-show="tab === 'password'">
    <div class="am-card__body">
      <div class="am-field">
        <label class="am-field__label">当前密码<span class="am-field__required">*</span></label>
        <input class="am-input" type="password" v-model="pwd.current_password" autocomplete="current-password">
        <div class="am-field__help">需要验证当前密码 —— 防止会话被劫持后直接改密码。</div>
      </div>

      <div class="am-field">
        <label class="am-field__label">新密码<span class="am-field__required">*</span></label>
        <input class="am-input" type="password" v-model="pwd.password" autocomplete="new-password">
        <div class="am-field__help">至少 6 位。</div>
      </div>

      <div class="am-field">
        <label class="am-field__label">确认新密码<span class="am-field__required">*</span></label>
        <input class="am-input" type="password" v-model="pwd.password_confirmation" autocomplete="new-password">
      </div>

      <div class="am-flex am-gap-2" style="margin-top:var(--sp-4)">
        <button class="am-btn am-btn--primary" :disabled="savingPwd" @click="savePassword">
          @{{ savingPwd ? '提交中…' : '修改密码' }}
        </button>
        <span class="am-text-sm" v-if="pwdError" style="color:var(--danger-text)">@{{ pwdError }}</span>
      </div>
    </div>
  </div>

  {{-- ── 我的权限 ── --}}
  <div class="am-card" v-show="tab === 'perms'">
    <div class="am-card__body">
      <h3 class="am-card__title">我的角色</h3>
      <div class="am-flex am-gap-2" style="flex-wrap:wrap;margin-bottom:var(--sp-4)">
        <span v-for="r in roles" :key="r" class="am-badge am-badge--brand">@{{ r }}</span>
        <span v-if="!roles.length" class="am-text-muted">未分配角色</span>
      </div>

      <h3 class="am-card__title">我的权限</h3>
      <p class="am-text-sm am-text-muted" v-if="isSuper">
        超级管理员：拥有全部权限，不受权限节点限制。
      </p>
      <template v-else>
        <p class="am-text-sm am-text-muted" v-if="!permissions.length">暂无权限节点。</p>
        <div class="am-perm-list">
          <code v-for="p in permissions" :key="p">@{{ p }}</code>
        </div>
      </template>
    </div>
  </div>

  {{-- ── 我的操作记录 ── --}}
  <div class="am-card" v-show="tab === 'ops'">
    <div class="am-table-wrap am-table-wrap--stack" v-if="operations.length">
      <table class="am-table" style="--col-count:5">
        <thead>
          <tr>
            <th>时间</th><th>动作</th><th>资源</th><th>记录 ID</th><th>IP</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(o, i) in operations" :key="i">
            <td data-l="时间">@{{ o.at }}</td>
            <td data-l="动作"><span class="am-badge am-badge--brand">@{{ o.action }}</span></td>
            <td data-l="资源">@{{ o.resource || '—' }}</td>
            <td data-l="记录 ID">@{{ o.target || '—' }}</td>
            <td data-l="IP">@{{ o.ip || '—' }}</td>
          </tr>
        </tbody>
      </table>
    </div>
    <div class="am-empty" v-else>
      <div class="am-empty__title">暂无操作记录</div>
      <div class="am-empty__desc">你在后台的新增 / 修改 / 删除操作会显示在这里</div>
    </div>
  </div>

  {{-- Toast --}}
  <teleport to="body">
    <div class="am-toasts" v-if="toasts.length">
      <div v-for="t in toasts" :key="t.id" class="am-toast" :class="'am-toast--' + t.type" @click="dismiss(t.id)">
        <div class="am-toast__body">
          <div class="am-toast__title">@{{ t.title }}</div>
          <div class="am-toast__desc" v-if="t.desc">@{{ t.desc }}</div>
        </div>
      </div>
    </div>
  </teleport>
</div>
@endsection

@push('scripts')
<script src="{{ \Aimanong\Aimanong::asset()->url('js/vue.global.prod.js') }}"></script>
@include('aimanong::partials.upload', ['uploadConfig' => $upload])

@php
  $profileUrl = url(\Aimanong\Aimanong::url('api/profile'));
  $passwordUrl = url(\Aimanong\Aimanong::url('api/profile/password'));
@endphp

<style>
/* 个人中心的标签页与权限列表（页面级少量样式，不进设计系统） */
.am-tabs { display: flex; gap: var(--sp-1); margin-bottom: var(--sp-4); border-bottom: 1px solid var(--border-subtle); }
.am-tabs__item {
  position: relative;
  padding: 9px 14px;
  border: 0;
  background: none;
  font: inherit;
  font-size: var(--fs-base);
  color: var(--text-secondary);
  cursor: pointer;
  border-bottom: 2px solid transparent;
  transition: color var(--dur) var(--ease), border-color var(--dur) var(--ease);
}
.am-tabs__item:hover { color: var(--text-primary); }
.am-tabs__item.is-active { color: var(--brand-text); border-bottom-color: var(--brand-solid); font-weight: 600; }
.am-tabs__count {
  display: inline-block; margin-left: 6px; padding: 0 6px;
  border-radius: var(--r-full); background: var(--bg-active);
  font-size: var(--fs-xs); color: var(--text-tertiary);
}
.am-perm-list { display: flex; flex-wrap: wrap; gap: 6px; }
.am-perm-list code {
  padding: 2px 8px; border-radius: var(--r-sm);
  background: var(--bg-subtle); border: 1px solid var(--border-subtle);
  font-size: var(--fs-xs); color: var(--text-secondary);
}
.am-card__title { font-size: var(--fs-md); font-weight: var(--fw-semibold); margin: 0 0 var(--sp-3); }
</style>

<script>
const { createApp, ref } = Vue;
const UPLOAD = window.AimanongUpload.config;
const CSRF = window.AimanongUpload.csrf;

createApp({
    setup() {
        const user = @json($userData);
        const roles = @json($roles);
        const permissions = @json($permissions);
        const operations = @json($operations);
        const isSuper = permissions.includes('__super__');

        const tabs = [
            { key: 'basic', label: '基本资料' },
            { key: 'password', label: '修改密码' },
            { key: 'perms', label: '我的权限' },
            { key: 'ops', label: '我的操作记录' },
        ];
        const tab = ref('basic');

        const form = ref({
            name: user.name || '',
            email: user.email || '',
            phone: user.phone || '',
            avatar: user.avatar || '',
        });

        const pwd = ref({ current_password: '', password: '', password_confirmation: '' });
        const saving = ref(false);
        const savingPwd = ref(false);
        const basicError = ref('');
        const pwdError = ref('');

        const toasts = ref([]);
        let seq = 0;
        function toast(title, desc, type) {
            const id = ++seq;
            toasts.value.push({ id, title, desc: desc || '', type: type || 'success' });
            setTimeout(() => { toasts.value = toasts.value.filter(t => t.id !== id); },
                type === 'danger' ? 4500 : 2600);
        }
        function dismiss(id) { toasts.value = toasts.value.filter(t => t.id !== id); }

        /** 把 422 的字段错误拼成一句人话 */
        function fieldErrors(json) {
            if (json && json.errors && typeof json.errors === 'object') {
                return Object.values(json.errors).flat().slice(0, 3).join('；');
            }
            return (json && json.message) || '保存失败';
        }

        async function post(url, body) {
            const res = await fetch(url, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': CSRF,
                },
                body: JSON.stringify(body),
            });
            const json = await res.json().catch(() => ({}));
            return { ok: res.ok, json };
        }

        async function saveProfile() {
            basicError.value = '';
            saving.value = true;
            try {
                const r = await post(@json($profileUrl), form.value);
                if (!r.ok) { basicError.value = fieldErrors(r.json); toast('保存失败', basicError.value, 'danger'); return; }
                toast('已保存', '资料已更新');
            } catch (e) {
                basicError.value = e.message;
            } finally {
                saving.value = false;
            }
        }

        async function savePassword() {
            pwdError.value = '';
            if (pwd.value.password !== pwd.value.password_confirmation) {
                pwdError.value = '两次输入的新密码不一致';
                toast('修改失败', pwdError.value, 'danger');
                return;
            }
            savingPwd.value = true;
            try {
                const r = await post(@json($passwordUrl), pwd.value);
                if (!r.ok) { pwdError.value = fieldErrors(r.json); toast('修改失败', pwdError.value, 'danger'); return; }
                pwd.value = { current_password: '', password: '', password_confirmation: '' };
                toast('已修改', '下次登录请使用新密码');
            } catch (e) {
                pwdError.value = e.message;
            } finally {
                savingPwd.value = false;
            }
        }

        return {
            user, roles, permissions, operations, isSuper,
            tabs, tab, form, pwd,
            saving, savingPwd, basicError, pwdError,
            toasts, dismiss, saveProfile, savePassword,
        };
    },
}).component('am-upload', window.AimanongUpload.component).mount('#app');
</script>
@endpush
