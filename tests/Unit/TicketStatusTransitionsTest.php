<?php

/*
 * Transiciones de estado permitidas (R14, R15). Es la tabla de «Transiciones de estado» de
 * docs/plan/autorizacion.md. Cuándo tocarlo: solo si la usuaria cambia el ciclo de vida.
 * Se prueba sin base de datos: es lógica pura.
 */

namespace Tests\Unit;

use App\Enums\TicketStatus as S;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class TicketStatusTransitionsTest extends TestCase
{
    /** Las 25 combinaciones, escritas a mano: de qué estado a cuáles SÍ se puede ir. El resto, NO. */
    private const ALLOWED = [
        'open' => ['in_progress', 'pending_customer', 'resolved'],
        'in_progress' => ['pending_customer', 'resolved'],
        'pending_customer' => ['in_progress', 'resolved'],
        'resolved' => ['closed'],
        'closed' => [],
    ];

    #[TestDox('Las 25 combinaciones de estado a estado dan exactamente lo esperado')]
    public function test_every_pair_of_states(): void
    {
        foreach (S::cases() as $from) {
            foreach (S::cases() as $to) {
                $expected = in_array($to->value, self::ALLOWED[$from->value], true);

                $this->assertSame($expected, $from->canTransitionTo($to), "{$from->value} → {$to->value} ".($expected ? 'debe permitirse' : 'debe estar prohibido'));
            }
        }
    }

    #[TestDox('NADIE reabre (R15): desde Resuelto o Cerrado no se vuelve a ningún estado anterior')]
    public function test_nobody_reopens(): void
    {
        foreach ([S::Open, S::InProgress, S::PendingCustomer] as $earlier) {
            $this->assertFalse(S::Resolved->canTransitionTo($earlier), "Resuelto → {$earlier->value}");
            $this->assertFalse(S::Closed->canTransitionTo($earlier), "Cerrado → {$earlier->value}");
        }
        $this->assertSame([], S::Closed->allowedTargets(), 'Cerrado no tiene salida');
        $this->assertSame([S::Closed], S::Resolved->allowedTargets(), 'de Resuelto solo se cierra');
    }

    #[TestDox('Ningún estado se puede alcanzar de vuelta desde sí mismo (no hay ciclos): el orden es siempre hacia delante')]
    public function test_no_cycles_back_to_open(): void
    {
        foreach (S::cases() as $from) {
            $this->assertFalse($from->canTransitionTo(S::Open), "{$from->value} → open: «Abierto» es solo el estado inicial");
            $this->assertFalse($from->canTransitionTo($from), "{$from->value} → él mismo no es una transición");
        }
    }

    #[TestDox('Todos los estados finales son alcanzables: desde Abierto se puede llegar a Cerrado en tres pasos como mucho')]
    public function test_closed_is_reachable_from_open(): void
    {
        $this->assertTrue(S::Open->canTransitionTo(S::Resolved));
        $this->assertTrue(S::Resolved->canTransitionTo(S::Closed));
    }
}
