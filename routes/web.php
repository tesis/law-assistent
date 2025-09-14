<?php

use App\Http\Controllers\ArticleController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/ask-demo', fn() => view('ask'));

Route::get('/laws/{mopedId}', [ArticleController::class, 'page'])->name('laws.show');

