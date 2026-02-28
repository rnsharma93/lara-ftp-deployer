<?php
use Illuminate\Support\Facades\Route;
use Ram\Deployer\Http\Controllers\DeployController;

Route::post('/deployer/run', [DeployController::class, 'run']);
Route::get('/deployer/metadata', [DeployController::class, 'getMetadata']);
