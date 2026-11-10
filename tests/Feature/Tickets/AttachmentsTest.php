<?php

/*
 * Adjuntos. Reglas: R3 (almacenamiento privado y descarga solo con autorización), R2 (los adjuntos de una nota
 * interna no llegan al cliente), R1 (nada de otras empresas), lista cerrada de tipos mirando el CONTENIDO real,
 * límites de tamaño/cantidad/total, nombre del usuario nunca usado como ruta, y limpieza si algo falla.
 * Usan archivos REALES (no UploadedFile::fake): los falsos informan el tipo por el nombre y no probarían la
 * detección por contenido. Cuándo tocarlo: al cambiar esas reglas. NO se adapta un test para que pase.
 */

namespace Tests\Feature\Tickets;

use App\Actions\Tickets\CreateTicket;
use App\Actions\Tickets\ReplyToTicket;
use App\Enums\Department;
use App\Enums\MessageKind;
use App\Enums\TicketStatus;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\CreatesActors;
use Tests\TestCase;
use ZipArchive;

class AttachmentsTest extends TestCase
{
    use CreatesActors, RefreshDatabase;

    /** @var list<string> */
    private array $temporales = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Attachment::DISK);
        Storage::fake('public'); // el disco público real tiene su .gitignore: se simula para comprobar que NO se escribe nada
        $this->crearActores();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) {
            @unlink($ruta);
        }
        parent::tearDown();
    }

    // ---------- fabricación de archivos reales ----------

    private function archivo(string $nombre, string $contenido): UploadedFile
    {
        $ruta = tempnam(sys_get_temp_dir(), 'adj');
        file_put_contents($ruta, $contenido);
        $this->temporales[] = $ruta;

        // `test: true` permite subirlo sin pasar por un POST real; el tipo SÍ se detecta del contenido.
        return new UploadedFile($ruta, $nombre, null, null, true);
    }

    private function png(string $nombre = 'captura.png'): UploadedFile
    {
        return $this->archivo($nombre, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    }

    private function pdf(string $nombre = 'factura.pdf'): UploadedFile
    {
        return $this->archivo($nombre, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
    }

    /** Un .xlsx/.docx de verdad es un ZIP con un [Content_Types].xml. */
    private function ooxml(string $nombre): UploadedFile
    {
        $ruta = tempnam(sys_get_temp_dir(), 'adj');
        $zip = new ZipArchive;
        $zip->open($ruta, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>');
        $zip->addFromString('xl/workbook.xml', '<workbook/>');
        $zip->close();
        $this->temporales[] = $ruta;

        return new UploadedFile($ruta, $nombre, null, null, true);
    }

    private function enviarTicket(array $adjuntos, string $quien = 'cuA')
    {
        $categoria = Category::factory()->forDepartment(Department::Support)->create();

        return $this->actingAs($this->a[$quien])->post(route('tickets.store'), [
            'department' => 'support', 'category_id' => $categoria->id, 'subject' => 'Con adjuntos',
            'description' => 'Os mando capturas', 'adjuntos' => $adjuntos,
        ]);
    }

    private function ficherosEnDisco(): array
    {
        return Storage::disk(Attachment::DISK)->allFiles();
    }

    // ---------- guardar ----------

    #[TestDox('Al abrir un ticket los adjuntos se guardan en el disco PRIVADO con nombre aleatorio y fila con datos reales (R3)')]
    public function test_attachments_are_stored_privately_with_random_names(): void
    {
        $this->enviarTicket([$this->png('mi captura.png'), $this->pdf('factura octubre.pdf')])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(2, Attachment::query()->count());
        $captura = Attachment::query()->where('original_name', 'mi captura.png')->firstOrFail();
        $this->assertSame('image/png', $captura->mime_type);
        $this->assertGreaterThan(0, $captura->size);
        $this->assertSame($this->a['cuA']->id, $captura->uploaded_by);
        $this->assertStringStartsWith("attachments/{$captura->ticket_id}/", $captura->path);
        $this->assertStringNotContainsString('captura', $captura->path, 'el nombre del usuario no se usa en disco');
        $this->assertTrue(Storage::disk(Attachment::DISK)->exists($captura->path));
        $this->assertSame($captura->ticket_id, TicketMessage::query()->findOrFail($captura->ticket_message_id)->ticket_id);
        // R3: nada en el disco público.
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    #[TestDox('El cliente adjunta al responder y el equipo al responder; las notas internas descartan los archivos')]
    public function test_attachments_on_replies_but_not_on_notes(): void
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::InProgress, 'agSup');

        $this->actingAs($this->a['cuA'])->post(route('tickets.messages.store', $ticket), ['mensaje' => 'ahí va', 'adjuntos' => [$this->png()]]);
        $this->actingAs($this->a['agSup'])->post(route('tickets.messages.store', $ticket), ['mensaje' => 'guía', 'adjuntos' => [$this->pdf()]]);
        $this->actingAs($this->a['agSup'])->post(route('tickets.notes.store', $ticket), ['mensaje' => 'nota', 'adjuntos' => [$this->png('secreto.png')]]);

        $this->assertSame(['captura.png', 'factura.pdf'], Attachment::query()->orderBy('id')->pluck('original_name')->all());
        $this->assertCount(2, $this->ficherosEnDisco());
    }

    #[TestDox('Se aceptan los tipos de uso corriente: png, jpg, pdf, txt, csv, zip, xlsx y docx reales')]
    public function test_common_types_are_accepted(): void
    {
        $archivos = [
            $this->png('a.png'), $this->pdf('b.pdf'), $this->archivo('c.txt', "hola\n"), $this->archivo('d.csv', "a,b\n1,2\n"),
            $this->ooxml('e.xlsx'),
        ];
        $this->enviarTicket($archivos)->assertSessionHasNoErrors();
        $this->assertSame(5, Attachment::query()->count());

        $this->enviarTicket([$this->ooxml('f.docx')])->assertSessionHasNoErrors();
        $this->assertSame(6, Attachment::query()->count());
    }

    // ---------- rechazar ----------

    #[TestDox('Se rechazan ejecutables, scripts y páginas por su extensión (php, exe, html, js, svg, sin extensión)')]
    public function test_dangerous_extensions_are_rejected(): void
    {
        foreach (['shell.php', 'programa.exe', 'pagina.html', 'script.js', 'dibujo.svg', 'sinextension', 'doble.php.png.exe'] as $nombre) {
            $this->enviarTicket([$this->archivo($nombre, 'contenido')])->assertSessionHasErrors('adjuntos.0');
        }
        $this->assertSame(0, Ticket::query()->count());
        $this->assertSame([], $this->ficherosEnDisco());
    }

    #[TestDox('Se mira el CONTENIDO: un script PHP o un HTML con extensión .png / .pdf se rechaza')]
    public function test_disguised_files_are_rejected_by_content(): void
    {
        foreach ([['truco.png', '<?php echo shell_exec($_GET["c"]);'], ['truco.pdf', '<html><script>alert(1)</script></html>'], ['truco.jpg', "MZ\x90\x00\x03\x00\x00\x00"]] as [$nombre, $contenido]) {
            $this->enviarTicket([$this->archivo($nombre, $contenido)])->assertSessionHasErrors('adjuntos.0');
        }
        $this->assertSame(0, Attachment::query()->count());
        $this->assertSame([], $this->ficherosEnDisco());
    }

    #[TestDox('Límites: más de 5 archivos, uno de más de 10 MB o un envío de más de 25 MB en total se rechazan sin guardar nada')]
    public function test_size_and_count_limits(): void
    {
        $seis = array_map(fn ($i) => $this->archivo("n$i.txt", 'x'), range(1, 6));
        $this->enviarTicket($seis)->assertSessionHasErrors('adjuntos');

        $grande = $this->archivo('grande.txt', str_repeat('a', 10 * 1024 * 1024 + 1024));
        $this->enviarTicket([$grande])->assertSessionHasErrors('adjuntos.0');

        $tres = array_map(fn ($i) => $this->archivo("m$i.txt", str_repeat('a', 9 * 1024 * 1024)), range(1, 3)); // 27 MB
        $this->enviarTicket($tres)->assertSessionHasErrors('adjuntos');

        $this->assertSame(0, Ticket::query()->count());
        $this->assertSame([], $this->ficherosEnDisco());
    }

    #[TestDox('El nombre original del usuario no es una ruta: «../../x.png» se guarda solo como «x.png» y en disco no aparece')]
    public function test_original_name_is_sanitized(): void
    {
        $this->enviarTicket([$this->png('../../etc/passwd.png')])->assertSessionHasNoErrors();

        $adjunto = Attachment::query()->firstOrFail();
        $this->assertSame('passwd.png', $adjunto->original_name);
        $this->assertStringNotContainsString('..', $adjunto->path);
        $this->assertStringNotContainsString('passwd', $adjunto->path);
    }

    #[TestDox('El nombre original se limpia: sin caracteres de control y recortado a 150 caracteres (conserva la extensión útil)')]
    public function test_original_name_control_chars_and_length(): void
    {
        $largo = str_repeat('a', 300).'.png';
        $this->enviarTicket([$this->png("raro\x07\x1Bnombre.png"), $this->png($largo)])->assertSessionHasNoErrors();

        $nombres = Attachment::query()->orderBy('id')->pluck('original_name')->all();
        $this->assertSame('raronombre.png', $nombres[0]);
        $this->assertLessThanOrEqual(150, mb_strlen($nombres[1]));
    }

    #[TestDox('Las notas internas no admiten adjuntos aunque alguien llame a la acción con archivos (no se escribe nada)')]
    public function test_reply_action_drops_files_on_notes(): void
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::InProgress, 'agSup');

        app(ReplyToTicket::class)($this->a['agSup'], $ticket, 'nota', MessageKind::Internal, [$this->png()]);

        $this->assertSame(0, Attachment::query()->count());
        $this->assertSame([], $this->ficherosEnDisco());
    }

    #[TestDox('R2: el ámbito Attachment::visibleTo deja fuera al cliente los adjuntos de notas internas y al equipo se los da')]
    public function test_scope_hides_note_attachments_from_clients(): void
    {
        $nota = $this->conAdjunto(MessageKind::Internal);
        $publico = $this->conAdjunto(MessageKind::Public);

        $this->assertSame([$publico->id], Attachment::query()->visibleTo($this->a['cuA'])->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$nota->id, $publico->id], Attachment::query()->visibleTo($this->a['agSup'])->pluck('id')->all());
    }

    // ---------- descargar (R3, R2, R1) ----------

    private function conAdjunto(MessageKind $tipo = MessageKind::Public, string $contenido = 'DATOS-DEL-ADJUNTO'): Attachment
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::InProgress, 'agSup');
        $mensaje = TicketMessage::factory()->create(['ticket_id' => $ticket->id, 'author_id' => $this->a['agSup']->id, 'kind' => $tipo->value]);
        $ruta = "attachments/{$ticket->id}/fichero-interno-123";
        Storage::disk(Attachment::DISK)->put($ruta, $contenido);

        $adjunto = new Attachment;
        $adjunto->ticket_id = $ticket->id;
        $adjunto->ticket_message_id = $mensaje->id;
        $adjunto->uploaded_by = $this->a['agSup']->id;
        $adjunto->original_name = 'informe.pdf';
        $adjunto->path = $ruta;
        $adjunto->mime_type = 'application/pdf';
        $adjunto->size = strlen($contenido);
        $adjunto->save();

        return $adjunto;
    }

    #[TestDox('R3: quien puede ver el ticket descarga el adjunto, siempre como descarga y con nosniff')]
    public function test_authorized_people_can_download(): void
    {
        $adjunto = $this->conAdjunto();

        foreach (['cuA', 'caA', 'agSup', 'agApp', 'admin'] as $quien) {
            $respuesta = $this->actingAs($this->a[$quien])->get(route('attachments.download', $adjunto))->assertOk();
            $this->assertStringContainsString('attachment', (string) $respuesta->headers->get('Content-Disposition'));
            $this->assertStringContainsString('informe.pdf', (string) $respuesta->headers->get('Content-Disposition'));
            $this->assertSame('nosniff', $respuesta->headers->get('X-Content-Type-Options'));
            $this->assertSame('DATOS-DEL-ADJUNTO', $respuesta->streamedContent());
        }
    }

    #[TestDox('R3/R1: sin sesión, de otra empresa o un compañero que no abrió el ticket NO descargan (403 o acceso)')]
    public function test_unauthorized_cannot_download(): void
    {
        $adjunto = $this->conAdjunto();

        $this->get(route('attachments.download', $adjunto))->assertRedirect(route('login'));
        foreach (['cuB', 'caB', 'cuA2'] as $quien) {
            $this->actingAs($this->a[$quien])->get(route('attachments.download', $adjunto))->assertForbidden();
        }
    }

    #[TestDox('R2: el adjunto de una NOTA interna es solo del equipo: el cliente recibe 403 y no ve ni el nombre')]
    public function test_note_attachments_are_staff_only(): void
    {
        $adjunto = $this->conAdjunto(MessageKind::Internal);

        $this->actingAs($this->a['cuA'])->get(route('attachments.download', $adjunto))->assertForbidden();
        $this->actingAs($this->a['caA'])->get(route('attachments.download', $adjunto))->assertForbidden();
        $this->actingAs($this->a['cuA'])->get(route('tickets.show', $adjunto->ticket_id))->assertOk()->assertDontSee('informe.pdf');
        $this->actingAs($this->a['agSup'])->get(route('tickets.show', $adjunto->ticket_id))->assertOk()->assertSee('informe.pdf');
        $this->actingAs($this->a['agSup'])->get(route('attachments.download', $adjunto))->assertOk();
    }

    #[TestDox('R13: un agente no descarga adjuntos de un ticket de «Otros»; el administrador sí')]
    public function test_agent_cannot_download_other_attachments(): void
    {
        $adjunto = $this->conAdjunto();
        Ticket::query()->whereKey($adjunto->ticket_id)->update(['department' => Department::Other->value, 'assigned_to' => null]);

        $this->actingAs($this->a['agSup'])->get(route('attachments.download', $adjunto))->assertForbidden();
        $this->actingAs($this->a['admin'])->get(route('attachments.download', $adjunto))->assertOk();
    }

    #[TestDox('Si el fichero falta en disco la descarga da 404 (no un error de servidor)')]
    public function test_missing_file_is_404(): void
    {
        $adjunto = $this->conAdjunto();
        Storage::disk(Attachment::DISK)->delete($adjunto->path);

        $this->actingAs($this->a['agSup'])->get(route('attachments.download', $adjunto))->assertNotFound();
    }

    #[TestDox('El detalle enlaza a la ruta que autoriza, nunca a /storage, y escapa el nombre original (un <script> no llega al HTML)')]
    public function test_detail_links_to_authorized_route_and_escapes_name(): void
    {
        $adjunto = $this->conAdjunto();
        $adjunto->forceFill(['original_name' => '<script>alert(1)</script>.pdf'])->save();

        $html = $this->actingAs($this->a['cuA'])->get(route('tickets.show', $adjunto->ticket_id))->assertOk()
            ->assertSee(route('attachments.download', $adjunto), false)
            ->assertDontSee('/storage/', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;.pdf', false);
    }

    // ---------- si falla algo, no quedan ficheros ----------

    #[TestDox('Si falla el guardado de un adjunto, se deshace el ticket y se borran los ficheros ya escritos (nada huérfano)')]
    public function test_failure_rolls_back_and_cleans_files(): void
    {
        $categoria = Category::factory()->forDepartment(Department::Support)->create();
        // Un archivo que existe al validar pero desaparece antes de guardarse: el segundo adjunto falla a mitad.
        $roto = $this->archivo('roto.txt', 'x');
        unlink($this->temporales[array_key_last($this->temporales)]);

        try {
            app(CreateTicket::class)($this->a['cuA'], Department::Support, $categoria, null, 'Asunto', 'Texto', [$this->png(), $roto]);
            $this->fail('debía fallar');
        } catch (\Throwable) {
            // esperado
        }

        $this->assertSame(0, Ticket::query()->count());
        $this->assertSame(0, Attachment::query()->count());
        $this->assertSame([], $this->ficherosEnDisco());
    }
}
