{{--
  内联 SVG 图标集。

  ## 为什么不用 emoji / iconfont / Iconify CDN
    - emoji：跨平台渲染不一致、无法控色、显得廉价
    - iconfont / Iconify CDN：离线不可用、有外部依赖
    - 内联 SVG：可控色（currentColor）、可控粗细、零依赖、体积小

  ## 用法
    @include('aimanong::partials.icon', ['name' => 'article', 'size' => 16])

  ## AI 提示
    新增图标请加在下面的 match 里，name 用小写连字符。
--}}
@php
  $name = $name ?? 'file';
  $size = $size ?? 16;
  $stroke = $stroke ?? 2;

  // 用纯路径描述，统一 24x24 视口、stroke 描边风格（Lucide 风格）
  $paths = [
      'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
      'article'   => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/>',
      'folder'    => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
      'user'      => '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c0-3.5 3-6 7-6s7 2.5 7 6"/>',
      'users'     => '<circle cx="9" cy="8" r="3"/><path d="M3 19c0-3 2.5-5 6-5s6 2 6 5"/><path d="M16 5.5a3 3 0 0 1 0 5.8M18 19c0-2-.6-3.6-1.7-4.8"/>',
      'tag'       => '<path d="M3 12V5a2 2 0 0 1 2-2h7l9 9-9 9z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
      'package'   => '<path d="M3 7l2-4h14l2 4v12H3z"/><path d="M3 7h18M12 3v4"/>',
      'cart'      => '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M3 4h2l2.5 11h11L21 7H6"/>',
      'receipt'   => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6"/>',
      'shield'    => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/>',
      'key'       => '<circle cx="8" cy="14" r="4"/><path d="M11 11l8-8 2 2-2 2 2 2-2 2-2-2-2 2"/>',
      'building'  => '<path d="M4 21V4a1 1 0 0 1 1-1h9a1 1 0 0 1 1 1v17"/><path d="M15 10h4a1 1 0 0 1 1 1v10M8 7h3M8 11h3M8 15h3M2 21h20"/>',
      'settings'  => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-2.9 1.2v.2a2 2 0 1 1-4 0v-.1A1.7 1.7 0 0 0 7 19.4a1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.7 1.7 0 0 0 2.6 14H2.4a2 2 0 1 1 0-4h.1A1.7 1.7 0 0 0 4.6 7a1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A1.7 1.7 0 0 0 10 2.6V2.4a2 2 0 1 1 4 0v.1A1.7 1.7 0 0 0 17 4.6a1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0 1.2 2.9h.2a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.6 1z"/>',
      'search'    => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
      'plus'      => '<path d="M12 5v14M5 12h14"/>',
      'edit'      => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
      'trash'     => '<path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/>',
      'download'  => '<path d="M12 3v12M7 10l5 5 5-5M5 21h14"/>',
      'upload'    => '<path d="M12 21V9M7 14l5-5 5 5M5 3h14"/>',
      'refresh'   => '<path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/><path d="M3 21v-5h5"/>',
      'chevron-right' => '<path d="m9 6 6 6-6 6"/>',
      'chevron-down'  => '<path d="m6 9 6 6 6-6"/>',
      'check'     => '<path d="m5 13 4 4L19 7"/>',
      'x'         => '<path d="M18 6 6 18M6 6l12 12"/>',
      'logout'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/>',
      'palette'   => '<circle cx="13.5" cy="6.5" r="1"/><circle cx="17.5" cy="10.5" r="1"/><circle cx="8.5" cy="7.5" r="1"/><circle cx="6.5" cy="12.5" r="1"/><path d="M12 2a10 10 0 0 0 0 20 2.5 2.5 0 0 0 2-4 2.5 2.5 0 0 1 2-4h2.5A4 4 0 0 0 22 10a10 10 0 0 0-10-8z"/>',
      'file'      => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>',
      'clock'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
      'image'     => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9.5" r="1.5"/><path d="m4 17 5-5 4 4 3-3 4 4"/>',
      'inbox'     => '<path d="M3 12h5l2 3h4l2-3h5"/><path d="M5.5 5h13l2.5 7v7H3v-7z"/>',
      'menu'      => '<path d="M4 6h16M4 12h16M4 18h16"/>',
      'alert'     => '<path d="M12 3 2 20h20z"/><path d="M12 10v4M12 17h.01"/>',
      'info'      => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
      'lock'      => '<rect x="4" y="10" width="16" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
      'mail'      => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
      'phone'     => '<path d="M5 3h4l2 5-2.5 1.5a12 12 0 0 0 6 6L16 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 5a2 2 0 0 1 2-2z"/>',
      'database'  => '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
      'layers'    => '<path d="m12 3 9 5-9 5-9-5z"/><path d="m3 13 9 5 9-5"/>',
      'trending'  => '<path d="m3 17 6-6 4 4 8-8"/><path d="M15 7h6v6"/>',
  ];

  $path = $paths[$name] ?? $paths['file'];
@endphp
<svg xmlns="http://www.w3.org/2000/svg" width="{{ $size }}" height="{{ $size }}"
     viewBox="0 0 24 24" fill="none" stroke="currentColor"
     stroke-width="{{ $stroke }}" stroke-linecap="round" stroke-linejoin="round"
     aria-hidden="true" focusable="false">{!! $path !!}</svg>
