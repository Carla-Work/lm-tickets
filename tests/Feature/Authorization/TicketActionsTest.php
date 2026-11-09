<?php

/*
 * Quién puede HACER qué sobre un ticket, situación por situación. Es la matriz de
 * docs/plan/autorizacion.md convertida en test: reglas R1, R2, R4, R10, R11, R12, R13 y R15.
 * Cuándo tocarlo: solo si la usuaria cambia una regla; cambiar la matriz del documento Y este test.
 * Si falla, se arregla la policy o se discute la regla: NO se adapta el test.
 *
 * CÓMO LEERLO: cada test describe UNA situación (departamento, estado, quién lo lleva) y lista, por
 * rol, lo que SE PUEDE hacer. Todo lo que no figure en la lista se comprueba como DENEGADO. Así es
 * imposible que se abra un permiso sin que un test se ponga rojo.
 */

namespace Tests\Feature\Authorization;

use App\Enums\Department;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\CreatesActors;
use Tests\TestCase;

class TicketActionsTest extends TestCase
{
    use CreatesActors, RefreshDatabase;

    private const ABILITIES = [
        'view', 'viewInternal', 'reply', 'addNote', 'markResolved', 'resolve',
        'updateStatus', 'close', 'assign', 'changeDepartment', 'createRelated',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearActores();
    }

    /**
     * Comprueba TODAS las habilidades de TODOS los actores: las listadas, permitidas; el resto, denegadas.
     *
     * @param  array<string, list<string>>  $permitido  actor => habilidades permitidas
     */
    private function assertPermissions(Ticket $ticket, array $permitido): void
    {
        $this->assertSame([], array_diff(array_keys($permitido), array_keys($this->a)), 'actor desconocido en la matriz');

        foreach ($this->a as $clave => $actor) {
            foreach (self::ABILITIES as $habilidad) {
                $esperado = in_array($habilidad, $permitido[$clave] ?? [], true);
                $this->assertSame(
                    $esperado,
                    $actor->can($habilidad, $ticket),
                    ($esperado ? 'DEBERÍA poder' : 'NO debería poder')." $clave «{$habilidad}» (departamento {$ticket->department->value}, estado {$ticket->status->value})",
                );
            }
        }
    }

    // ---------- Aplicaciones web, abierto ----------

    #[TestDox('Abierto y SIN asignar: ningún agente puede responder ni cambiar el estado; antes debe asignárselo (R11)')]
    public function test_open_unassigned(): void
    {
        $this->assertPermissions($this->ticket(Department::WebApps, TicketStatus::Open), [
            'cuA' => ['view', 'reply', 'markResolved'],
            'caA' => ['view'], // solo lee (propuesta): no responde por su compañero
            'agSup' => ['view', 'viewInternal', 'addNote', 'assign', 'changeDepartment'],
            'agApp' => ['view', 'viewInternal', 'addNote', 'assign', 'changeDepartment'],
            'admin' => ['view', 'viewInternal', 'assign'], // R10: solo lectura, pero puede reasignar
        ]);
    }

    #[TestDox('Abierto y asignado a un agente: ese agente lo gestiona; su compañero del otro equipo solo ayuda con notas, reasignando y corrigiendo el departamento')]
    public function test_open_assigned(): void
    {
        $this->assertPermissions($this->ticket(Department::WebApps, TicketStatus::Open, 'agApp'), [
            'cuA' => ['view', 'reply', 'markResolved'],
            'caA' => ['view'],
            'agApp' => ['view', 'viewInternal', 'reply', 'addNote', 'resolve', 'updateStatus', 'assign', 'changeDepartment'],
            'agSup' => ['view', 'viewInternal', 'addNote', 'assign', 'changeDepartment'],
            'admin' => ['view', 'viewInternal', 'assign'],
        ]);
    }

    #[TestDox('En curso: los mismos permisos que en «Abierto»')]
    public function test_in_progress(): void
    {
        $this->assertPermissions($this->ticket(Department::WebApps, TicketStatus::InProgress, 'agApp'), [
            'cuA' => ['view', 'reply', 'markResolved'],
            'caA' => ['view'],
            'agApp' => ['view', 'viewInternal', 'reply', 'addNote', 'resolve', 'updateStatus', 'assign', 'changeDepartment'],
            'agSup' => ['view', 'viewInternal', 'addNote', 'assign', 'changeDepartment'],
            'admin' => ['view', 'viewInternal', 'assign'],
        ]);
    }

