<?php

/*
 * Validación del formulario «Nuevo ticket para un cliente» (paso 2). Cuándo tocarlo: al cambiar sus campos o R6.
 * Mockups validados 2026-11-12: ticket-nuevo-interno-b-agente / c-administrador. Plan: docs/plan/p1-ticket-creado-por-agente.md.
 *
 * Es el gemelo de StoreTicketRequest (R6 igual: categoría activa del departamento; producto obligatorio solo si la empresa
 * del CLIENTE lo tiene y debe ser uno suyo), con la diferencia de que aquí la empresa sale del cliente elegido, no del usuario
 * que envía (R1). Si se cambia una de las dos, comprobar la otra.
 *
 * Trampas:
 *  - Autoriza ANTES de validar (como los demás FormRequest de administración): con `return true` un cliente recibiría errores
 *    de validación («esa persona no existe») en vez de un 403, y filtraría qué cuentas hay.
 *  - El departamento se limita a los que atiende quien lo crea (`canWorkOn`, R11/R13) y la persona asignada debe poder atender
 *    ese departamento. CreateTicket lo recomprueba (dos capas).
 *  - `notificar` es una casilla: ausente = no se avisa al cliente (decidido 2026-11-12).
 */

namespace App\Http\Requests;

use App\Enums\Department;
use App\Enums\TicketChannel;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use App\Support\AttachmentRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStaffTicketRequest extends FormRequest
{
    private ?User $customer = null;

    public function authorize(): bool
    {
        return $this->user()?->can('createForCustomer', Ticket::class) ?? false;
    }

    /** El cliente elegido, si es una persona activa de una empresa activa; si no, null. */
    public function customer(): ?User
    {
        if ($this->customer === null) {
            $customer = User::query()->with('company')->find($this->integer('solicitante'));
            $this->customer = $customer !== null && $customer->hasRole(UserRole::CompanyUser, UserRole::CompanyAdmin) && $customer->isOperational()
                ? $customer : null;
        }

        return $this->customer;
    }

    /** @return array<string, list<ValidationRule|string|\Closure>> */
    public function rules(): array
    {
        $actor = $this->user();
        $department = Department::tryFrom((string) $this->input('department'));
        $companyProducts = $this->customer()?->company?->products()->where('active', true)->pluck('products.id')->all() ?? [];
        $allowedDepartments = $actor?->workableDepartments() ?? [];

        return [
            'solicitante' => ['required', 'integer', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($this->customer() === null) {
                    $fail('Elige a una persona activa de una empresa activa.');
                }
            }],
            'canal' => ['required', Rule::in(array_map(fn (TicketChannel $c) => $c->value, TicketChannel::forStaff()))],
            'department' => ['required', Rule::in($allowedDepartments)],
            'category_id' => ['required', 'integer', Rule::exists(Category::class, 'id')->where(
                fn ($query) => $query->where('active', true)->where('is_default', false)->where('department', $department->value ?? '')
            )],
            'product_id' => $companyProducts === []
                ? ['prohibited']
                : ['required', 'integer', Rule::in($companyProducts)],
            'subject' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:10000'],
            'asignado' => ['required', 'integer', function (string $attribute, mixed $value, \Closure $fail) use ($department): void {
                $assignee = User::query()->find((int) $value);
                $valid = $assignee !== null && $department !== null && $assignee->hasRole(UserRole::Agent)
                    && $assignee->isOperational() && $assignee->canWorkOn($department);
                if (! $valid) {
                    $fail('Solo se puede asignar a un agente en activo que atienda este departamento.');
                }
            }],
            'notificar' => ['nullable', 'boolean'],
        ] + AttachmentRules::rules();
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'solicitante' => 'persona del cliente',
            'canal' => 'canal',
            'department' => 'departamento',
            'category_id' => 'tipo de incidencia',
            'product_id' => 'producto o servicio',
            'subject' => 'asunto',
            'description' => 'descripción',
            'asignado' => 'asignado a',
        ] + AttachmentRules::attributes();
    }
}
