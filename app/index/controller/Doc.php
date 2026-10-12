<?php

namespace app\index\controller;

class Doc
{
    public function openapi()
    {
        // 假设 openapi.yaml 放在 public 目录下
        $filePath = root_path() . 'public/openapi.yaml';

        if (!file_exists($filePath)) {
            return response('File not found', 404);
        }

        $content = file_get_contents($filePath);

        // 关键：设置 Content-Type 为 text/yaml 或 text/plain
        // 关键：设置 Content-Disposition 为 inline，告诉浏览器不要下载
        return response($content, 200, [
            'Content-Type' => 'text/yaml; charset=utf-8',
            'Content-Disposition' => 'inline; filename="openapi.yaml"'
        ]);
    }
}