<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json(['service' => 'RETD Tasks Management', 'status' => 'ok']);
});
