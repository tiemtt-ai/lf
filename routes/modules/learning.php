<?php

use App\Http\Controllers\LearningFrameworkController;
use Illuminate\Support\Facades\Route;

Route::get('/learning-frameworks', [LearningFrameworkController::class, 'index'])->name('learning-frameworks.index');
Route::get('/learning-frameworks/create', [LearningFrameworkController::class, 'create'])->name('learning-frameworks.create');
Route::post('/learning-frameworks', [LearningFrameworkController::class, 'store'])->name('learning-frameworks.store');
Route::delete('/learning-frameworks', [LearningFrameworkController::class, 'bulkDestroy'])->name('learning-frameworks.bulk-destroy');
Route::get('/learning-frameworks/{framework}', [LearningFrameworkController::class, 'show'])->name('learning-frameworks.show');
Route::put('/learning-frameworks/{framework}', [LearningFrameworkController::class, 'update'])->name('learning-frameworks.update');
Route::get('/learning-frameworks/{framework}/form', [LearningFrameworkController::class, 'form'])->name('learning-frameworks.form');
Route::get('/learning-frameworks/{framework}/scale/form', [LearningFrameworkController::class, 'scaleForm'])->name('learning-frameworks.scale.form');
Route::get('/learning-frameworks/{framework}/competencies/create', [LearningFrameworkController::class, 'competencyForm'])->name('learning-frameworks.competencies.create');
Route::get('/learning-frameworks/{framework}/definitions/{definition}/form', [LearningFrameworkController::class, 'definitionForm'])->name('learning-frameworks.definitions.form');
Route::post('/learning-frameworks/{framework}/versions', [LearningFrameworkController::class, 'storeVersion'])->name('learning-frameworks.versions.store');
Route::put('/learning-frameworks/{framework}/versions/{version}', [LearningFrameworkController::class, 'updateVersion'])->name('learning-frameworks.versions.update');
Route::post('/learning-frameworks/{framework}/versions/{version}/publish', [LearningFrameworkController::class, 'publishVersion'])->name('learning-frameworks.versions.publish');
Route::post('/learning-frameworks/{framework}/definitions', [LearningFrameworkController::class, 'storeDefinition'])->name('learning-frameworks.definitions.store');
Route::put('/learning-frameworks/{framework}/definitions/{definition}', [LearningFrameworkController::class, 'updateDefinition'])->name('learning-frameworks.definitions.update');
Route::post('/learning-frameworks/{framework}/versions/{version}/nodes', [LearningFrameworkController::class, 'storeNode'])->name('learning-frameworks.nodes.store');
Route::put('/learning-frameworks/{framework}/versions/{version}/nodes/{node}', [LearningFrameworkController::class, 'updateNode'])->name('learning-frameworks.nodes.update');
Route::post('/learning-frameworks/{framework}/competencies', [LearningFrameworkController::class, 'storeCompetency'])->name('learning-frameworks.competencies.store');
Route::post('/learning-frameworks/{framework}/competencies/{definition}/retire', [LearningFrameworkController::class, 'retireCompetency'])->name('learning-frameworks.competencies.retire');
Route::delete('/learning-frameworks/{framework}/versions/{version}', [LearningFrameworkController::class, 'destroyVersion'])->name('learning-frameworks.versions.destroy');
