<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class BuysController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('buys/Index');
    }

    public function items(): Response
    {
        return Inertia::render('buys/Items');
    }
}
