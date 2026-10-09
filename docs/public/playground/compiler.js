/**
 * Aimanong Playground —— Resource 声明解析器
 *
 * 在浏览器里解析 PHP DSL，产出四份产物预览。
 *
 * 设计说明：这是**真实解析**，不是硬编码演示。
 * 用户改动左侧代码，右侧产物会按规则重新生成。
 *
 * 解析范围限定为框架的核心 DSL（链式调用），
 * 与后端 Compiler 的产出结构保持一致。
 */

/** 字段类型 → JSON 类型映射（与后端 FieldType 保持一致） */
export const FIELD_TYPE_MAP = {
  text: { json: 'string', php: 'string' },
  textarea: { json: 'string', php: 'string' },
  email: { json: 'string', php: 'string' },
  url: { json: 'string', php: 'string' },
  password: { json: 'string', php: 'string' },
  tel: { json: 'string', php: 'string' },
  number: { json: 'integer', php: 'int' },
  decimal: { json: 'number', php: 'float' },
  money: { json: 'number', php: 'float' },
  rate: { json: 'integer', php: 'int' },
  slider: { json: 'integer', php: 'int' },
  select: { json: 'string', php: 'string' },
  multiselect: { json: 'array', php: 'array' },
  radio: { json: 'string', php: 'string' },
  checkbox: { json: 'array', php: 'array' },
  switch: { json: 'boolean', php: 'bool' },
  date: { json: 'string', php: 'string' },
  datetime: { json: 'string', php: 'string' },
  time: { json: 'string', php: 'string' },
  daterange: { json: 'array', php: 'array' },
  color: { json: 'string', php: 'string' },
  icon: { json: 'string', php: 'string' },
  tags: { json: 'array', php: 'array' },
  hidden: { json: 'string', php: 'string' },
  display: { json: 'string', php: 'mixed' },
  divider: { json: 'null', php: 'mixed' }
}

/** 表单方法与字段类型的对应（方法名大小写不敏感） */
const FORM_METHOD_TO_TYPE = {
  text: 'text',
  textarea: 'textarea',
  number: 'number',
  decimal: 'decimal',
  money: 'money',
  rate: 'rate',
  slider: 'slider',
  select: 'select',
  multiselect: 'multiselect',
  radio: 'radio',
  checkbox: 'checkbox',
  switch: 'switch',
  date: 'date',
  datetime: 'datetime',
  time: 'time',
  daterange: 'daterange',
  email: 'email',
  url: 'url',
  password: 'password',
  tel: 'tel',
  color: 'color',
  icon: 'icon',
  tags: 'tags',
  hidden: 'hidden',
  display: 'display',
  divider: 'divider'
}

/** 列展示器方法 → formatter 标识 */
const DISPLAYER_MAP = {
  dateTime: 'datetime',
  bool: 'bool',
  badge: 'badge',
  money: 'money',
  image: 'image',
  link: 'link',
  progress: 'progress',
  map: 'map',
  using: 'enum'
}

/**
 * 从 PHP 源码中提取方法体。
 * 只做括号配对，不做完整 PHP 解析 —— Playground 场景够用。
 */
function extractMethodBody (source, method) {
  const re = new RegExp(`function\\s+${method}\\s*\\([^)]*\\)\\s*:\\s*void\\s*\\{`, 'i')
  const m = source.match(re)
  if (!m) return null

  let i = m.index + m[0].length
  let depth = 1
  const start = i

  while (i < source.length && depth > 0) {
    const ch = source[i]
    if (ch === '{') depth++
    else if (ch === '}') depth--
    i++
  }

  return source.slice(start, i - 1)
}

/** 把一个 PHP 数组字面量解析成 JS 对象/数组 */
function parsePhpArray (raw) {
  const text = raw.trim()
  if (!text.startsWith('[')) return null

  const out = {}
  let isList = true
  let autoIndex = 0

  // 去掉最外层 []
  const inner = text.slice(1, -1)
  let depth = 0
  let buf = ''
  const parts = []

  for (let i = 0; i < inner.length; i++) {
    const ch = inner[i]
    if (ch === '[' || ch === '(') depth++
    if (ch === ']' || ch === ')') depth--
    if (ch === ',' && depth === 0) {
      parts.push(buf)
      buf = ''
      continue
    }
    buf += ch
  }
  if (buf.trim()) parts.push(buf)

  for (const part of parts) {
    const seg = part.trim()
    if (!seg) continue

    // 找顶层的 =>
    let d = 0
    let arrowAt = -1
    let inStr = false
    let quote = ''
    for (let i = 0; i < seg.length; i++) {
      const ch = seg[i]
      if (inStr) {
        if (ch === quote && seg[i - 1] !== '\\') inStr = false
        continue
      }
      if (ch === "'" || ch === '"') { inStr = true; quote = ch; continue }
      if (ch === '[' || ch === '(') d++
      if (ch === ']' || ch === ')') d--
      if (d === 0 && ch === '=' && seg[i + 1] === '>') { arrowAt = i; break }
    }

    if (arrowAt > -1) {
      isList = false
      const k = unquote(seg.slice(0, arrowAt).trim())
      const v = seg.slice(arrowAt + 2).trim()
      out[k] = parseValue(v)
    } else {
      out[autoIndex++] = parseValue(seg)
    }
  }

  return isList ? Object.values(out) : out
}

