<?php

/*
 * Validación del formulario «Nuevo ticket». Cuándo tocarlo: al cambiar los campos del formulario o R6.
 *
 * R6 se valida AQUÍ, en el servidor, y no solo con el desplegable de la pantalla: quien manipule el
 * formulario podría enviar el producto de otra empresa (R1).
 *   · La categoría debe estar activa y ser del departamento elegido.
 *   · Si la empresa tiene productos activos, el producto es OBLIGATORIO y debe ser uno de ellos.
 *   · Si no tiene ninguno, no se admite producto.
 *
 * Los mensajes de error son genéricos (R17): nunca dicen que un producto «existe pero es de otra empresa».
 */

namespace App\Http\Requests;

use App\Enums\Department;
use App\Models\Category;
use App\Models\Ticket;
use App\Support\AttachmentRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Solo los clientes abren tickets (TicketPolicy::create).
        return $this->user()?->can('create', Ticket::class) ?? false;
    }

    /** @return array<string, list<ValidationRule|string>> */
    public function rules(): array
    {
        // Si el departamento enviado no existe, `$department` es null y `->value ?? ''` da cadena vacía (no casa con ninguna categoría).
        // Departamento inexistente → null → `->value ?? ''` da cadena vacía, que no casa con ninguna categoría.
        $department = Department::tryFrom((string) $this->input('department'));
        $companyProducts = $this->user()?->company?->products()->where('active', true)->pluck('products.id')->all() ?? [];

        return [
            'department' => ['required', Rule::enum(Department::class)],
            'category_id' => ['required', 'integer', Rule::exists(Category::class, 'id')->where(
                fn ($query) => $query->where('active', true)->where('department', $department->value ?? '')
            )],
            'product_id' => $companyProducts === []
                ? ['prohibited']
                : ['required', 'integer', Rule::in($companyProducts)],
            'subject' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:10000'],
            // R15/R16: solo se relaciona con un ticket final al que esta persona puede responder con uno nuevo
            // (TicketPolicy::createRelated). Un id ajeno o inexistente da el MISMO error: no delata qué tickets hay.
            'related_ticket_id' => ['nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail): void {
                $related = Ticket::query()->find((int) $value);
                if ($related === null || ! ($this->user()?->can('createRelated', $related) ?? false)) {
                    $fail('No puedes abrir un ticket relacionado con ese ticket.');
                }
            }],
        ] + AttachmentRules::rules();
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'department' => 'departamento',
            'category_id' => 'tipo de incidencia',
            'product_id' => 'producto o servicio',
            'subject' => 'asunto',
            'description' => 'descripción',
            'related_ticket_id' => 'ticket relacionado',
        ] + AttachmentRules::attributes();
    }
}
