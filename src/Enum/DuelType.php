<?php

namespace App\Enum;

enum DuelType: string
{
    /** Tri complet du classement par tri fusion. */
    case Sort = 'sort';
    /** Placement d'un ou plusieurs éléments par insertion dichotomique. */
    case Insertion = 'insertion';
}
