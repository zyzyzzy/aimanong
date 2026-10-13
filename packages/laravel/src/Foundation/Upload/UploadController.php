<?php

declare(strict_types=1);

namespace Aimanong\Foundation\Upload;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 上传接口与文件读取。
 *
 * ## 两条路由
 *
 * - `POST api/upload`      接收文件（multipart）
 * - `GET  uploads/{path}`  读取文件（仅在 disk 没有公开 URL 时使用）
 *
 * ## 安全约束
 *
 * 1. **双白名单**：扩展名 + MIME 都要在允许列表内。
 *    只看扩展名会被 `shell.php.jpg` 之类绕过（虽然本框架不做 PHP 解析，
 *    但用户可能把 storage 挂到可执行目录）。
 * 2. **文件名不受客户端控制**：见 Uploader。
 * 3. **路径穿越**：读取路由做 realpath 前缀校验，
 *    拒绝 `..` / 绝对路径 / 非本目录文件。
 */
class UploadController extends Controller
{
    public function __construct(protected Uploader $uploader) {}

    /**
     * 接收上传。
     *
     * 请求参数：
     *   file       必填，multipart 文件
     *   kind       image | file（默认 file）—— 决定白名单
     *   accept     可选，覆盖扩展名白名单（来自字段的 ->accept()）
     *   directory  可选，子目录（来自字段的 ->directory()）
     */
    public function store(Request $request): JsonResponse
    {
        if (! config('aimanong.foundation.upload.enable', true)) {
            abort(403, '文件上传已被配置关闭（aimanong.foundation.upload.enable）。');
        }

        /** @var mixed $kindInput */
        $kindInput = $request->input('kind', 'file');
        $kind = $kindInput === 'image' ? 'image' : 'file';

        /** @var mixed $acceptInput */
        $acceptInput = $request->input('accept');
        $accept = is_string($acceptInput) ? $acceptInput : null;

        /** @var mixed $dirInput */
        $dirInput = $request->input('directory');
        $directory = is_string($dirInput) && $dirInput !== '' ? $dirInput : null;

        $extensions = $this->uploader->extensionsFor($kind, $accept);

        if ($extensions === []) {
            abort(500, '上传白名单为空，请检查 config/aimanong.php 的 foundation.upload 配置。');
        }

        $maxKb = $this->uploader->maxSize($kind);

        try {
            $request->validate([
                'file' => [
                    'required',
                    'file',
                    'max:'.$maxKb,
                    // mimes 会校验真实 MIME，而不是只看扩展名
                    'mimes:'.implode(',', $extensions),
                ],
            ], [
                'file.required' => '请选择要上传的文件。',
                'file.max' => "文件过大，最多 {$maxKb} KB。",
                'file.mimes' => '不支持该文件类型。允许：'.implode('、', $extensions),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->validator->errors()->first('file'),
                'errors' => $e->errors(),
            ], 422);
        }

        /** @var mixed $uploaded */
        $uploaded = $request->file('file');

        if (! $uploaded instanceof UploadedFile) {
            return response()->json(['message' => '上传内容不是合法文件。'], 422);
        }

        return response()->json([
            'data' => $this->uploader->store($uploaded, $kind, $directory),
        ], 201);
    }

    /**
     * 读取已上传文件。
     *
     * 仅在 disk 没有公开 URL（如本地 public 盘且未做 storage:link）时使用。
     */
    public function show(Request $request, string $path): StreamedResponse
    {
        if (! config('aimanong.foundation.upload.enable', true)) {
            abort(404);
        }

        $safe = $this->uploader->pathFromValue($path);

        if ($safe === null) {
            abort(404);
        }

        $disk = Storage::disk($this->uploader->disk());

        if (! $disk->exists($safe)) {
            abort(404);
        }

        return $disk->response($safe);
    }
}