function unquote (s) {
  const t = s.trim()
  if ((t.startsWith("'") && t.endsWith("'")) || (t.startsWith('"') && t.endsWith('"'))) {
    return t.slice(1, -1)
  }
  return t
}

function parseValue (v) {
  const t = v.trim()
  if (t.startsWith('[')) return parsePhpArray(t)
  if (t === 'true') return true
  if (t === 'false') return false
  if (t === 'null') return null
  if (/^-?\d+(\.\d+)?$/.test(t)) return Number(t)
  return unquote(t)
}

/** 解析链式调用：->label('x')->required()->max(255) */
function parseChain (chainText) {
  const props = {}
  const rules = []
  let required = false
  let defaultVal = null
  let readonly = false
  let hidden = false
  let formatter = null
  let sortable = false
  let searchable = false
  let filterable = false

  // 逐个方法匹配
  const re = /->\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\(([^)]*)\)/g
  let m

  while ((m = re.exec(chainText)) !== null) {
    const name = m[1]
    const rawArgs = m[2].trim()

    switch (name) {
      case 'label': props.label = unquote(rawArgs); break
      case 'required':
        required = rawArgs === '' || rawArgs === 'true'
        if (required) rules.push('required')
        break
      case 'default': defaultVal = parseValue(rawArgs); break
      case 'readonly': readonly = true; break
      case 'hidden': hidden = true; break
      case 'sortable': sortable = rawArgs !== 'false'; break
      case 'searchable': searchable = rawArgs !== 'false'; break
      case 'filter': filterable = rawArgs !== 'false'; break
      case 'max': rules.push(`max:${rawArgs}`); break
      case 'min': rules.push(`min:${rawArgs}`); break
      case 'rules': {
        const r = parseValue(rawArgs)
        if (Array.isArray(r)) rules.push(...r)
        else if (typeof r === 'string') rules.push(r)
        break
      }
      case 'options': {
        const opts = parsePhpArray(rawArgs)
        if (opts) {
          props.options = Array.isArray(opts)
            ? opts.map((v, i) => ({ value: String(i), label: String(v) }))
            : Object.entries(opts).map(([k, v]) => ({ value: String(k), label: String(v) }))
        }
        break
      }
      case 'rows': props.rows = Number(rawArgs); break
      case 'placeholder': props.placeholder = unquote(rawArgs); break
      case 'help': props.help = unquote(rawArgs); break
      case 'decimals': props.decimals = Number(rawArgs); break
      case 'symbol': props.symbol = unquote(rawArgs); break
      case 'width': props.width = Number(rawArgs); break
      case 'multiple': props.multiple = true; break
      case 'format': props.format = unquote(rawArgs); break
      // 列展示器
      case 'dateTime': formatter = 'datetime'; props.format = unquote(rawArgs) || 'Y-m-d H:i:s'; break
      case 'bool': {
        formatter = 'bool'
        const parts = splitArgs(rawArgs)
        props.trueLabel = parts[0] ? unquote(parts[0]) : '是'
        props.falseLabel = parts[1] ? unquote(parts[1]) : '否'
        break
      }
      case 'badge': formatter = 'badge'; break
      case 'money': formatter = 'money'; props.symbol = unquote(rawArgs) || '¥'; break
      case 'image': formatter = 'image'; props.height = Number(rawArgs) || 32; break
      case 'link': formatter = 'link'; if (rawArgs) props.text = unquote(rawArgs); break
      case 'progress': formatter = 'progress'; break
      case 'map': {
        formatter = 'map'
        const mp = parsePhpArray(rawArgs)
        if (mp) props.map = mp
        break
      }
      case 'using': formatter = 'enum'; props.enum = rawArgs; break
      case 'perPage': props.perPage = Number(rawArgs); break
      case 'using': break
      default: break
    }
  }

  return { props, rules, required, default: defaultVal, readonly, hidden, formatter, sortable, searchable, filterable }
}

