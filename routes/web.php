<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\WacrmWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('login'));

// Receptor de eventos del wacrm — público, sin CSRF (excluido en
// bootstrap/app.php), autenticado por firma HMAC.
Route::post('/webhooks/wacrm/{accountId}', [WacrmWebhookController::class, 'receive'])
    ->name('webhooks.wacrm');

// Aceptación pública de invitaciones al equipo.
Route::get('/invite/{token}', [\App\Http\Controllers\TeamController::class, 'acceptForm'])->name('invitations.accept');
Route::post('/invite/{token}', [\App\Http\Controllers\TeamController::class, 'redeem'])->name('invitations.redeem');

// Formularios web públicos (crean leads). Con throttle anti-abuso.
Route::get('/f/{token}', [\App\Http\Controllers\WebFormController::class, 'show'])->name('webforms.show');
Route::post('/f/{token}', [\App\Http\Controllers\WebFormController::class, 'submit'])
    ->middleware('throttle:web-form')->name('webforms.submit');

// Snippet embebible para landings externas — captura UTMs / click IDs
// en localStorage (first-touch) y auto-inyecta hidden inputs a forms con
// [data-komo-track]. Público, CDN-friendly. Ver TrackController.
Route::get('/track.js', [\App\Http\Controllers\TrackController::class, 'snippet'])->name('track.js');

