<?php

/*
 * Validación de «Crear ticket» desde un correo en cuarentena. Cuándo tocarla: al cambiar ese formulario o R6.
 * Gemelo reducido de StoreStaffTicketRequest: el departamento NO se elige, sale del buzón del correo (R13); la categoría debe ser de ese departamento y
 * activa; el producto, de la empresa de la persona elegida; la persona asignada, un agente en activo que atienda ese departamento.
 * Trampa: autoriza con la policy ANTES de validar (un no autorizado recibe 403, no mensajes de validación que filtren datos).
 */

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\InboundEmail;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateTicketFromInboundRequest extends FormRequest
{
    private ?User $customer = null;

    public function authorize(): bool
    {
        $email = $this->route('inboundEmail');

        return $email instanceof InboundEmail && ($this->user()?->can('decide', $email) ?? false);
    }

    public function customer(): ?User
    {
        if ($this->customer === null) {
            $customer = User::query()->with('company')->find($this->integer('solicitante'));
            $this->customer = $customer !== null && $customer->hasRole(UserRole::CompanyUser, UserRole::CompanyAdmin) && $customer->isOperational() ? $customer : null;
        }

        return $this->customer;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $email = $this->route('inboundEmail');
        $department = $email instanceof InboundEmail ? $email->department() : null;
        $products = $this->customer()?->company?->products()->where('active', true)->pluck('products.id')->all() ?? [];

        return [
            'solicitante' => ['required', 'integer', function (string $a, mixed $v, \Closure $fail): void {
                if ($this->customer() === null) {
                    $fail('Elige a una persona activa de una empresa activa.');
                }
            }],
            'category_id' => ['required', 'integer', Rule::exists(Category::class, 'id')->where(fn ($q) => $q->where('active', true)->where('department', $department->value ?? ''))],
            'product_id' => $products === [] ? ['prohibited'] : ['required', 'integer', Rule::in($products)],
            'asignado' => ['required', 'integer', function (string $a, mixed $v, \Closure $fail) use ($department): void {
                $assignee = User::query()->find((int) $v);
                if ($assignee === null || $department === null || ! $assignee->hasRole(UserRole::Agent) || ! $assignee->isOperational() || ! $assignee->canWorkOn($department)) {
                    $fail('Solo se puede asignar a un agente en activo que atienda este departamento.');
                }
            }],
            'notificar' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['solicitante' => 'persona', 'category_id' => 'tipo de incidencia', 'product_id' => 'producto', 'asignado' => 'asignado a'];
    }
}
