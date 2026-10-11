/**
 * Playground 解析器验证。
 *
 * ## 为什么需要它
 *
 * Playground 是很多人接触框架的第一站，但它的 DSL 解析器
 * 长期落后于框架 —— 实测发现两类问题：
 *   1. 缺 4 种上传字段类型（image/images/file/files）
 *   2. **驼峰方法被静默跳过**：映射表键是小写 `multiselect`，
 *      而 PHP 方法名是 `multiSelect` —— 查表 miss 后 `continue`，
 *      字段凭空消失且不报错
 *
 * 用真实声明跑一遍，比"看起来改了"可靠。
 *
 * 用法：node tests/e2e/playground-check.mjs
 */
import { compile } from '../../docs/public/playground/compiler.js';

const src = `
public static function grid(Grid $grid): void
{
    $grid->column('status', '状态')->dict('order_status');
    $grid->column('amount', '金额')->money()->dangerWhen('>=', 1000000);
    $grid->column('file_path', '附件')->file();
}
public static function form(Form $form): void
{
    $form->select('status')->label('状态')->dict('order_status');
    $form->images('photos')->label('现场照片')->maxSize(5120);
    $form->files('docs')->label('附件')->accept('pdf,zip');
    $form->password('password')->requiredOnCreate()->omitWhenEmpty()->min(6);
    $form->multiSelect('roles')->relation('roles')->options([1 => '管理员']);
}
`;
const out = compile(src);
const errs = [];
const g = out.grid?.columns ?? [];
const f = out.form?.fields ?? [];

const chk = (name, cond, note='') => { console.log(`  ${cond ? '✅' : '❌'} ${name}${note ? '  ' + note : ''}`); if(!cond) errs.push(name); };

chk('列：dict 生效', g.find(c=>c.name==='status')?.props?.dict === 'order_status');
chk('列：条件高亮', g.find(c=>c.name==='amount')?.props?.highlight?.operator === '>=');
chk('列：file 展示器', g.find(c=>c.name==='file_path')?.formatter === 'file');
chk('字段：images 类型', f.find(x=>x.name==='photos')?.type === 'images', f.find(x=>x.name==='photos')?.type);
chk('字段：images json 类型', f.find(x=>x.name==='photos')?.jsonType === 'array');
chk('字段：maxSize', f.find(x=>x.name==='photos')?.props?.maxSize === 5120);
chk('字段：files + accept', f.find(x=>x.name==='docs')?.props?.accept === 'pdf,zip');
chk('字段：requiredOnCreate', f.find(x=>x.name==='password')?.props?.requiredOnCreate === true);
chk('字段：omitWhenEmpty', f.find(x=>x.name==='password')?.props?.omitWhenEmpty === true);
chk('字段：relation', f.find(x=>x.name==='roles')?.props?.relation === 'roles');
chk('字段：dict 生效', f.find(x=>x.name==='status')?.props?.dict === 'order_status');

console.log(errs.length ? `\n失败 ${errs.length} 项` : '\n全部通过');
process.exit(errs.length ? 1 : 0);
