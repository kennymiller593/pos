<?php

namespace App\Exceptions;

use DomainException;

/**
 * Regla de negocio incumplida. El mensaje es apto para mostrarse
 * directamente al usuario (los controllers lo envian como flash de error).
 */
class ErrorDeNegocio extends DomainException
{
}
