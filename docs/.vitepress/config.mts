import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'AI 码农',
  description: 'Aimanong（AI 码农）后台开发框架 —— 让任何 AI Agent 5 分钟理解、30 分钟产出后台系统',
  lang: 'zh-CN',

  // LOGO
  head: [
    ['link', { rel: 'icon', type: 'image/png', href: '/favicon-32.png' }],
    ['link', { rel: 'apple-touch-icon', href: '/apple-touch-icon.png' }],
  ],
  // 部署到 GitHub Pages 项目站点时路径是 /aimanong/，
  // 本地开发保持 / —— 用环境变量切换，避免两处硬编码不一致。
  base: process.env.DOCS_BASE || '/',

  themeConfig: {
    logo: '/assets/logo.png',
    nav: [
      { text: '指南', link: '/guide/quickstart' },
      { text: 'API 参考', link: '/api/' },
      { text: 'AI 使用手册', link: '/guide/ai-handbook' },
      { text: 'Playground', link: '/playground/' },
      { text: '实测报告', link: '/blog/ai-first-in-practice' },
    ],

    sidebar: {
      '/guide/': [
        {
          text: '入门',
          items: [
            { text: '快速开始', link: '/guide/quickstart' },
            { text: '核心概念', link: '/guide/concepts' },
          ],
        },
        {
          text: 'AI 协作',
          items: [
            { text: 'AI 使用手册', link: '/guide/ai-handbook' },
            { text: 'AI 协作指南（技术向）', link: '/guide/ai-collaboration' },
          ],
        },
        {
          text: '基座能力',
          items: [
            { text: '基座能力总览', link: '/guide/foundation' },
          ],
        },
        {
          text: '开发',
          items: [
            { text: '字段类型', link: '/api/fields' },
            { text: '列展示器', link: '/api/columns' },
            { text: 'Resource 详情', link: '/api/resources' },
            { text: 'HTTP API', link: '/api/api' },
          ],
        },
      ],
      '/api/': [
        {
          text: 'API 参考',
          items: [
            { text: '总览', link: '/api/' },
            { text: '字段类型', link: '/api/fields' },
            { text: '列展示器', link: '/api/columns' },
            { text: 'Resource 详情', link: '/api/resources' },
            { text: 'HTTP API', link: '/api/api' },
          ],
        },
      ],
    },

    socialLinks: [
      { icon: 'github', link: 'https://github.com/zyzyzzy/aimanong' },
    ],

    footer: {
      message: '基于 MIT 许可开源 · 允许免费商用',
      copyright: 'Aimanong（AI 码农）',
    },

    outline: { label: '本页目录', level: [2, 3] },
    docFooter: { prev: '上一篇', next: '下一篇' },
    lastUpdated: { text: '最后更新于' },
  },
})
