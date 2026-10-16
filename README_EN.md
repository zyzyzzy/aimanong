<div align="center">
  <img src="docs/public/assets/logo.png" alt="Aimanong" width="160" />

# Aimanong (AI Coder)

> **An AI-first PHP admin framework** — one declaration, four artifacts, AI builds the rest.

[![Version](https://img.shields.io/badge/version-1.11.0-blue.svg)](./CHANGELOG.md)
[![License: Apache-2.0](https://img.shields.io/badge/License-Apache_2.0-blue.svg)](./LICENSE)
[![PHP](https://img.shields.io/badge/PHP-%5E8.2-blue.svg)](https://www.php.net)
[![Laravel](https://img.shields.io/badge/Laravel-%5E12.0-red.svg)](https://laravel.com)
[![ThinkPHP](https://img.shields.io/badge/ThinkPHP-%5E8.0-green.svg)](https://www.thinkphp.cn)

🌐 English | **[中文](./README.md)**

</div>

## What Is This

Aimanong is an **AI-first PHP admin framework**. You write a single declarative Resource class; the framework compiles it into JSON Schema, TypeScript types, OpenAPI docs, and an AI context file. The frontend is rendered entirely from Schema — no hand-written Vue components needed.

Design principles are defined in the [Project Constitution](./CONSTITUTION.md).

## Who It's For

| ✅ Yes | ❌ No |
|---|---|
| Programmers with coding fundamentals | People with zero programming knowledge |
| Comfortable with CLI and PHP environments | Those who can't set up a dev environment |
| Can review what AI writes (without writing it yourself) | Those who cannot judge code correctness at all |
| Building web admin systems with AI assistance | Non-technical users looking for drag-and-drop builders |

Full workflow: [Developing Your Admin Panel with AI](./docs/guide/ai-workflow.md)

## Quick Start

```bash
composer require aimanong/framework
php artisan aimanong:install
```

> 📖 Full installation guide & your first Resource → [Quick Start](./docs/guide/quickstart.md)
>
> Current version: **framework 1.11.0** (core 1.6.3 · laravel 1.3.3 · thinkphp 1.4.3) | [Changelog](./CHANGELOG.md)

## Core Capabilities

| Capability | Description | Docs |
|---|---|---|
| Declaration-driven | One Resource → Schema / TS / OpenAPI / AI context; `--check` blocks drift | [Schema Compilation](./docs/guide/concepts.md) |
| CRUD admin panel | List / search / sort / paginate / create / update / delete — all derived from declarations | [Resource Reference](./docs/api/resources.md) |
| AI introspection | `/__ai/capabilities.json` + MCP Server + `ai:verify` | [AI Workflow](./docs/guide/ai-workflow.md) |
| 30 field types | text / email / select / tree / money / image / tags … | [Field Reference](./docs/api/fields.md) |
| Built-in foundation | Login logs / operation logs / data dictionaries / file uploads / profile / admin accounts — zero config | [Foundation](./docs/guide/foundation.md) |
| Tree / export / stepped forms | `$grid->tree()` / `$grid->export()` / `$form->step()` | [Advanced Features](./docs/guide/concepts.md) |
| Plugin system | Extend via Extension class; dependency checks + failure isolation | [Multi-Framework](./docs/guide/multi-framework.md) |
| Multi-app | Independent prefix / guard / user model per app; request-level isolation | [Multi-App](./docs/guide/multi-app.md) |
| Multi-framework | Laravel 12 + ThinkPHP 8 — same declarations, consistent behavior | [Multi-Framework](./docs/guide/multi-framework.md) |
| RBAC permissions | Button-level permissions; unauthorized buttons are not rendered server-side | [AI Collaboration](./docs/guide/ai-collaboration.md) |

## Documentation

| Audience | Link |
|---|---|
| 👤 Humans | [Documentation Site](https://zyzyzzy.github.io/aimanong/) |
| 🤖 AI Agents | [llms.txt](./llms.txt) · [AGENTS.md](./AGENTS.md) |
| 🎮 Try it | [Playground](https://zyzyzzy.github.io/aimanong/playground/) |
| 📊 API Reference | [Auto-generated from Schema](./docs/api/) — never drifts |
| 📝 Changelog | [CHANGELOG.md](./CHANGELOG.md) |

## Repository Structure

| Path | Description |
|---|---|
| `packages/core` | Framework-agnostic core (Grid / Form / Schema / Contracts) |
| `packages/laravel` | Laravel driver |
| `packages/thinkphp` | ThinkPHP driver |
| `packages/framework` | Composer meta-package |
| `packages/region` | Official extension: China province/city/district cascading selector |
| `docs/` | Documentation site + Playground |
| `tests/e2e/` | End-to-end tests |
| `scripts/` | Release checks, demo scaffolding |

> **No separate frontend package**: Vue 3 is bundled inline (zero-build). Pages are rendered from Schema automatically. No npm or bundler required.

## License

**Apache License 2.0** — free for commercial use, modification, distribution, and proprietary derivatives; includes an explicit patent grant.

| You can | You cannot |
|---|---|
| ✅ Use commercially, modify, distribute, close-source | ❌ Name your fork or product "Aimanong" |
| ✅ Use in internal company systems | ❌ Imply official endorsement |
| ✅ Receive explicit patent grants from contributors | ❌ Sue others over patents in this project (license terminates) |

Versions ≤ 1.10.1 remain under MIT permanently. Apache-2.0 applies to versions ≥ 1.11.0. Both are permissive licenses with equivalent practical freedom.

See [LICENSE](./LICENSE) · [Trademark Policy](./TRADEMARK.md) · [Commercial Terms](./COMMERCIAL.md)

> **Our commitment**: the framework itself is permanently free with no feature gating. Paid offerings are additive capabilities (enterprise plugins, hosting, support) — never locking away what's already free.
