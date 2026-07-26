<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avisos del admin al equipo: notas y recordatorios que aterrizan en las
 * notificaciones del responsable.
 *
 * Se montan sobre app_notifications en vez de crear una tabla nueva: el
 * destinatario ya las ve en su campana y en /notifications sin tocar nada de
 * la lectura. Lo único que faltaba era clasificarlas y poder programarlas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_notifications', function (Blueprint $table) {
            // seguimiento | personal | marketing. Null en las notificaciones
            // automáticas del sistema, que no pertenecen a ningún apartado.
            $table->string('category', 20)->nullable()->after('type');

            // Null = se ve al instante. Con fecha, la notificación existe pero
            // permanece oculta hasta ese momento: así un recordatorio no
            // necesita cron ni cola, basta con filtrar al leer.
            $table->timestamp('deliver_at')->nullable()->after('body');

            $table->foreignUuid('sent_by_user_id')->nullable()->after('deliver_at')
                ->constrained('users')->nullOnDelete();

            // Un envío masivo son N filas idénticas salvo el destinatario.
            // El batch las vuelve a unir en el historial del admin ("1 aviso
            // a 4 personas") sin tener que adivinar por título + timestamp.
            $table->uuid('batch_id')->nullable()->after('sent_by_user_id');

            $table->index(['user_id', 'deliver_at']);
            $table->index('batch_id');
        });

        // El cuerpo era varchar(255): corto para una nota redactada a mano.
        Schema::table('app_notifications', function (Blueprint $table) {
            $table->text('body')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('app_notifications', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'deliver_at']);
            $table->dropIndex(['batch_id']);
            $table->dropConstrainedForeignId('sent_by_user_id');
            $table->dropColumn(['category', 'deliver_at', 'batch_id']);
            $table->string('body')->nullable()->change();
        });
    }
};
