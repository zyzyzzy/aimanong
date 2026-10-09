import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'AI 码农',
  description: 'AI-First 后台开发框架 —— 让任何 AI Agent 5 分钟理解、30 分钟产出后台系统',
  lang: 'zh-CN',
  base: '/',

  themeConfig: {
    nav: [
      { text: '指南', link: '/guide/quickstart' },
      { text: 'API 参考', link: '/api/' },
      { text: 'AI 协作', link: '/guide/ai-collaboration' },
    ],

    sidebar: {
      '/guide/': [
        {
          text: '入门',
          items: [
            { text: '快速开始', link: '/guide/quickstart' },
            { text: '核心概念', link: '/guide/concepts' },
            { text: 'AI 协作指南', link: '/guide/ai-collaboration' },
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
      message: '基于 MIT 许可开源',
      copyright: 'AI 码农 · Aimanong',
    },

    outline: { label: '本页目录', level: [2, 3] },
    docFooter: { prev: '上一篇', next: '下一篇' },
    lastUpdated: { text: '最后更新于' },
  },
})
