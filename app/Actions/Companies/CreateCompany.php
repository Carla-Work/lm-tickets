<?php

/*
 * Alta de una empresa cliente con su primer usuario (administrador de empresa) y, si se piden, sus productos.
 * Cuándo tocarlo: al cambiar qué se crea al dar de alta una empresa. Pantalla: Administración › Empresas › Nueva.
 *
 * Trampas:
 *  - Todo en UNA transacción: una empresa sin su primer usuario es una empresa a la que nadie puede entrar.
 *    La invitación se envía dentro (InviteUser); si el envío falla, no queda ni empresa ni usuario.
 *  - Quién puede hacerlo lo decide CompanyPolicy::create y el controlador; aquí se vuelve a comprobar con
 *    UserPolicy::invite (R8/R18: solo el administrador da de alta al primer administrador de empresa).
 *  - Los productos nuevos deben estar activos (un producto desactivado no se asigna, R19).
 */

namespace App\Actions\Companies;

use App\Actions\Users\InviteUser;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CreateCompany
{
    public function __construct(private readonly InviteUser $invite) {}

    /**
     * @param  array{name: string, tax_id: string, phone?: ?string, contact_email?: ?string}  $data
     * @param  list<int>  $productIds
     */
    public function __invoke(User $actor, array $data, array $productIds, string $firstUserName, string $firstUserEmail): Company
    {
        Gate::forUser($actor)->authorize('create', Company::class);

        return DB::transaction(function () use ($actor, $data, $productIds, $firstUserName, $firstUserEmail): Company {
            $company = new Company;
            $company->fill($data)->save();

            $company->products()->sync(Product::query()->where('active', true)->whereIn('id', $productIds)->pluck('id')->all());

            Gate::forUser($actor)->authorize('invite', [User::class, UserRole::CompanyAdmin, $company]); // User::class: así Laravel encuentra UserPolicy
            $this->invite->handle($firstUserName, $firstUserEmail, UserRole::CompanyAdmin, $company);

            Audit::record('company_created', "Empresa «{$company->name}» creada.", $actor, null, null, ['company_id' => $company->id, 'tax_id' => $company->tax_id]);

            return $company;
        });
    }
}
