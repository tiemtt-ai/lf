<?php

use App\Http\Controllers\LearningFrameworkController;
use Illuminate\Support\Facades\Route;

Route::get('/learning-frameworks', [LearningFrameworkController::class, 'index'])->name('learning-frameworks.index');
Route::get('/learning-frameworks/create', [LearningFrameworkController::class, 'create'])->name('learning-frameworks.create');
Route::post('/learning-frameworks', [LearningFrameworkController::class, 'store'])->name('learning-frameworks.store');
Route::get('/learning-frameworks/{framework}', [LearningFrameworkController::class, 'show'])->name('learning-frameworks.show');
Route::put('/learning-frameworks/{framework}', [LearningFrameworkController::class, 'update'])->name('learning-frameworks.update');
Route::post('/learning-frameworks/{framework}/versions', [LearningFrameworkController::class, 'storeVersion'])->name('learning-frameworks.versions.store');
Route::put('/learning-frameworks/{framework}/versions/{version}', [LearningFrameworkController::class, 'updateVersion'])->name('learning-frameworks.versions.update');
Route::post('/learning-frameworks/{framework}/versions/{version}/publish', [LearningFrameworkController::class, 'publishVersion'])->name('learning-frameworks.versions.publish');
Route::post('/learning-frameworks/{framework}/definitions', [LearningFrameworkController::class, 'storeDefinition'])->name('learning-frameworks.definitions.store');
Route::put('/learning-frameworks/{framework}/definitions/{definition}', [LearningFrameworkController::class, 'updateDefinition'])->name('learning-frameworks.definitions.update');
Route::post('/learning-frameworks/{framework}/versions/{version}/nodes', [LearningFrameworkController::class, 'storeNode'])->name('learning-frameworks.nodes.store');
Route::put('/learning-frameworks/{framework}/versions/{version}/nodes/{node}', [LearningFrameworkController::class, 'updateNode'])->name('learning-frameworks.nodes.update');
