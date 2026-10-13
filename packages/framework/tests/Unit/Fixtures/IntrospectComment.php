<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 关联目标模型（幽灵列检测要用到 belongsTo/hasMany 的判定）。
 *
 * @property int $article_id
 */
class IntrospectComment extends Model
{
    protected $table = 'introspect_comments';

    protected $guarded = [];

    public $timestamps = false;

    /** @return BelongsTo<IntrospectArticle, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(IntrospectArticle::class, 'article_id');
    }
}