Route::get('/dashboard', [\App\Http\Controllers\DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Leads (Kanban + ficha)
    Route::get('/leads', [\App\Http\Controllers\LeadController::class, 'index'])->name('leads.index');
    Route::post('/leads', [\App\Http\Controllers\LeadController::class, 'store'])->name('leads.store');
    Route::get('/leads/{lead}', [\App\Http\Controllers\LeadController::class, 'show'])->name('leads.show');
    Route::patch('/leads/{lead}', [\App\Http\Controllers\LeadController::class, 'update'])->name('leads.update');
    Route::patch('/leads/{lead}/move', [\App\Http\Controllers\LeadController::class, 'move'])->name('leads.move');
    Route::delete('/leads/{lead}', [\App\Http\Controllers\LeadController::class, 'destroy'])->name('leads.destroy');
    Route::post('/leads/{lead}/notes', [\App\Http\Controllers\LeadController::class, 'addNote'])->name('leads.notes.add');
    Route::patch('/leads/{lead}/tags', [\App\Http\Controllers\LeadController::class, 'syncTags'])->name('leads.tags');

    // Etiquetas
    Route::post('/tags', [\App\Http\Controllers\TagController::class, 'store'])->name('tags.store');
    Route::delete('/tags/{tag}', [\App\Http\Controllers\TagController::class, 'destroy'])->name('tags.destroy');
    Route::post('/leads/{lead}/whatsapp', [\App\Http\Controllers\LeadController::class, 'sendWhatsapp'])->name('leads.whatsapp');
    Route::post('/leads/{lead}/whatsapp-media', [\App\Http\Controllers\LeadController::class, 'sendMedia'])->name('leads.whatsapp-media');
    Route::post('/leads/{lead}/quote', [\App\Http\Controllers\LeadController::class, 'createQuote'])->name('leads.quote');
    Route::patch('/leads/{lead}/ai-mode', [\App\Http\Controllers\LeadController::class, 'setAiMode'])->name('leads.ai-mode');
    Route::get('/leads-quick-replies', [\App\Http\Controllers\LeadController::class, 'quickReplies'])->name('leads.quick-replies');
    Route::get('/leads/media/{mediaId}', [\App\Http\Controllers\LeadController::class, 'media'])->name('leads.media');

    // Tareas
    Route::get('/tasks', [\App\Http\Controllers\TaskController::class, 'index'])->name('tasks.index');
    Route::post('/tasks', [\App\Http\Controllers\TaskController::class, 'store'])->name('tasks.store');
    Route::post('/tasks/{task}/complete', [\App\Http\Controllers\TaskController::class, 'complete'])->name('tasks.complete');
    Route::delete('/tasks/{task}', [\App\Http\Controllers\TaskController::class, 'destroy'])->name('tasks.destroy');

    // Contactos y empresas
    Route::get('/contacts', [\App\Http\Controllers\ContactController::class, 'index'])->name('contacts.index');
    Route::get('/contacts/{contact}/timeline', [\App\Http\Controllers\ContactController::class, 'show'])->name('contacts.timeline');
    Route::post('/contacts', [\App\Http\Controllers\ContactController::class, 'store'])->name('contacts.store');
    Route::post('/contacts/import-wacrm', [\App\Http\Controllers\ContactController::class, 'importFromWacrm'])->name('contacts.import-wacrm');
    Route::patch('/contacts/{contact}', [\App\Http\Controllers\ContactController::class, 'update'])->name('contacts.update');
    Route::delete('/contacts/{contact}', [\App\Http\Controllers\ContactController::class, 'destroy'])->name('contacts.destroy');
    Route::get('/companies', [\App\Http\Controllers\CompanyController::class, 'index'])->name('companies.index');
    Route::post('/companies', [\App\Http\Controllers\CompanyController::class, 'store'])->name('companies.store');
    Route::patch('/companies/{company}', [\App\Http\Controllers\CompanyController::class, 'update'])->name('companies.update');
    Route::delete('/companies/{company}', [\App\Http\Controllers\CompanyController::class, 'destroy'])->name('companies.destroy');

    // Digital Pipeline (automatizaciones por etapa)
    Route::get('/pipelines/{pipeline}/automations', [\App\Http\Controllers\StageAutomationController::class, 'index'])->name('pipelines.automations');
    Route::post('/pipelines/{pipeline}/automations', [\App\Http\Controllers\StageAutomationController::class, 'store'])->name('pipelines.automations.store');
    Route::post('/automations/{automation}/toggle', [\App\Http\Controllers\StageAutomationController::class, 'toggle'])->name('automations.toggle');
    Route::delete('/automations/{automation}', [\App\Http\Controllers\StageAutomationController::class, 'destroy'])->name('automations.destroy');

    // Inbox unificado (bandeja de conversaciones activas)
    Route::get('/inbox', [\App\Http\Controllers\InboxController::class, 'index'])->name('inbox');

    // Reportes
    Route::get('/reports', [\App\Http\Controllers\ReportController::class, 'index'])->name('reports.index');

    // Notificaciones (accesible a todos los usuarios logueados)
    Route::get('/notifications', [\App\Http\Controllers\NotificationController::class, 'index'])->name('notifications');
    Route::post('/notifications/read-all', [\App\Http\Controllers\NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::get('/notifications/{notification}/go', [\App\Http\Controllers\NotificationController::class, 'go'])->name('notifications.go');

    // ---- SECCIONES ADMIN-ONLY (bloqueadas para agent/viewer) ----
    Route::middleware('admin.only')->group(function () {
        // Formularios web
        Route::get('/settings/web-forms', [\App\Http\Controllers\WebFormController::class, 'index'])->name('webforms.index');
        Route::post('/settings/web-forms', [\App\Http\Controllers\WebFormController::class, 'store'])->name('webforms.store');
        Route::post('/settings/web-forms/{webForm}/toggle', [\App\Http\Controllers\WebFormController::class, 'toggle'])->name('webforms.toggle');
        Route::delete('/settings/web-forms/{webForm}', [\App\Http\Controllers\WebFormController::class, 'destroy'])->name('webforms.destroy');

        // Equipo
        Route::get('/settings/team', [\App\Http\Controllers\TeamController::class, 'index'])->name('settings.team');
        Route::post('/settings/team/auto-assign', [\App\Http\Controllers\TeamController::class, 'toggleAutoAssign'])->name('team.auto-assign');
        Route::post('/settings/team/invitations', [\App\Http\Controllers\TeamController::class, 'invite'])->name('team.invite');
        Route::delete('/settings/team/invitations/{invitation}', [\App\Http\Controllers\TeamController::class, 'revokeInvitation'])->name('team.invitations.revoke');
        Route::post('/settings/team/invitations/{invitation}/regenerate', [\App\Http\Controllers\TeamController::class, 'regenerateInvitation'])->name('team.invitations.regenerate');
        Route::patch('/settings/team/members/{member}', [\App\Http\Controllers\TeamController::class, 'updateMember'])->name('team.members.update');
        Route::delete('/settings/team/members/{member}', [\App\Http\Controllers\TeamController::class, 'removeMember'])->name('team.members.remove');
        Route::post('/settings/team/members/{member}/transfer-ownership', [\App\Http\Controllers\TeamController::class, 'transferOwnership'])->name('team.members.transfer');
        Route::post('/settings/team/api-keys', [\App\Http\Controllers\TeamController::class, 'storeApiKey'])->name('team.api-keys.store');
        Route::delete('/settings/team/api-keys/{apiKey}', [\App\Http\Controllers\TeamController::class, 'revokeApiKey'])->name('team.api-keys.revoke');

        // Campos personalizados
        Route::get('/settings/custom-fields', [\App\Http\Controllers\CustomFieldController::class, 'index'])->name('custom-fields.index');
        Route::post('/settings/custom-fields', [\App\Http\Controllers\CustomFieldController::class, 'store'])->name('custom-fields.store');
        Route::delete('/settings/custom-fields/{customField}', [\App\Http\Controllers\CustomFieldController::class, 'destroy'])->name('custom-fields.destroy');

        // Integración con el wacrm
        Route::get('/settings/integration', [\App\Http\Controllers\IntegrationController::class, 'edit'])->name('settings.integration');
        Route::post('/settings/integration', [\App\Http\Controllers\IntegrationController::class, 'update'])->name('settings.integration.update');
        Route::post('/settings/integration/test', [\App\Http\Controllers\IntegrationController::class, 'test'])->name('settings.integration.test');
    });
});

// SSO ligero del ecosistema - consume tokens de un solo uso emitidos
// por el Komo Hub (GET publico; valida firma HMAC + nonce anti-replay).
Route::get('/sso/consume', [\App\Http\Controllers\SsoController::class, 'consume'])->name('sso.consume');

require __DIR__.'/auth.php';
