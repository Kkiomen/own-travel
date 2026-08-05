<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Presenter\DashboardPayload;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class DealController extends Controller
{
    public function __construct(private readonly DashboardPayload $board) {}

    public function index(Request $request): Response
    {
        return Inertia::render(
            'Dashboard',
            $this->board->for($request, (int) config('deals.dashboard_limit', 60)),
        );
    }
}
