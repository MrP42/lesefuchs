<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * PHP-View-Renderer mit Layout-Unterstützung.
 * Views liegen in app/Views/<name>.php. Ausgaben mit e() escapen.
 */
final class View
{
    public static string $defaultLayout = 'layouts/app';

    public static function render(string $view, array $data = [], ?string $layout = 'layouts/app', int $status = 200): Response
    {
        return Response::html(self::make($view, $data, $layout), $status);
    }

    public static function make(string $view, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $content = self::partial($view, $data);
        if ($layout !== null && $layout !== '') {
            return self::partial($layout, array_merge($data, ['content' => $content]));
        }
        return $content;
    }

    public static function partial(string $view, array $data = []): string
    {
        $file = base_path('app/Views/' . $view . '.php');
        if (!is_file($file)) {
            throw new RuntimeException("View nicht gefunden: {$view} ({$file})");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        include $file;
        return (string) ob_get_clean();
    }
}
