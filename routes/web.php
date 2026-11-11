<?php

/*
 * Rutas web. Cuándo tocarlo: al añadir pantallas.
 *
 * R18: NO hay ruta de registro. Las cuentas nacen por invitación (App\Actions\Users\InviteUser).
 * NewPasswordController atiende dos enlaces distintos (recuperar y activar) con el mismo código;
 * lo que cambia es el «broker», que se pasa como valor por defecto de la ruta.
 */

use App\Http\Controllers\Admin\CatalogController;
use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\TagController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\CompanyAreaController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\NoticeController;
use App\Http\Controllers\PanelController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Records\AuditLogController;
use App\Http\Controllers\Records\BackupController;
use App\Http\Controllers\Records\MailLogController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TicketAssignmentController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\TicketDepartmentController;
use App\Http\Controllers\TicketMessageController;
use App\Http\Controllers\TicketPriorityController;
use App\Http\Controllers\TicketRatingController;
use App\Http\Controllers\TicketStatusController;
use App\Http\Controllers\TwoFactorSettingsController;
use App\Http\Controllers\UserStatusController;
use Illuminate\Support\Facades\Route;

// La raíz no tiene página propia: lleva al panel. Quien no ha iniciado sesión llega a /panel y el
// middleware «auth» lo manda a /acceso; quien sí, PanelController lo reparte (equipo→/bandeja, cliente→/tickets).
Route::redirect('/', '/panel');

// Solo para quien NO ha iniciado sesión.
Route::middleware('guest')->group(function () {
    Route::get('/acceso', [LoginController::class, 'create'])->name('login');
    Route::post('/acceso', [LoginController::class, 'store'])->name('login.store');

    Route::get('/recuperar', [ForgotPasswordController::class, 'create'])->name('password.request');
    // Tope de peticiones por IP para que nadie use el formulario para llenar de correos un buzón ajeno.
    Route::post('/recuperar', [ForgotPasswordController::class, 'store'])->middleware('throttle:6,1')->name('password.email');
    Route::get('/recuperar/enviado', [ForgotPasswordController::class, 'sent'])->name('password.sent');
});

// Segundo factor del administrador (R21). SIN sesión iniciada pero con el «acceso a medias» (correo y
// contraseña ya acertados): lo exige el middleware login.pending.
Route::prefix('acceso/segundo-factor')->middleware(['guest', 'login.pending'])->group(function () {
    Route::get('/activar', [TwoFactorController::class, 'setup'])->name('two-factor.setup');
    Route::post('/activar', [TwoFactorController::class, 'confirmSetup'])->name('two-factor.setup.confirm');
    Route::get('/codigos', [TwoFactorController::class, 'codes'])->name('two-factor.codes');
    Route::post('/codigos', [TwoFactorController::class, 'finish'])->name('two-factor.finish');
    Route::get('/', [TwoFactorController::class, 'challenge'])->name('two-factor.challenge');
    Route::post('/', [TwoFactorController::class, 'verify'])->name('two-factor.verify');
    Route::get('/recuperacion', [TwoFactorController::class, 'recovery'])->name('two-factor.recovery');
    Route::post('/recuperacion', [TwoFactorController::class, 'useRecovery'])->name('two-factor.recovery.use');
});

// Enlaces de los correos. SIN el middleware «guest»: lo protege el token, y quien abre el enlace
// puede tener otra sesión iniciada en ese navegador.
Route::get('/contrasena/{token}', [NewPasswordController::class, 'create'])->defaults('broker', 'users')->name('password.reset');
Route::post('/contrasena', [NewPasswordController::class, 'store'])->defaults('broker', 'users')->name('password.update');
Route::get('/invitacion/{token}', [NewPasswordController::class, 'create'])->defaults('broker', 'invitations')->name('invitation.accept');
Route::post('/invitacion', [NewPasswordController::class, 'store'])->defaults('broker', 'invitations')->name('invitation.store');

