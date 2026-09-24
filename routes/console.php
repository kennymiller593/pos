<?php

use Illuminate\Support\Facades\Schedule;

// Reintenta los envios a SUNAT pendientes y confirma las bajas en proceso.
// Requiere el cron "* * * * * php artisan schedule:run" (o "schedule:work" en desarrollo).
Schedule::command('sunat:sincronizar')
    ->everyTenMinutes()
    ->withoutOverlapping();

// Deja el historial de suscripciones al dia (el bloqueo por vencimiento no depende de esto).
Schedule::command('suscripciones:vencer')->dailyAt('00:10');
