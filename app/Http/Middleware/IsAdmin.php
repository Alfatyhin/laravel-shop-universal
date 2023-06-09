<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\StatisticService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class IsAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        /** @var User $user */

        $user = Auth::user();

        if (!$user || !$user->isAdmin()) {
            return redirect()->route('index');
        }


        StatisticService::addItem($request, 'crm', 'admin_midleware');

        return $next($request);
    }
}
