<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit\Fixtures;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 真实 Eloquent 模型，挂在 sqlite 内存表 introspect_articles 上。
 *
 * 故意同时具备：传统访问器、Laravel 9+ 访问器、casts、关联 ——
 * 这四类都是「合法但不是真实列」的名字，幽灵列检测必须全部放行。
 *
 * @property string $title
 * @property string $slug
 */
class IntrospectArticle extends Model
{
    protected $table = 'introspect_articles';

    protected $guarded = [];

    public $timestamps = false;

    /** 传统访问器（getXxxAttribute） */
    public function getTitleUpperAttribute(): string
    {
        return strtoupper((string) $this->title);
    }

    /** Laravel 9+ 访问器（xxx(): Attribute）—— 由 casts 表覆盖，见 $casts */
    protected function slugged(): Attribute
    {
        return Attribute::make(
            get: fn (): string => 'slug-'.(string) $this->slug,
        );
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'published' => 'boolean',
        ];
    }

    /** @return HasMany<IntrospectComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(IntrospectComment::class, 'article_id');
    }
}
