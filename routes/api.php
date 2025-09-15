<?php

use App\Http\Controllers\ArticleController;
use App\Http\Controllers\AskController;
use App\Http\Controllers\LawSummaryController;
use Illuminate\Support\Facades\Route;

Route::post('/ask', AskController::class);

Route::get('/laws/{mopedId}/articles', [ArticleController::class, 'index']);

Route::get('/laws/{mopedId}/summary', [LawSummaryController::class, 'show']);
