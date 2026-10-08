<?php

declare(strict_types=1);

namespace Aimanong\Http\Controllers;

use Aimanong\Aimanong;
use Illuminate\Routing\Controller;

class HomeController extends Controller
{
    public function index(): \Illuminate\View\View
    {
        return view('aimanong::index', [
            'user' => Aimanong::user(),
            'resourceCount' => Aimanong::registry()->count(),
        ]);
    }
}
