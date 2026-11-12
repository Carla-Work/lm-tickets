<?php

/*
 * Plazos de la cuarentena en `retention:run` (decididos por la usuaria, 2026-11-12): posible spam 30 días, pendientes 90, y los ya procesados guardan
 * solo datos mínimos y se borran con los 90. Cuándo tocarlo: al cambiar un plazo. Vigila que se purga LO VENCIDO (fila y ficheros) y NADA más.
 */

namespace Tests\Feature\Inbound;

use App\Models\Attachment;
use App\Models\InboundEmail;
use App\Models\InboundEmailAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class InboundRetentionTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function correo(string $estado, int $diasAtras, bool $conAdjunto = false): InboundEmail
    {
        $this->n++;
        $email = (new InboundEmail)->forceFill([
            'mailbox' => 'apps', 'message_id' => "r{$this->n}@x", 'from_email' => 'a@b.test', 'to_addresses' => [], 'subject' => 'Asunto',
            'status' => $estado, 'reasons' => [], 'received_at' => now()->subDays($diasAtras),
        ]);
        $email->save();
        if ($conAdjunto) {
            Storage::disk(Attachment::DISK)->put("inbound/{$email->id}/f.png", 'x');
            (new InboundEmailAttachment)->forceFill(['inbound_email_id' => $email->id, 'path' => "inbound/{$email->id}/f.png", 'original_name' => 'f.png', 'mime_type' => 'image/png', 'size' => 1])->save();
        }

        return $email;
    }

    #[TestDox('Purga el spam de más de 30 días y lo demás de más de 90 (con sus ficheros); conserva lo que está dentro de plazo, y --dry-run no borra nada')]
    public function test_it_purges_by_term(): void
    {
        Storage::fake(Attachment::DISK);
        $spamViejo = $this->correo(InboundEmail::SPAM, 31, true);
        $spamReciente = $this->correo(InboundEmail::SPAM, 29);
        $pendienteViejo = $this->correo(InboundEmail::PENDING, 91, true);
        $pendiente60 = $this->correo(InboundEmail::PENDING, 60, true); // pendiente de 60 días: dentro de plazo (90), aunque sería spam vencido
        $ticketViejo = $this->correo(InboundEmail::TICKET, 100);
        $ticketReciente = $this->correo(InboundEmail::TICKET, 10);

        $this->artisan('retention:run', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(6, InboundEmail::query()->count(), 'la simulación no borra');

        $this->artisan('retention:run')->assertSuccessful();

        $this->assertSame([$spamReciente->id, $pendiente60->id, $ticketReciente->id], InboundEmail::query()->orderBy('id')->pluck('id')->all());
        Storage::disk(Attachment::DISK)->assertMissing("inbound/{$spamViejo->id}/f.png");
        Storage::disk(Attachment::DISK)->assertMissing("inbound/{$pendienteViejo->id}/f.png");
        Storage::disk(Attachment::DISK)->assertExists("inbound/{$pendiente60->id}/f.png");
        $this->assertSame(1, InboundEmailAttachment::query()->count());
        $this->assertNull(InboundEmail::query()->find($ticketViejo->id));
    }
}
