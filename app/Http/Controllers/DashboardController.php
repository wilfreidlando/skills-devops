<?php

namespace App\Http\Controllers;

use App\Jobs\PingWorker;
use App\Support\PlatformStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        // Chaque visite écrit une ligne : le compteur prouve que la base est
        // persistante (il survit aux déploiements et se retrouve dans les sauvegardes).
        try {
            DB::table('visits')->insert(['ip' => $request->ip(), 'created_at' => now()]);
        } catch (Throwable) {
            // la page reste utile même base en panne : le statut l'affiche
        }

        return view('dashboard', ['status' => PlatformStatus::collect($request)]);
    }

    public function status(Request $request): JsonResponse
    {
        return response()->json(PlatformStatus::collect($request));
    }

    public function ping(): RedirectResponse
    {
        PingWorker::dispatch();

        return redirect()->route('dashboard')->with('pinged', true);
    }
}
