<?php

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\ContactApiController;
use App\Http\Controllers\Api\LeadApiController;
use Illuminate\Support\Facades\Route;

// API pública v1 — Authorization: Bearer komo_live_…
// Scopes: leads:read, leads:write, contacts:read. La consume meta_ads
// para la atribución (GET /leads?ad_id=), los Lead Ads (POST /leads)
// y las Custom Audiences (GET /contacts?tag_id=).
Route::prefix('v1')->middleware('throttle:public-api')->group(function () {
    Route::get('/me', [ApiController::class, 'me'])->middleware('api.key');

    // Provisión del ecosistema (Komo Hub) — firma HMAC con secreto
    // maestro, no api.key (crea la cuenta que luego tendrá keys).
    Route::post('/provision', [\App\Http\Controllers\Api\ProvisionController::class, 'store']);

    Route::get('/leads', [LeadApiController::class, 'index'])
        ->middleware('api.key:leads:read');

    Route::post('/leads', [LeadApiController::class, 'store'])
        ->middleware('api.key:leads:write');

    // Invoice actualiza revenue REAL del lead cuando factura/cobra (Fase 4 F4-Invoice).
    Route::patch('/leads/{id}/revenue', [LeadApiController::class, 'updateRevenue'])
        ->middleware('api.key:leads:write');

    // Atribución multi-canal: aplica UTMs / click IDs a un lead existente
    // (first-touch: no pisa valores previos). Ver LeadApiController@updateTracking.
    Route::patch('/leads/{id}/tracking', [LeadApiController::class, 'updateTracking'])
        ->middleware('api.key:leads:write');

    Route::get('/contacts', [ContactApiController::class, 'index'])
        ->middleware('api.key:contacts:read');

    // Notificaciones consolidadas del Komo Hub (Fase 5).
    Route::get('/notifications', [\App\Http\Controllers\Api\NotificationApiController::class, 'index'])
        ->middleware('api.key:notifications:read');
});
