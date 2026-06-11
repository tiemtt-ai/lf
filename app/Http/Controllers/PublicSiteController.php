<?php

namespace App\Http\Controllers;

class PublicSiteController extends Controller
{
    public function home()
    {
        return view('pages.home');
    }

    public function features()
    {
        return view('pages.features');
    }

    public function pricing()
    {
        return view('pages.pricing');
    }

    public function services()
    {
        return view('pages.services');
    }

    public function about()
    {
        return view('pages.about');
    }
}
