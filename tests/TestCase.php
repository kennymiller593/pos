<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    // La BD se gestiona fuera de Laravel (sin migraciones), asi que cada test
    // corre dentro de una transaccion que se revierte al terminar.
    use DatabaseTransactions;
}
