{{--
    «Informes» del equipo (P1). Sin mockup propio: se construye con el estilo ya validado (tarjetas de resumen, filtros, tablas);
    la usuaria lo dispensó el 2026-11-11 («ya conoces el estilo»). Cuándo tocarla: al cambiar la pantalla; las cifras y sus
    DEFINICIONES están en App\Support\TicketReport (si se redefine una, se cambia también el texto de «Cómo se calcula» de aquí).
    Variables: $from, $to, $trimmed, $presets, $totals, $byDepartment, $byCompany, $priorities, $byAgent (vacío salvo administrador).
    Trampas: todo viene ya limitado a lo que ESTA persona ve (R13); la tabla «por agente» solo se pinta si hay filas (solo el administrador).
--}}
@extends('layouts.panel')
@section('titulo', 'Informes')

@php
    $fmt = fn (?float $n, int $dec = 1) => $n === null ? '—' : number_format($n, $dec, ',', '.');
    $humanMin = fn (?float $m) => \App\Support\TicketReport::humanMinutes($m);
    $humanHours = fn (?float $h) => $h === null ? '—' : \App\Support\TicketReport::humanMinutes($h * 60);
    $stars = fn (?float $avg) => $avg === null ? '—' : '★ '.number_format($avg, 1, ',', '.').' / 5';
@endphp

