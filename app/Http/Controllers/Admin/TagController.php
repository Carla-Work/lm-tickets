<?php

/*
 * «Etiquetas» (Administración, solo el administrador): el catálogo de etiquetas internas de los tickets.
 * Cuándo tocarlo: al cambiar esta pantalla. Mockup validado: admin-catalogo-b-etiquetas.html.
 *
 * Trampas:
 *  - Nada se borra: se desactiva (no hay rutas DELETE y no deben añadirse).
 *  - Las etiquetas son INTERNAS: ni esta pantalla ni su catálogo los ve un cliente.
 */

namespace App\Http\Controllers\Admin;

use App\Actions\Catalog\ManageTags;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TagRequest;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TagController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Tag::class);

        return view('admin.tags.index', [
            'user' => $request->user(),
            'tags' => Tag::query()->withCount('tickets')->orderByDesc('active')->orderBy('name')->get(),
        ]);
    }

    public function store(TagRequest $request, ManageTags $tags): RedirectResponse
    {
        Gate::authorize('create', Tag::class);

        $tag = $tags->create($this->actor($request), $request->string('name')->toString());

        return redirect()->route('admin.tags.index')->with('status', "Etiqueta «{$tag->name}» creada.");
    }

    public function update(TagRequest $request, Tag $tag, ManageTags $tags): RedirectResponse
    {
        Gate::authorize('update', $tag);

        $tags->rename($this->actor($request), $tag, $request->string('name')->toString());

        return redirect()->route('admin.tags.index')->with('status', 'Etiqueta guardada.');
    }

    public function setStatus(Request $request, Tag $tag, ManageTags $tags): RedirectResponse
    {
        Gate::authorize('update', $tag);

        $tags->setActive($this->actor($request), $tag, $request->boolean('activo'));

        return redirect()->route('admin.tags.index')->with('status', "Etiqueta «{$tag->name}» ".($tag->active ? 'activada' : 'desactivada').'.');
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user !== null, 403);

        return $user;
    }
}