    #[TestDox('Pendiente de cliente: el cliente puede responder (y eso lo pasará a En curso, R14); los permisos del equipo no cambian')]
    public function test_pending_customer(): void
    {
        $this->assertPermissions($this->ticket(Department::WebApps, TicketStatus::PendingCustomer, 'agApp'), [
            'cuA' => ['view', 'reply', 'markResolved'],
            'caA' => ['view'],
            'agApp' => ['view', 'viewInternal', 'reply', 'addNote', 'resolve', 'updateStatus', 'assign', 'changeDepartment'],
            'agSup' => ['view', 'viewInternal', 'addNote', 'assign', 'changeDepartment'],
            'admin' => ['view', 'viewInternal', 'assign'],
        ]);
    }

    #[TestDox('Resuelto: el cliente ya no escribe ni reabre (R4, R15) y solo puede abrir uno relacionado; el equipo escribe y cierra, pero NO reasigna ni cambia de departamento')]
    public function test_resolved(): void
    {
        $this->assertPermissions($this->ticket(Department::WebApps, TicketStatus::Resolved, 'agApp'), [
            'cuA' => ['view', 'createRelated'],
            'caA' => ['view', 'createRelated'],
            'agApp' => ['view', 'viewInternal', 'reply', 'addNote', 'close'],
            'agSup' => ['view', 'viewInternal', 'addNote'],
            'admin' => ['view', 'viewInternal'],
        ]);
    }

    #[TestDox('Cerrado es el final: nadie escribe nada más, ni el equipo; solo se mira y el cliente puede abrir uno relacionado (R15)')]
    public function test_closed(): void
    {
        $this->assertPermissions($this->ticket(Department::WebApps, TicketStatus::Closed, 'agApp'), [
            'cuA' => ['view', 'createRelated'],
            'caA' => ['view', 'createRelated'],
            'agApp' => ['view', 'viewInternal'],
            'agSup' => ['view', 'viewInternal'],
            'admin' => ['view', 'viewInternal'],
        ]);
    }

    // ---------- Soporte: cobertura entre equipos ----------

    #[TestDox('Un ticket de Soporte asignado a su agente: el agente de Aplicaciones tiene los permisos de «compañero», no de gestor (cubrir ausencias)')]
    public function test_support_ticket_seen_by_the_other_team(): void
    {
        $this->assertPermissions($this->ticket(Department::Support, TicketStatus::Open, 'agSup'), [
            'cuA' => ['view', 'reply', 'markResolved'],
            'caA' => ['view'],
            'agSup' => ['view', 'viewInternal', 'reply', 'addNote', 'resolve', 'updateStatus', 'assign', 'changeDepartment'],
            'agApp' => ['view', 'viewInternal', 'addNote', 'assign', 'changeDepartment'],
            'admin' => ['view', 'viewInternal', 'assign'],
        ]);
    }