@section('contenido')
    <div class="titulo-fila">
        <div>
            <h1>Informes</h1>
            <p class="sub">Del {{ $from->translatedFormat('j M Y') }} al {{ $to->translatedFormat('j M Y') }}. Solo cuentan los tickets que tú ves.</p>
        </div>
    </div>

    @if ($errors->any())
        <div class="aviso" role="alert"><strong>Revisa el periodo:</strong> {{ $errors->first() }}</div>
    @endif
    @if ($trimmed)
        <div class="aviso" role="status">El periodo era de más de {{ \App\Support\TicketReport::MAX_DAYS }} días y se ha recortado.</div>
    @endif

    <div class="vistas" aria-label="Periodos">
        <span>Periodo:</span>
        @foreach ($presets as $days => $label)
            <a href="{{ route('reports.index', ['desde' => now()->subDays($days - 1)->toDateString(), 'hasta' => now()->toDateString()]) }}">{{ $label }}</a>
        @endforeach
    </div>

    <form class="filtros" action="{{ route('reports.index') }}" method="get" aria-label="Elegir periodo">
        <div>
            <label for="f-desde">Desde</label>
            <input type="date" id="f-desde" name="desde" value="{{ $from->toDateString() }}">
        </div>
        <div>
            <label for="f-hasta">Hasta</label>
            <input type="date" id="f-hasta" name="hasta" value="{{ $to->toDateString() }}">
        </div>
        <button type="submit" class="boton secundario">Ver informe</button>
    </form>

    <section class="resumen" aria-label="Resumen del periodo">
        <div class="dato"><strong>{{ $totals['created'] }}</strong>Tickets creados</div>
        <div class="dato"><strong>{{ $totals['resolved'] }}</strong>Tickets resueltos</div>
        <div class="dato"><strong>{{ $humanMin($totals['first_response_min']) }}</strong>Primera respuesta (media)</div>
        <div class="dato"><strong>{{ $humanHours($totals['resolution_hours']) }}</strong>Tiempo hasta resolver (media)</div>
        <div class="dato"><strong>{{ $stars($totals['rating_avg']) }}</strong>Valoración media ({{ $totals['rating_count'] }})</div>
    </section>

    <section class="resumen" aria-label="Situación ahora">
        <div class="dato {{ $totals['unassigned'] > 0 ? 'atencion' : '' }}"><strong>{{ $totals['unassigned'] }}</strong>Vivos sin asignar ahora</div>
        <div class="dato"><strong>{{ $totals['live'] }}</strong>Vivos ahora (sin resolver ni cerrar)</div>
        @foreach (\App\Enums\Priority::cases() as $priority)
            @continue($priority === \App\Enums\Priority::Normal || $priority === \App\Enums\Priority::Low)
            <div class="dato"><strong>{{ $priorities[$priority->value] }}</strong>Vivos con prioridad {{ mb_strtolower($priority->label()) }}</div>
        @endforeach
    </section>

    <div class="tabla-caja">
        <table>
            <caption>Por departamento</caption>
            <thead>
                <tr>
                    <th scope="col">Departamento</th>
                    <th scope="col">Creados</th>
                    <th scope="col">Resueltos</th>
                    <th scope="col">Vivos ahora</th>
                    <th scope="col">Tiempo hasta resolver</th>
                    <th scope="col">Valoración</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($byDepartment as $row)
                    <tr>
                        <td data-etiqueta="Departamento"><span class="dep {{ $row['department']->cssClass() }}">{{ $row['department']->label() }}</span></td>
                        <td class="cifra" data-etiqueta="Creados">{{ $row['created'] }}</td>
                        <td class="cifra" data-etiqueta="Resueltos">{{ $row['resolved'] }}</td>
                        <td class="cifra" data-etiqueta="Vivos ahora">{{ $row['live'] }}</td>
                        <td class="cifra" data-etiqueta="Tiempo hasta resolver">{{ $humanHours($row['resolution_hours']) }}</td>
                        <td class="cifra" data-etiqueta="Valoración">{{ $stars($row['rating_avg']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="tabla-caja separada">
        <table>
            <caption>Empresas con más tickets (las {{ \App\Support\TicketReport::TOP_COMPANIES }} primeras)</caption>
            <thead>
                <tr><th scope="col">Empresa</th><th scope="col">Creados</th><th scope="col">Resueltos</th><th scope="col">Valoración</th></tr>
            </thead>
            <tbody>
                @forelse ($byCompany as $row)
                    <tr>
                        <td data-etiqueta="Empresa"><strong>{{ $row['name'] }}</strong></td>
                        <td class="cifra" data-etiqueta="Creados">{{ $row['created'] }}</td>
                        <td class="cifra" data-etiqueta="Resueltos">{{ $row['resolved'] }}</td>
                        <td class="cifra" data-etiqueta="Valoración">{{ $stars($row['rating_avg']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4">No se ha creado ningún ticket en este periodo.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if (count($byAgent) > 0)
        <div class="tabla-caja separada">
            <table>
                <caption>Por agente (solo lo ve el administrador)</caption>
                <thead>
                    <tr>
                        <th scope="col">Agente</th>
                        <th scope="col">Equipo</th>
                        <th scope="col">Resueltos</th>
                        <th scope="col">Vivos ahora</th>
                        <th scope="col">Tiempo hasta resolver</th>
                        <th scope="col">Valoración</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($byAgent as $row)
                        <tr>
                            <td data-etiqueta="Agente"><strong>{{ $row['name'] }}</strong></td>
                            <td data-etiqueta="Equipo">{{ $row['team'] }}</td>
                            <td class="cifra" data-etiqueta="Resueltos">{{ $row['resolved'] }}</td>
                            <td class="cifra" data-etiqueta="Vivos ahora">{{ $row['live'] }}</td>
                            <td class="cifra" data-etiqueta="Tiempo hasta resolver">{{ $humanHours($row['resolution_hours']) }}</td>
                            <td class="cifra" data-etiqueta="Valoración">{{ $stars($row['rating_avg']) }} <small style="color:var(--texto-suave)">({{ $row['rating_count'] }})</small></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <details class="tarjeta" style="margin-top:1.25rem">
        <summary><strong>Cómo se calcula</strong></summary>
        <ul class="ayuda" style="margin:.6rem 0 0">
            <li><strong>Creados:</strong> tickets abiertos dentro del periodo. <strong>Resueltos:</strong> los que se marcaron como resueltos dentro del periodo (aunque se abrieran antes).</li>
            <li><strong>Vivos ahora:</strong> los que hoy no están resueltos ni cerrados; no depende del periodo.</li>
            <li><strong>Primera respuesta:</strong> desde que se abre el ticket hasta el primer mensaje público de una persona del equipo (las notas internas no cuentan).</li>
            <li><strong>Tiempo hasta resolver:</strong> desde que se abre hasta que se marca como resuelto, en los resueltos del periodo.</li>
            <li><strong>Valoración:</strong> media de las valoraciones hechas dentro del periodo, entre paréntesis cuántas son.</li>
            @if (count($byAgent) > 0)<li><strong>Por agente:</strong> los resueltos por quien lo lleva asignado ahora. Si un ticket cambió de manos antes de resolverse, cuenta para el último.</li>@endif
        </ul>
    </details>
@endsection
