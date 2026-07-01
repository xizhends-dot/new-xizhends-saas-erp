<?php

declare(strict_types=1);

namespace Xizhen\Core;

final class View
{
    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = [], string $layout = 'layouts/tenant'): void
    {
        $content = $this->capture($template, $data);
        echo $this->capture($layout, array_merge($data, ['content' => $content]));
    }

    /** @param array<string, mixed> $data */
    public function partial(string $template, array $data = []): string
    {
        return $this->capture($template, $data);
    }

    /** @param array<string, mixed> $data */
    private function capture(string $template, array $data): string
    {
        $file = $this->basePath . '/' . trim($template, '/') . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("模板不存在：{$template}");
        }

        extract($data, EXTR_SKIP);
        ob_start();
        require $file;
        return (string) ob_get_clean();
    }
}
