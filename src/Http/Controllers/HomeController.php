<?php

namespace Redberry\Synapse\Http\Controllers;

use Illuminate\Contracts\View\View;

class HomeController
{
    /**
     * Serve the single-page application shell. React Router owns the rest.
     */
    public function __invoke(): View
    {
        return view('synapse::layout');
    }
}
