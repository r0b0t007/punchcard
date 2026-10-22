<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Admin\StopImpersonation;
use App\Http\Middleware\WhileImpersonating;
use App\Support\Auth\Impersonation;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** "Back to admin" while signed in as an owner (CHW-144): the admin's own session again, on the business's page. */
final class ImpersonationController extends Controller
{
    public function stop(Request $request, StopImpersonation $stopImpersonation): Response
    {
        $impersonation = $stopImpersonation->handle($request->session(), StopImpersonation::BACK);

        return $impersonation instanceof Impersonation
            ? WhileImpersonating::backToAdmin($request, $impersonation->businessId)
            : to_route('start');
    }
}
