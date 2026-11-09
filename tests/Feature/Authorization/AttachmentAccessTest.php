<?php

/*
 * R3: los adjuntos se descargan solo con permiso y viven en almacenamiento PRIVADO, sin URL pública.
 * Cuándo tocarlo: al cambiar cómo se guardan o se sirven los adjuntos (paso 4).
 * Si falla un test, se arregla el código; NO se adapta el test.
 */

namespace Tests\Feature\Authorization;

use App\Enums\Department;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\CreatesActors;
use Tests\TestCase;

class AttachmentAccessTest extends TestCase
{
    use CreatesActors, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearActores();
    }

    private function adjuntoEn(Ticket $ticket, bool $nota): Attachment
    {
        $mensaje = $nota
            ? TicketMessage::factory()->internal()->create(['ticket_id' => $ticket->id, 'author_id' => $this->a['agApp']->id])
            : TicketMessage::factory()->create(['ticket_id' => $ticket->id, 'author_id' => $this->a['cuA']->id]);

        $adjunto = new Attachment;
        $adjunto->forceFill([
            'ticket_id' => $ticket->id, 'ticket_message_id' => $mensaje->id, 'uploaded_by' => $mensaje->author_id,
            'original_name' => 'doc.txt', 'path' => 'attachments/doc.txt', 'mime_type' => 'text/plain', 'size' => 10,
        ])->save();

        return $adjunto;
    }

    /** @param  list<string>  $descargan */
    private function assertDownloads(Attachment $adjunto, array $descargan): void
    {
        foreach ($this->a as $clave => $actor) {
            $this->assertSame(
                in_array($clave, $descargan, true),
                $actor->can('download', $adjunto),
                (in_array($clave, $descargan, true) ? 'DEBERÍA poder descargar' : 'NO debería poder descargar')." $clave",
            );
        }
    }

    #[TestDox('Adjunto de un mensaje PÚBLICO: lo descargan su autor, el administrador de empresa y el equipo; ni un compañero ni otra empresa (R1, R3)')]
    public function test_public_attachment_download(): void
    {
        $this->assertDownloads($this->adjuntoEn($this->ticket(Department::WebApps, assignedTo: 'agApp'), nota: false), ['cuA', 'caA', 'agApp', 'agSup', 'admin']);
    }

    #[TestDox('Adjunto de una NOTA interna: solo el equipo; el cliente que ve el ticket NO lo descarga (R2, R3)')]
    public function test_internal_note_attachment_download(): void
    {
        $this->assertDownloads($this->adjuntoEn($this->ticket(Department::WebApps, assignedTo: 'agApp'), nota: true), ['agApp', 'agSup', 'admin']);
    }

    #[TestDox('Adjunto de un ticket de «Otros»: lo descargan el autor, su administrador de empresa y el administrador; ningún agente (R13)')]
    public function test_other_ticket_attachment_download(): void
    {
        $this->assertDownloads($this->adjuntoEn($this->ticket(Department::Other), nota: false), ['cuA', 'caA', 'admin']);
    }

    #[TestDox('Un adjunto de un ticket que NO ve nadie de otra empresa tampoco se puede descargar aunque se conozca su identificador (R1)')]
    public function test_other_company_cannot_download(): void
    {
        $adjunto = $this->adjuntoEn($this->ticket(), nota: false);

        $this->assertFalse($this->a['cuB']->can('download', $adjunto));
        $this->assertFalse($this->a['caB']->can('download', $adjunto));
    }

    // ---------- almacenamiento privado ----------

    #[TestDox('Los adjuntos van al disco privado, cuya carpeta está FUERA del directorio público (R3)')]
    public function test_storage_is_outside_the_public_directory(): void
    {
        $raiz = (string) realpath(Storage::disk(Attachment::DISK)->path(''));

        $this->assertSame('local', Attachment::DISK);
        $this->assertStringStartsNotWith((string) realpath(public_path()), $raiz, 'el disco de adjuntos no puede estar dentro de public/');
        $this->assertStringEndsWith('storage/app/private', $raiz);
    }

    #[TestDox('No existe el enlace público public/storage (storage:link): nada de storage/ se sirve por URL (R3)')]
    public function test_no_public_storage_symlink(): void
    {
        $this->assertFalse(file_exists(public_path('storage')), 'public/storage no debe existir');
        $this->assertFalse(is_link(public_path('storage')));
    }

    #[TestDox('El disco privado NO registra rutas /storage (ni de lectura ni de SUBIDA): se cerraron a propósito (R3)')]
    public function test_private_disk_registers_no_storage_routes(): void
    {
        $this->assertFalse(Route::has('storage.local'));
        $this->assertFalse(Route::has('storage.local.upload'));
        $this->assertFalse((bool) config('filesystems.disks.local.serve'), 'serve debe ser false: ver el comentario en config/filesystems.php');
    }

    #[TestDox('Pedir un fichero privado por /storage/… da «no encontrado» y no filtra el contenido; tampoco se puede subir por ahí')]
    public function test_storage_urls_do_not_serve_private_files(): void
    {
        Storage::disk(Attachment::DISK)->put('attachments/secreto.txt', 'CONTENIDO PRIVADO');

        try {
            $lectura = $this->get('/storage/attachments/secreto.txt');
            $subida = $this->put('/storage/attachments/subido.txt', ['x' => 'y']);

            $lectura->assertNotFound();
            $this->assertStringNotContainsString('CONTENIDO PRIVADO', (string) $lectura->getContent());
            $this->assertContains($subida->getStatusCode(), [404, 405]);
            $this->assertFalse(Storage::disk(Attachment::DISK)->exists('attachments/subido.txt'));
        } finally {
            Storage::disk(Attachment::DISK)->delete(['attachments/secreto.txt', 'attachments/subido.txt']);
        }
    }

    #[TestDox('El disco por defecto de la aplicación es el privado, no el público')]
    public function test_default_disk_is_private(): void
    {
        $this->assertSame('local', config('filesystems.default'));
    }
}
