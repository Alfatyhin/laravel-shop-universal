<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Storage;

class ShopMidleware
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

        if (!empty($request->query())) {
            $request->noindex = true;
        } else {
            $request->noindex = false;
        }

        $utm = [];
        if ($request->has('utm_referrer'))
            $utm['utm_referrer'] = $request->get('utm_referrer');

        if ($request->has('utm_content'))
            $utm['utm_content'] = $request->get('utm_content');

        if ($request->has('utm_source'))
            $utm['utm_source'] = $request->get('utm_source');

        if ($request->has('utm_medium'))
            $utm['utm_medium'] = $request->get('utm_medium');

        if ($request->has('utm_campaign'))
            $utm['utm_campaign'] = $request->get('utm_campaign');

        if (!empty($utm)) {
            session(['utm' => $utm]);
        }


        if (Storage::disk('local')->exists('data/banner.json')) {
            $banner = Storage::disk('local')->get('data/banner.json');
            $banner = json_decode($banner, true);
        } else {
            $banner = ['en' => '', 'ru' => '', 'he' => ''];
        }

        if(isset($banner['popapp'])) {
            if (!session('popapp')) {
                session()->put('popapp', 1);
            } else {
                unset($banner['popapp']);
            }
        }

        $request->banner = $banner;

        return $next($request);
    }
}
