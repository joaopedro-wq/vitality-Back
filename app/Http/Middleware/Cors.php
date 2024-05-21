<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class Cors
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return \Illuminate\Http\Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Certifique-se de que o $response é uma instância de \Illuminate\Http\Response
        if ($response instanceof Response) {
            $response->header('Access-Control-Allow-Origin', '*')
                     ->header('Access-Control-Allow-Methods', 'PUT, POST, DELETE, GET, OPTIONS')
                     ->header('Access-Control-Allow-Headers', 'Accept, Authorization, Content-Type');
        }

        return $response;
    }
}
