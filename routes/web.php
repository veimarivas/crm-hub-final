<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\WacrmWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('login'));

// Booking publico (sin auth) — pagina de reserva
Route::get('/book/{slug}', [\App\Http\Controllers\BookingController::class, 'publicShow'])->name('book.show');
Route::post('/book/{slug}', [\App\Http\Controllers\BookingController::class, 'publicStore'])
    ->middleware('throttle:book')
    ->name('book.store');
Route::get('/book/{slug}/confirmed', [\App\Http\Controllers\BookingController::class, 'publicConfirmed'])->name('book.confirmed');

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

// Layout del tablero: por usuario, no por cuenta.
Route::patch('/dashboard/layout', [\App\Http\Controllers\DashboardController::class, 'saveLayout'])
    ->middleware(['auth', 'verified'])->name('dashboard.layout');
Route::delete('/dashboard/layout', [\App\Http\Controllers\DashboardController::class, 'resetLayout'])
    ->middleware(['auth', 'verified'])->name('dashboard.layout.reset');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Leads (Kanban + ficha)
    Route::get('/leads', [\App\Http\Controllers\LeadController::class, 'index'])->name('leads.index');

    // Alias: en el wacrm este mismo tablero vive en /pipelines. Se expone con
    // la misma URL para que las dos apps se naveguen igual; /leads sigue
    // funcionando para los links viejos.
    Route::get('/pipelines', [\App\Http\Controllers\LeadController::class, 'index'])->name('pipelines.index');
    Route::get('/leads/export', [\App\Http\Controllers\LeadController::class, 'export'])->name('leads.export');
    Route::post('/leads', [\App\Http\Controllers\LeadController::class, 'store'])->name('leads.store');
    Route::post('/leads/bulk', [\App\Http\Controllers\LeadController::class, 'bulk'])->name('leads.bulk');

    // Segments (listas guardadas de leads segmentados)
    Route::post('/segments', [\App\Http\Controllers\SavedSegmentController::class, 'store'])->name('segments.store');
    Route::delete('/segments/{savedSegment}', [\App\Http\Controllers\SavedSegmentController::class, 'destroy'])->name('segments.destroy');
    Route::get('/leads/{lead}', [\App\Http\Controllers\LeadController::class, 'show'])->name('leads.show');
    Route::patch('/leads/{lead}', [\App\Http\Controllers\LeadController::class, 'update'])->name('leads.update');
    Route::patch('/leads/{lead}/move', [\App\Http\Controllers\LeadController::class, 'move'])->name('leads.move');
    Route::delete('/leads/{lead}', [\App\Http\Controllers\LeadController::class, 'destroy'])->name('leads.destroy');
    Route::post('/leads/{lead}/notes', [\App\Http\Controllers\LeadController::class, 'addNote'])->name('leads.notes.add');
    Route::patch('/leads/{lead}/tags', [\App\Http\Controllers\LeadController::class, 'syncTags'])->name('leads.tags');

    // Etiquetas
    Route::get('/tags', [\App\Http\Controllers\TagController::class, 'index'])->name('tags.index');
    Route::post('/tags', [\App\Http\Controllers\TagController::class, 'store'])->name('tags.store');
    Route::patch('/tags/{tag}', [\App\Http\Controllers\TagController::class, 'update'])->name('tags.update');
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
    Route::post('/tasks/{task}/uncomplete', [\App\Http\Controllers\TaskController::class, 'uncomplete'])->name('tasks.uncomplete');
    Route::post('/tasks/{task}/snooze', [\App\Http\Controllers\TaskController::class, 'snooze'])->name('tasks.snooze');
    Route::delete('/tasks/{task}', [\App\Http\Controllers\TaskController::class, 'destroy'])->name('tasks.destroy');

    // Contactos y empresas
    Route::get('/contacts', [\App\Http\Controllers\ContactController::class, 'index'])->name('contacts.index');
    Route::get('/contacts/export', [\App\Http\Controllers\ContactController::class, 'export'])->name('contacts.export');
    Route::get('/contacts/{contact}/timeline', [\App\Http\Controllers\ContactController::class, 'show'])->name('contacts.timeline');
    Route::post('/contacts', [\App\Http\Controllers\ContactController::class, 'store'])->name('contacts.store');
    Route::post('/contacts/import-wacrm', [\App\Http\Controllers\ContactController::class, 'importFromWacrm'])->name('contacts.import-wacrm');
    Route::patch('/contacts/{contact}', [\App\Http\Controllers\ContactController::class, 'update'])->name('contacts.update');
    Route::delete('/contacts/{contact}', [\App\Http\Controllers\ContactController::class, 'destroy'])->name('contacts.destroy');
    // Empresas: catalogo de configuracion, no pantalla de trabajo diario.
    // El agente sigue asociando empresas desde la ficha del lead — ese
    // desplegable se alimenta del LeadController, no de estas rutas.
    Route::middleware('admin.only')->group(function () {
        Route::get('/companies', [\App\Http\Controllers\CompanyController::class, 'index'])->name('companies.index');
        Route::post('/companies', [\App\Http\Controllers\CompanyController::class, 'store'])->name('companies.store');
        Route::patch('/companies/{company}', [\App\Http\Controllers\CompanyController::class, 'update'])->name('companies.update');
        Route::delete('/companies/{company}', [\App\Http\Controllers\CompanyController::class, 'destroy'])->name('companies.destroy');
    });

    // Digital Pipeline (automatizaciones por etapa)
    Route::get('/pipelines/{pipeline}/automations', [\App\Http\Controllers\StageAutomationController::class, 'index'])->name('pipelines.automations');
    Route::post('/pipelines/{pipeline}/automations', [\App\Http\Controllers\StageAutomationController::class, 'store'])->name('pipelines.automations.store');
    Route::post('/pipelines/{pipeline}/automations/recipe', [\App\Http\Controllers\StageAutomationController::class, 'applyRecipe'])->name('pipelines.automations.recipe');
    Route::post('/pipelines/{pipeline}/automations/simulate', [\App\Http\Controllers\StageAutomationController::class, 'simulate'])->name('pipelines.automations.simulate');
    Route::patch('/automations/{automation}', [\App\Http\Controllers\StageAutomationController::class, 'update'])->name('automations.update');
    Route::post('/automations/{automation}/toggle', [\App\Http\Controllers\StageAutomationController::class, 'toggle'])->name('automations.toggle');
    Route::delete('/automations/{automation}', [\App\Http\Controllers\StageAutomationController::class, 'destroy'])->name('automations.destroy');

    // Inbox unificado (bandeja de conversaciones activas)
    Route::get('/inbox', [\App\Http\Controllers\InboxController::class, 'index'])->name('inbox');

    // Reportes
    Route::get('/reports', [\App\Http\Controllers\ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/export', [\App\Http\Controllers\ReportController::class, 'export'])->name('reports.export');

    // Seguimiento de la atencion por responsable (admin-only)
    Route::get('/supervision', [\App\Http\Controllers\SupervisionController::class, 'index'])
        ->middleware('admin.only')
        ->name('supervision.index');

    // Ficha de un responsable (drill-down individual, admin-only)
    Route::get('/supervision/agents/{user}', [\App\Http\Controllers\SupervisionController::class, 'show'])
        ->middleware('admin.only')
        ->name('supervision.agent');

    // Asesores: desempeño individual con desglose por pipeline (admin-only)
    Route::get('/asesores', [\App\Http\Controllers\AsesoresController::class, 'index'])
        ->middleware('admin.only')
        ->name('asesores.index');

    // Avisos del admin al equipo: notas y recordatorios (admin-only)
    Route::middleware('admin.only')->group(function () {
        Route::get('/team-messages', [\App\Http\Controllers\TeamMessageController::class, 'index'])->name('team-messages.index');
        Route::post('/team-messages', [\App\Http\Controllers\TeamMessageController::class, 'store'])->name('team-messages.store');
    });

    // Pipeline builder (admin-only)
    Route::middleware('admin.only')->group(function () {
        Route::get('/settings/pipelines', [\App\Http\Controllers\PipelineController::class, 'index'])->name('settings.pipelines');
        Route::post('/settings/pipelines', [\App\Http\Controllers\PipelineController::class, 'store'])->name('pipelines.store');
        Route::patch('/settings/pipelines/{pipeline}', [\App\Http\Controllers\PipelineController::class, 'update'])->name('pipelines.update');
        Route::delete('/settings/pipelines/{pipeline}', [\App\Http\Controllers\PipelineController::class, 'destroy'])->name('pipelines.destroy');
        Route::post('/settings/pipelines/{pipeline}/stages', [\App\Http\Controllers\PipelineController::class, 'storeStage'])->name('pipelines.stages.store');
        Route::patch('/settings/pipelines/stages/{stage}', [\App\Http\Controllers\PipelineController::class, 'updateStage'])->name('pipelines.stages.update');
        Route::delete('/settings/pipelines/stages/{stage}', [\App\Http\Controllers\PipelineController::class, 'destroyStage'])->name('pipelines.stages.destroy');
        Route::post('/settings/pipelines/{pipeline}/stages/reorder', [\App\Http\Controllers\PipelineController::class, 'reorderStages'])->name('pipelines.stages.reorder');
    });

    // Broadcasts (envio masivo por WhatsApp).
    //
    // Abierto a los agentes: el corte no es "quien entra" sino "a quien le
    // puede escribir" — el controlador limita al agente a los leads que tiene
    // asignados, igual que el Inbox y el tablero. Cerrarlo por completo
    // obligaba a pedirle al admin cada difusion de la propia cartera.
    Route::get('/broadcasts', [\App\Http\Controllers\BroadcastController::class, 'index'])->name('broadcasts.index');
    Route::get('/broadcasts/create', [\App\Http\Controllers\BroadcastController::class, 'create'])->name('broadcasts.create');
    Route::post('/broadcasts/preview', [\App\Http\Controllers\BroadcastController::class, 'preview'])->name('broadcasts.preview');
    Route::post('/broadcasts', [\App\Http\Controllers\BroadcastController::class, 'store'])->name('broadcasts.store');
    Route::get('/broadcasts/{broadcast}', [\App\Http\Controllers\BroadcastController::class, 'show'])->name('broadcasts.show');
    Route::get('/broadcasts/{broadcast}/media', [\App\Http\Controllers\BroadcastController::class, 'media'])->name('broadcasts.media');

    // Bookings (admin del propio usuario)
    Route::get('/bookings', [\App\Http\Controllers\BookingController::class, 'index'])->name('bookings.index');
    Route::post('/bookings/{booking}/cancel', [\App\Http\Controllers\BookingController::class, 'cancel'])->name('bookings.cancel');

    // Notificaciones (accesible a todos los usuarios logueados)
    Route::get('/notifications', [\App\Http\Controllers\NotificationController::class, 'index'])->name('notifications');
    Route::post('/notifications/read-all', [\App\Http\Controllers\NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    // Aviso en vivo de mensajes entrantes, desde cualquier pantalla.
    Route::get('/notifications/recent-inbound', [\App\Http\Controllers\NotificationController::class, 'recentInbound'])->name('notifications.recent-inbound');
    Route::post('/notifications/{notification}/read', [\App\Http\Controllers\NotificationController::class, 'markRead'])->name('notifications.read');
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

        // Horario de atencion + auto-respuesta fuera de hora
        Route::get('/settings/business-hours', [\App\Http\Controllers\BusinessHoursController::class, 'edit'])->name('settings.business-hours');
        Route::patch('/settings/business-hours', [\App\Http\Controllers\BusinessHoursController::class, 'update'])->name('settings.business-hours.update');
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