// operational: una cuenta o empresa dada de baja pierde la sesión en la siguiente petición.
// (admin.2fa ya no se usa: el segundo factor es opcional desde 2026-11-09, ver User::requiresTwoFactor.)
Route::middleware(['auth', 'operational'])->group(function () {
    Route::get('/panel', PanelController::class)->name('panel');

    // Avisos (la campana). «leidos» va ANTES de {id}: si no, se leería como un id.
    Route::get('/avisos', [NoticeController::class, 'index'])->name('notices.index');
    Route::post('/avisos/leidos', [NoticeController::class, 'readAll'])->name('notices.read-all');
    Route::get('/avisos/{id}', [NoticeController::class, 'open'])->name('notices.open');

    // Bandeja del equipo (agentes y administrador); un cliente recibe 403.
    Route::get('/bandeja', [InboxController::class, 'index'])->name('inbox');
    Route::get('/informes', [ReportController::class, 'index'])->name('reports.index'); // cifras del equipo (P1); el cliente recibe 403

    // Pantallas del cliente. «nuevo» va ANTES de {ticket}: si no, «nuevo» se leería como el id de un ticket.
    Route::get('/tickets', [TicketController::class, 'index'])->name('tickets.index');
    Route::get('/tickets/nuevo', [TicketController::class, 'create'])->name('tickets.create');
    Route::post('/tickets', [TicketController::class, 'store'])->name('tickets.store');
    Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
    Route::post('/tickets/{ticket}/mensajes', [TicketMessageController::class, 'store'])->name('tickets.messages.store');
    Route::post('/tickets/{ticket}/notas', [TicketMessageController::class, 'storeNote'])->name('tickets.notes.store');
    Route::post('/tickets/{ticket}/estado', [TicketStatusController::class, 'update'])->name('tickets.status.update');
    Route::post('/tickets/{ticket}/asignacion', [TicketAssignmentController::class, 'update'])->name('tickets.assignment.update');
    Route::post('/tickets/{ticket}/departamento', [TicketDepartmentController::class, 'update'])->name('tickets.department.update');
    // Prioridad y etiquetas INTERNAS (P1): solo agentes que atienden el departamento; el cliente no las ve.
    Route::post('/tickets/{ticket}/prioridad', [TicketPriorityController::class, 'priority'])->name('tickets.priority.update');
    Route::post('/tickets/{ticket}/etiquetas', [TicketPriorityController::class, 'tags'])->name('tickets.tags.update');
    // Valoración de la atención (P1): solo quien abrió el ticket, con el ticket resuelto o cerrado.
    Route::post('/tickets/{ticket}/valoracion', [TicketRatingController::class, 'store'])->name('tickets.rating.store');
    Route::post('/tickets/{ticket}/resolver', [TicketStatusController::class, 'resolve'])->name('tickets.resolve');
    // R3: la ÚNICA vía de descarga de adjuntos; autoriza con AttachmentPolicy::download.
    Route::get('/adjuntos/{attachment}', [AttachmentController::class, 'download'])->name('attachments.download');
    Route::post('/salir', [LoginController::class, 'destroy'])->name('logout');

    // «Registros» (correos, auditoría, copias): SOLO el administrador (habilidad view-records, AppServiceProvider).
    // Trampa: la URL es /administracion y NO /registros: NoRegistrationTest (R18) rechaza cualquier ruta cuyo
    // nombre o dirección contenga «regist», y no se adapta ese test para que pase.
    // Administración (solo el administrador): cada acción vuelve a autorizarse con su policy.
    Route::prefix('administracion')->name('admin.')->group(function () {
        Route::get('/empresas', [CompanyController::class, 'index'])->name('companies.index');
        Route::get('/empresas/nueva', [CompanyController::class, 'create'])->name('companies.create');
        Route::post('/empresas', [CompanyController::class, 'store'])->name('companies.store');
        Route::get('/empresas/{company}', [CompanyController::class, 'show'])->name('companies.show');
        Route::post('/empresas/{company}', [CompanyController::class, 'update'])->name('companies.update');
        Route::post('/empresas/{company}/productos', [CompanyController::class, 'updateProducts'])->name('companies.products');
        Route::post('/empresas/{company}/baja', [CompanyController::class, 'deactivate'])->name('companies.deactivate');
        Route::post('/empresas/{company}/reactivar', [CompanyController::class, 'reactivate'])->name('companies.reactivate');

        // Empleados (Usuarios): alta de agentes, equipo y traspaso del rol de administrador.
        Route::get('/usuarios', [StaffController::class, 'index'])->name('staff.index');
        Route::post('/usuarios', [StaffController::class, 'store'])->name('staff.store');
        Route::post('/usuarios/traspaso', [StaffController::class, 'transfer'])->name('staff.transfer');
        Route::post('/usuarios/{user}/equipo', [StaffController::class, 'changeTeam'])->name('staff.team');

        // Catálogo: productos y categorías. Sin DELETE a propósito (R19): se desactivan.
        Route::get('/catalogo', [CatalogController::class, 'index'])->name('catalog.index');
        Route::post('/catalogo/productos', [CatalogController::class, 'storeProduct'])->name('catalog.products.store');
        Route::get('/catalogo/productos/{product}', [CatalogController::class, 'editProduct'])->name('catalog.products.edit');
        Route::post('/catalogo/productos/{product}', [CatalogController::class, 'updateProduct'])->name('catalog.products.update');
        Route::post('/catalogo/productos/{product}/estado', [CatalogController::class, 'setProductStatus'])->name('catalog.products.status');
        Route::post('/catalogo/categorias', [CatalogController::class, 'storeCategory'])->name('catalog.categories.store');
        Route::get('/catalogo/categorias/{category}', [CatalogController::class, 'editCategory'])->name('catalog.categories.edit');
        Route::post('/catalogo/categorias/{category}', [CatalogController::class, 'updateCategory'])->name('catalog.categories.update');
        Route::post('/catalogo/categorias/{category}/estado', [CatalogController::class, 'setCategoryStatus'])->name('catalog.categories.status');

        // Etiquetas internas de los tickets (P1). Nada se borra: se desactivan.
        Route::get('/etiquetas', [TagController::class, 'index'])->name('tags.index');
        Route::post('/etiquetas', [TagController::class, 'store'])->name('tags.store');
        Route::post('/etiquetas/{tag}', [TagController::class, 'update'])->name('tags.update');
        Route::post('/etiquetas/{tag}/estado', [TagController::class, 'setStatus'])->name('tags.status');
    });

    // «Mis datos» (todos): datos personales y contraseña. La contraseña tiene su propio límite de intentos.
    Route::get('/mis-datos', [ProfileController::class, 'show'])->name('profile.show');
    Route::post('/mis-datos', [ProfileController::class, 'updateData'])->name('profile.update');
    Route::post('/mis-datos/contrasena', [ProfileController::class, 'updatePassword'])->middleware('throttle:5,1')->name('profile.password');

    // Segundo factor del administrador desde «Mis datos» (R21, opcional). Las que piden código o contraseña limitan
    // intentos: `throttle:5,1` cuenta por PERSONA, no por ruta, así que activar, regenerar, desactivar y cambiar la
    // contraseña comparten los mismos 5 intentos por minuto (a propósito: no se multiplican probando rutas distintas).
    Route::prefix('mis-datos/dos-pasos')->name('profile.two-factor.')->group(function () {
        Route::get('/activar', [TwoFactorSettingsController::class, 'setup'])->name('setup');
        Route::post('/activar', [TwoFactorSettingsController::class, 'confirm'])->middleware('throttle:5,1')->name('confirm');
        Route::get('/codigos', [TwoFactorSettingsController::class, 'codes'])->name('codes');
        Route::post('/codigos', [TwoFactorSettingsController::class, 'finish'])->name('finish');
        Route::post('/codigos-nuevos', [TwoFactorSettingsController::class, 'regenerate'])->middleware('throttle:5,1')->name('regenerate');
        Route::post('/desactivar', [TwoFactorSettingsController::class, 'disable'])->middleware('throttle:5,1')->name('disable');
    });

    // «Mi empresa» (cliente): productos en solo lectura y, para su administrador, los usuarios de su empresa.
    Route::get('/mi-empresa', [CompanyAreaController::class, 'show'])->name('company.show');
    Route::post('/mi-empresa/usuarios', [CompanyAreaController::class, 'store'])->name('company.users.store');
    Route::post('/mi-empresa/usuarios/{target}/rol', [CompanyAreaController::class, 'changeRole'])->name('company.users.role');

    // Baja y reactivación de cuentas (quién puede a quién: UserPolicy::deactivate).
    Route::post('/usuarios/{user}/baja', [UserStatusController::class, 'deactivate'])->name('users.deactivate');
    Route::post('/usuarios/{user}/reactivar', [UserStatusController::class, 'reactivate'])->name('users.reactivate');

    // Reenviar una invitación pendiente (quién puede: UserPolicy::resendInvitation).
    Route::post('/usuarios/{user}/invitacion', [InvitationController::class, 'resend'])->name('users.invitation.resend');

    Route::middleware('can:view-records')->prefix('administracion')->group(function () {
        Route::get('/correos', [MailLogController::class, 'index'])->name('records.mail');
        Route::get('/auditoria', [AuditLogController::class, 'index'])->name('records.audit');
        Route::get('/copias', [BackupController::class, 'index'])->name('records.backups');
        Route::post('/copias', [BackupController::class, 'store'])->name('records.backups.store');
    });
});
