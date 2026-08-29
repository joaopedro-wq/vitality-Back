<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    /*
     | Uma origin só não basta desde que o app nativo entrou em cena: o WebView
     | do Capacitor nunca se apresenta como o domínio do site. No Android o
     | esquema padrão é `https` (confirmado no emulador: o log do WebView serve
     | `https://localhost/...`), e `http://localhost` fica como rede de segurança
     | para quem mudar `androidScheme`; no iOS a origin é `capacitor://localhost`.
     | As três são fixas — fazem parte do runtime, não do deploy. O resto vem de
     | FRONTEND_ORIGINS (lista separada por vírgula) com FRONTEND_URL preservado
     | como fallback para os ambientes que ainda só definem essa variável.
     */
    'allowed_origins' => array_values(array_unique(array_filter(array_merge(
        ['https://localhost', 'http://localhost', 'capacitor://localhost'],
        array_map('trim', explode(',', (string) env('FRONTEND_ORIGINS', ''))),
        [env('FRONTEND_URL', 'http://localhost:3000')],
    )))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
