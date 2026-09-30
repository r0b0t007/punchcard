<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\Locale\ResolveLocale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function __construct(private readonly ResolveLocale $resolveLocale) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale($this->resolveLocale->handle($request));

        return $next($request);
    }
}
