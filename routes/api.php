<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ServerController;
use App\Http\Controllers\Api\DeploymentController;

Route::middleware(['auth:sanctum','api.tenant','throttle:api'])->prefix('v1')->group(function():void{
    Route::get('/servers',[ServerController::class,'index']);
    Route::get('/deployments',[DeploymentController::class,'index']);
});
