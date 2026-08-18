<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    private const SUPPORTED = ['pt-BR', 'en-US'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->fromHeader($request->header('Accept-Language')) ?? 'pt-BR';
        app()->setLocale($locale);
        $response = $next($request);
        $response->headers->set('Content-Language', $locale);

        return $response;
    }

    private function fromHeader(?string $header): ?string
    {
        if (! $header) {
            return null;
        }
        foreach (preg_split('/,\s*/', $header) ?: [] as $value) {
            $language = strtolower(trim(explode(';', $value)[0]));
            if (in_array($language, ['pt-br', 'pt'], true)) {
                return 'pt-BR';
            }
            if (in_array($language, ['en-us', 'en'], true)) {
                return 'en-US';
            }
        }

        return null;
    }
}
