<?php

namespace app\mall\controller;

use support\Request;
use support\Response;

class HomeController
{
    public function index(Request $request): Response
    {
        return view(
            'home/index',
            [
                'now_time' => date('Y-m-d H:i:s')
            ]
        );
    }

    public function monitor(): Response
    {
        return view('import/monitor');
    }
}