function splitArgs (raw) {
  const out = []
  let depth = 0
  let buf = ''
  let inStr = false
  let quote = ''

  for (let i = 0; i < raw.length; i++) {
    const ch = raw[i]
    if (inStr) {
      buf += ch
      if (ch === quote && raw[i - 1] !== '\\') inStr = false
      continue
    }
    if (ch === "'" || ch === '"') { inStr = true; quote = ch; buf += ch; continue }
    if (ch === '[' || ch === '(') depth++
    if (ch === ']' || ch === ')') depth--
    if (ch === ',' && depth === 0) { out.push(buf.trim()); buf = ''; continue }
    buf += ch
  }
  if (buf.trim()) out.push(buf.trim())

  return out
}

/** 解析 grid() 方法体 → 列定义 */
export function parseGrid (body) {
  if (!body) return { columns: [], perPage: 20 }

  const columns = []
  let perPage = 20

  const perPageMatch = body.match(/->\s*perPage\s*\(\s*(\d+)\s*\)/)
  if (perPageMatch) perPage = Number(perPageMatch[1])

  // 匹配 $grid->column('name', '标签')<链式>
  const re = /\$grid\s*->\s*column\s*\(\s*(['"])(.*?)\1\s*(?:,\s*(['"])(.*?)\3\s*)?\)((?:\s*->\s*[a-zA-Z_][a-zA-Z0-9_]*\s*\([^)]*\))*)/g
  let m

  while ((m = re.exec(body)) !== null) {
    const name = m[2]
    const label = m[4] || name
    const chain = parseChain(m[5] || '')

    columns.push({
      name,
      label: chain.props.label || label,
      sortable: chain.sortable,
      searchable: chain.searchable,
      filterable: chain.filterable,
      formatter: chain.formatter,
      enum: chain.props.enum || null,
      props: chain.props
    })
  }

  return { columns, perPage }
}

/** 解析 form() 方法体 → 字段定义 */
export function parseForm (body) {
  if (!body) return { fields: [], rules: {} }

  const fields = []
  const rules = {}

  // 匹配 $form->method('name', '标签')<链式>
  const re = /\$form\s*->\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\(\s*(['"])(.*?)\2\s*(?:,\s*(['"])(.*?)\4\s*)?\)((?:\s*->\s*[a-zA-Z_][a-zA-Z0-9_]*\s*\([^)]*\))*)/g
  let m

  while ((m = re.exec(body)) !== null) {
    const method = m[1]
    const name = m[3]
    const label = m[5] || name

    const type = FORM_METHOD_TO_TYPE[method]
    if (!type) continue // 非字段方法（如 divider 的变体）忽略

    // divider 是纯展示，特殊处理
    if (type === 'divider') {
      fields.push({
        name: `_divider_${fields.length}`,
        type: 'divider',
        label: m[3],
        jsonType: 'null',
        required: false,
        readonly: false,
        hidden: true,
        default: null,
        rules: [],
        props: {}
      })
      continue
    }

    const chain = parseChain(m[6] || '')

    const field = {
      name,
      type,
      label: chain.props.label || label,
      jsonType: FIELD_TYPE_MAP[type]?.json || 'string',
      required: chain.required,
      readonly: chain.readonly,
      hidden: chain.hidden,
      default: chain.default,
      rules: chain.rules,
      props: { ...chain.props }
    }
    delete field.props.label

    fields.push(field)
    if (chain.rules.length) rules[name] = chain.rules
  }

  return { fields, rules }
}

/** 从源码提取 uri / label / model */
export function parseMeta (source) {
  const uri = source.match(/function\s+uri\s*\([^)]*\)\s*:\s*string\s*\{\s*return\s+(['"])(.*?)\1/s)
  const label = source.match(/function\s+label\s*\([^)]*\)\s*:\s*string\s*\{\s*return\s+(['"])(.*?)\1/s)
  const model = source.match(/function\s+model\s*\([^)]*\)\s*:\s*string\s*\{\s*return\s+([A-Za-z_\\][A-Za-z0-9_\\]*)/s)

  return {
    uri: uri ? uri[2] : 'items',
    label: label ? label[2] : '示例',
    model: model ? model[1] : 'App\\Models\\Item'
  }
}

/** 主入口：解析完整 Resource 源码 → 四份产物 */
export function compile (source) {
  const meta = parseMeta(source)
  const grid = parseGrid(extractMethodBody(source, 'grid'))
  const form = parseForm(extractMethodBody(source, 'form'))

  const jsonSchema = {
    $schema: 'https://aimanong.com/schema/v1.json',
    uri: meta.uri,
    label: meta.label,
    model: meta.model,
    grid: {
      perPage: grid.perPage,
      columns: grid.columns.map(c => ({
        name: c.name,
        label: c.label,
        sortable: c.sortable,
        searchable: c.searchable,
        filterable: c.filterable,
        formatter: c.formatter,
        enum: c.enum,
        props: c.props
      }))
    },
    form: { fields: form.fields, rules: form.rules },
    show: { fields: [] },
    generatedAt: null
  }

  const typescript = emitTypeScript(meta, form)
  const openapi = emitOpenApi(meta, grid, form)
  const aiContext = emitAiContext(meta, grid, form)

  return { jsonSchema, typescript, openapi, aiContext, meta, grid, form }
}

function pascal (s) {
  return s.replace(/(^|[-_])(\w)/g, (_, __, c) => c.toUpperCase()).replace(/[-_]/g, '')
}

function emitTypeScript (meta, form) {
  const name = pascal(meta.uri)
  const lines = [
    '// 自动生成，请勿手工编辑。',
    '// 来源: Aimanong Schema 编译层',
    '',
    `export interface ${name} {`
  ]

  for (const f of form.fields) {
    if (f.type === 'divider') continue
    const php = FIELD_TYPE_MAP[f.type]?.php || 'string'
    const ts = php === 'int' || php === 'float' ? 'number' : php === 'bool' ? 'boolean' : php === 'mixed' ? 'unknown' : php === 'array' ? 'string[]' : 'string'
    lines.push(`  ${f.name}${f.required ? '' : '?'}: ${ts};`)
  }

  lines.push('}')
  lines.push('')
  lines.push(`export interface ${name}Schema {`)
  lines.push(`  uri: '${meta.uri}';`)
  lines.push(`  label: '${meta.label}';`)
  lines.push('  grid: AimanongGridSchema;')
  lines.push('  form: AimanongFormSchema;')
  lines.push('}')

  return lines.join('\n')
}

function emitOpenApi (meta, grid, form) {
  const sortable = grid.columns.filter(c => c.sortable).map(c => c.name)
  const props = {}
  const req = []

  for (const f of form.fields) {
    if (f.type === 'divider') continue
    props[f.name] = { type: f.jsonType }
    if (f.props.options) props[f.name].enum = f.props.options.map(o => o.value)
    if (f.type === 'email') props[f.name].format = 'email'
    if (f.type === 'url') props[f.name].format = 'uri'
    if (f.required) req.push(f.name)
  }

  return JSON.stringify({
    openapi: '3.1.0',
    info: { title: `${meta.label} API`, version: '0.1.0' },
    paths: {
      [`/admin/${meta.uri}`]: {
        get: {
          summary: `${meta.label}列表`,
          parameters: [
            { name: 'page', in: 'query', schema: { type: 'integer' } },
            { name: 'per_page', in: 'query', schema: { type: 'integer' } },
            { name: 'keyword', in: 'query', schema: { type: 'string' } },
            { name: 'sort', in: 'query', schema: { type: 'string', enum: sortable } },
            { name: 'direction', in: 'query', schema: { type: 'string', enum: ['asc', 'desc'] } }
          ]
        },
        post: { summary: `创建${meta.label}` }
      },
      [`/admin/${meta.uri}/{id}`]: {
        get: { summary: `${meta.label}详情` },
        put: { summary: `更新${meta.label}` },
        delete: { summary: `删除${meta.label}` }
      }
    },
    components: {
      schemas: {
        [pascal(meta.uri)]: { type: 'object', properties: props, required: req }
      }
    }
  }, null, 2)
}

function emitAiContext (meta, grid, form) {
  const lines = [`## Resource: ${meta.label} (uri: ${meta.uri})`, '']
  lines.push(`- 绑定模型: \`${meta.model}\``)
  lines.push('')
  lines.push('### 列表页可用列')

  if (grid.columns.length === 0) lines.push('（未定义列）')
  for (const c of grid.columns) {
    const flags = []
    if (c.sortable) flags.push('可排序')
    if (c.searchable) flags.push('可搜索')
    if (c.filterable) flags.push('可筛选')
    lines.push(`- \`${c.name}\`（${c.label}）${flags.length ? '[' + flags.join(' / ') + ']' : ''}`)
  }

  lines.push('')
  lines.push('### 表单字段')

  const real = form.fields.filter(f => f.type !== 'divider')
  if (real.length === 0) lines.push('（未定义字段）')

  for (const f of real) {
    const parts = []
    if (f.required) parts.push('必填')
    if (f.readonly) parts.push('只读')
    if (f.hidden) parts.push('隐藏')
    if (f.default !== null) parts.push('默认 ' + JSON.stringify(f.default))
    if (f.props.options) parts.push('可选值: ' + f.props.options.map(o => o.value).join(' | '))
    lines.push(`- \`${f.name}\` 类型 \`${f.type}\`（${f.label}）${parts.length ? '— ' + parts.join('；') : ''}`)
  }

  lines.push('')
  lines.push('### 校验规则（JSON）')
  lines.push('```json')
  lines.push(JSON.stringify(form.rules, null, 4))
  lines.push('```')

  return lines.join('\n')
}
