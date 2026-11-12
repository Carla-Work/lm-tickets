<?php

/*
 * Palabra de incidencia («error», «no funciona»…): decide si el correo nuevo de un cliente conocido parece una incidencia.
 * Cuándo tocarlo: al cambiar cómo se guardan. Se guardan normalizadas (KeywordMatcher::normalize). Son generales, no de un producto.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['word'])]
class IncidentWord extends Model {}