    #[TestDox('Tras asignárselo, el agente que cubre una ausencia ya puede responder (y el anterior deja de poder)')]
    public function test_claiming_a_ticket_grants_handling_rights(): void
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::Open, 'agSup');
        $this->assertFalse($this->a['agApp']->can('reply', $ticket));
        $this->assertTrue($this->a['agSup']->can('reply', $ticket));

        $ticket->forceFill(['assigned_to' => $this->a['agApp']->id])->save();

        $this->assertTrue($this->a['agApp']->can('reply', $ticket->fresh()));
        $this->assertFalse($this->a['agSup']->can('reply', $ticket->fresh()));
    }

    // ---------- Otros: solo el administrador ----------

    #[TestDox('«Otros» abierto: lo gestiona SOLO el administrador (incluido pasarlo a otro departamento); ningún agente lo ve ni lo toca; nadie puede «asignarlo» (R13)')]
    public function test_other_open(): void
    {
        $this->assertPermissions($this->ticket(Department::Other, TicketStatus::Open), [
            'cuA' => ['view', 'reply', 'markResolved'],
            'caA' => ['view'],
            'admin' => ['view', 'viewInternal', 'reply', 'addNote', 'resolve', 'updateStatus', 'changeDepartment'],
        ]);
    }

    #[TestDox('«Otros» con un agente asignado de antes (p. ej. un ticket que se movió a «Otros»): el agente pierde TODO (R13)')]
    public function test_other_with_stale_assignee(): void
    {
        $this->assertPermissions($this->ticket(Department::Other, TicketStatus::Open, 'agApp'), [
            'cuA' => ['view', 'reply', 'markResolved'],
            'caA' => ['view'],
            'admin' => ['view', 'viewInternal', 'reply', 'addNote', 'resolve', 'updateStatus', 'changeDepartment'],
        ]);
    }

    #[TestDox('«Otros» resuelto: el administrador escribe y cierra; ya no puede cambiarlo de departamento (estado final)')]
    public function test_other_resolved(): void
    {
        $this->assertPermissions($this->ticket(Department::Other, TicketStatus::Resolved), [
            'cuA' => ['view', 'createRelated'],
            'caA' => ['view', 'createRelated'],
            'admin' => ['view', 'viewInternal', 'reply', 'addNote', 'close'],
        ]);
    }

    #[TestDox('«Otros» cerrado: nadie escribe; el administrador solo mira')]
    public function test_other_closed(): void
    {
        $this->assertPermissions($this->ticket(Department::Other, TicketStatus::Closed), [
            'cuA' => ['view', 'createRelated'],
            'caA' => ['view', 'createRelated'],
            'admin' => ['view', 'viewInternal'],
        ]);
    }

    // ---------- Reglas transversales ----------

    #[TestDox('Ningún cliente tiene jamás una habilidad interna (notas, estado, cerrar, reasignar, departamento), en ninguna situación')]
    public function test_clients_never_have_internal_abilities(): void
    {
        $internas = ['viewInternal', 'addNote', 'resolve', 'updateStatus', 'close', 'assign', 'changeDepartment'];

        foreach (Department::cases() as $departamento) {
            foreach (TicketStatus::cases() as $estado) {
                $ticket = $this->ticket($departamento, $estado, 'agApp');
                foreach (['cuA', 'cuA2', 'caA', 'cuB', 'caB'] as $cliente) {
                    foreach ($internas as $habilidad) {
                        $this->assertFalse($this->a[$cliente]->can($habilidad, $ticket), "$cliente «{$habilidad}» ({$departamento->value}, {$estado->value})");
                    }
                }
            }
        }
    }

    #[TestDox('En NINGUNA situación el cliente puede responder en un ticket final (R4) ni nadie puede volver a escribir en uno Cerrado (R15)')]
    public function test_nobody_writes_in_closed_and_clients_not_in_resolved(): void
    {
        foreach (Department::cases() as $departamento) {
            $resuelto = $this->ticket($departamento, TicketStatus::Resolved, 'agApp');
            $cerrado = $this->ticket($departamento, TicketStatus::Closed, 'agApp');

            foreach (['cuA', 'caA'] as $cliente) {
                $this->assertFalse($this->a[$cliente]->can('reply', $resuelto), "$cliente escribe en Resuelto ({$departamento->value})");
                $this->assertFalse($this->a[$cliente]->can('markResolved', $resuelto));
            }
            foreach ($this->a as $clave => $actor) {
                $this->assertFalse($actor->can('reply', $cerrado), "$clave escribe en Cerrado ({$departamento->value})");
                $this->assertFalse($actor->can('addNote', $cerrado), "$clave anota en Cerrado ({$departamento->value})");
            }
        }
    }

    #[TestDox('Solo se puede abrir un ticket relacionado de uno FINAL, y nunca el equipo: solo el cliente (R15)')]
    public function test_create_related_only_for_final_tickets_and_only_clients(): void
    {
        foreach ([TicketStatus::Open, TicketStatus::InProgress, TicketStatus::PendingCustomer] as $estado) {
            $this->assertFalse($this->a['cuA']->can('createRelated', $this->ticket(Department::WebApps, $estado)), "createRelated en {$estado->value}");
        }

        $final = $this->ticket(Department::WebApps, TicketStatus::Closed, 'agApp');
        foreach (['agSup', 'agApp', 'admin', 'cuA2', 'cuB', 'caB'] as $otro) {
            $this->assertFalse($this->a[$otro]->can('createRelated', $final), "$otro no debería poder abrir uno relacionado");
        }
    }
}
