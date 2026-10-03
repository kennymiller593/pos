<?php

namespace App\Services;

use App\Exceptions\ErrorDeNegocio;
use App\Jobs\CopiarArchivosANube;
use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\Comprobante;
use App\Models\GuiaRemision;
use App\Models\ProductoPresentacion;
use App\Models\Sucursal;
use App\Models\Transferencia;
use App\Models\Ubigeo;
use App\Models\Usuario;
use App\Services\Sunat\EnviadorGuia;
use App\Services\Sunat\RespuestaSunat;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Barryvdh\Snappy\Facades\SnappyPdf;
use Barryvdh\Snappy\PdfWrapper;
use Carbon\Carbon;
use Greenter\Model\Client\Client;
use Greenter\Model\Company\Company;
use Greenter\Model\Despatch\AdditionalDoc;
use Greenter\Model\Despatch\Despatch;
use Greenter\Model\Despatch\DespatchDetail;
use Greenter\Model\Despatch\Direction;
use Greenter\Model\Despatch\Driver;
use Greenter\Model\Despatch\Shipment;
use Greenter\Model\Despatch\Transportist;
use Greenter\Model\Despatch\Vehicle;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Guías de remisión del remitente: registro, envío a SUNAT (ticket + consulta) y PDF.
 */
class GuiaRemisionService
{
    /** Indicador SUNAT para traslados en vehículos de categoría M1 o L (no exige placa ni conductor). */
    private const INDICADOR_VEHICULO_MENOR = 'SUNAT_Envio_IndicadorTrasladoVehiculoM1L';

    public function __construct(
        private readonly EnviadorGuia $enviador,
        private readonly VentaService $ventas,
    ) {}

    /**
     * Registra la guía con su número. El envío a SUNAT va aparte (enviar()).
     *
     * $datos ya validados por el controlador (ver GuiaRemisionController::reglas()).
     *
     * @throws ErrorDeNegocio
     */
    public function crear(Usuario $usuario, Sucursal $sucursal, array $datos): GuiaRemision
    {
        $empresa = $usuario->empresa;
        $empresaId = $empresa->id;
        $motivo = $datos['motivo_codigo'];

        if (blank($sucursal->direccion) || blank(trim((string) $sucursal->ubigeo))) {
            throw new ErrorDeNegocio("La sucursal \"{$sucursal->nombre}\" no tiene dirección o distrito: complétalos en Sucursales, es el punto de partida de la guía.");
        }

        $partida = [
            'partida_ubigeo' => trim((string) $sucursal->ubigeo),
            'partida_direccion' => $sucursal->direccion,
            'partida_cod_local' => null,
        ];
        $llegada = [
            'llegada_ubigeo' => $datos['llegada_ubigeo'] ?? null,
            'llegada_direccion' => $datos['llegada_direccion'] ?? null,
            'llegada_cod_local' => null,
        ];
        $cliente = null;
        $transferencia = null;

        if ($motivo === '04') {
            // entre locales de la misma empresa: el destinatario es la propia empresa y
            // ambos puntos llevan su código de establecimiento anexo
            $destino = Sucursal::where('empresa_id', $empresaId)->find($datos['sucursal_destino_id'] ?? null);

            if (! $destino || $destino->id === $sucursal->id) {
                throw new ErrorDeNegocio('Elige la sucursal de destino (distinta a la de partida).');
            }
            if (blank($destino->direccion) || blank(trim((string) $destino->ubigeo))) {
                throw new ErrorDeNegocio("La sucursal \"{$destino->nombre}\" no tiene dirección o distrito: complétalos en Sucursales.");
            }

            $destinatario = ['tipo' => '6', 'numero' => $empresa->ruc, 'nombre' => $empresa->razon_social];
            $partida['partida_cod_local'] = $sucursal->codigo_sunat ?: '0000';
            $llegada = [
                'llegada_ubigeo' => trim((string) $destino->ubigeo),
                'llegada_direccion' => $destino->direccion,
                'llegada_cod_local' => $destino->codigo_sunat ?: '0000',
            ];

            if (filled($datos['transferencia_id'] ?? null)) {
                $transferencia = Transferencia::where('empresa_id', $empresaId)->find($datos['transferencia_id']);
            }
        } else {
            $cliente = Cliente::where('empresa_id', $empresaId)->find($datos['cliente_id'] ?? null);

            if (! $cliente) {
                throw new ErrorDeNegocio('Elige el destinatario de la guía.');
            }
            if (blank($cliente->numero_documento) || trim((string) $cliente->tipo_documento_codigo) === '0') {
                throw new ErrorDeNegocio('El destinatario necesita DNI o RUC: SUNAT no acepta guías a clientes sin documento.');
            }
            if (trim((string) $cliente->numero_documento) === $empresa->ruc) {
                throw new ErrorDeNegocio('El destinatario no puede ser tu propia empresa. Para mover mercadería entre tus locales usa el motivo "Traslado entre establecimientos".');
            }
            if (! Ubigeo::whereKey($llegada['llegada_ubigeo'])->exists()) {
                throw new ErrorDeNegocio('Elige el distrito del punto de llegada.');
            }

            $destinatario = [
                'tipo' => trim((string) $cliente->tipo_documento_codigo),
                'numero' => trim((string) $cliente->numero_documento),
                'nombre' => $cliente->nombre,
            ];
        }

        $comprobante = null;
        if (filled($datos['comprobante_id'] ?? null)) {
            $comprobante = Comprobante::where('empresa_id', $empresaId)->find($datos['comprobante_id']);
        }

        $lineas = $this->prepararLineas($empresaId, $datos['items']);
        $transporte = $this->datosDeTransporte($datos);

        return DB::transaction(function () use ($usuario, $empresaId, $sucursal, $datos, $motivo, $cliente, $destinatario, $partida, $llegada, $transporte, $lineas, $comprobante, $transferencia) {
            $serie = $this->ventas->tomarCorrelativo($empresaId, $sucursal->id, null, '09');

            $guia = GuiaRemision::create([
                'empresa_id' => $empresaId,
                'sucursal_id' => $sucursal->id,
                'usuario_id' => $usuario->id,
                'serie' => $serie->serie,
                'correlativo' => $serie->correlativo,
                'fecha_emision' => now()->toDateString(),
                'hora_emision' => now()->toTimeString(),
                'fecha_traslado' => $datos['fecha_traslado'],
                'motivo_codigo' => $motivo,
                'motivo_descripcion' => $motivo === '13' ? ($datos['motivo_descripcion'] ?? null) : null,
                'peso_bruto' => round((float) $datos['peso_bruto'], 3),
                'bultos' => $datos['bultos'] ?? null,
                'cliente_id' => $cliente?->id,
                'destinatario_tipo_doc' => $destinatario['tipo'],
                'destinatario_numero_doc' => $destinatario['numero'],
                'destinatario_nombre' => $destinatario['nombre'],
                ...$partida,
                ...$llegada,
                ...$transporte,
                'comprobante_id' => $comprobante?->id,
                'transferencia_id' => $transferencia?->id,
                'observaciones' => $datos['observaciones'] ?? null,
                'estado' => 'emitida',
                'estado_sunat' => 'pendiente',
            ]);

            foreach ($lineas as $orden => $linea) {
                $guia->detalles()->create(['empresa_id' => $empresaId, 'orden' => $orden, ...$linea]);
            }

            return $guia;
        });
    }

    /** @return list<array<string, mixed>> */
    private function prepararLineas(string $empresaId, array $items): array
    {
        $presentaciones = ProductoPresentacion::query()
            ->where('empresa_id', $empresaId)
            ->whereIn('id', collect($items)->pluck('presentacion_id'))
            ->with('producto:id,nombre,codigo_interno,permite_fraccion,unidad_base_codigo')
            ->get()
            ->keyBy('id');

        $lineas = [];

        foreach ($items as $item) {
            $presentacion = $presentaciones->get($item['presentacion_id']);
            if (! $presentacion) {
                throw new ErrorDeNegocio('Uno de los productos de la guía ya no existe.');
            }

            $producto = $presentacion->producto;
            $cantidad = (float) $item['cantidad'];

            if (! $producto->permite_fraccion && fmod($cantidad, 1) != 0) {
                throw new ErrorDeNegocio("\"{$producto->nombre}\" no permite cantidades fraccionadas.");
            }

            $lineas[] = [
                'producto_id' => $producto->id,
                'presentacion_id' => $presentacion->id,
                'codigo' => $producto->codigo_interno,
                'descripcion' => $presentacion->descripcionConProducto($producto->nombre),
                'unidad_codigo' => trim((string) $presentacion->unidad_codigo) ?: $producto->unidad_base_codigo,
                'cantidad' => $cantidad,
            ];
        }

        return $lineas;
    }

    /** Solo se guardan los datos que corresponden a la modalidad elegida. */
    private function datosDeTransporte(array $datos): array
    {
        $vacio = [
            'transportista_ruc' => null, 'transportista_nombre' => null, 'transportista_mtc' => null,
            'vehiculo_placa' => null, 'conductor_tipo_doc' => null, 'conductor_numero_doc' => null,
            'conductor_nombres' => null, 'conductor_apellidos' => null, 'conductor_licencia' => null,
        ];

        if ($datos['modalidad'] === '01') {
            return [
                ...$vacio,
                'modalidad' => '01',
                'vehiculo_menor' => false,
                'transportista_ruc' => $datos['transportista_ruc'],
                'transportista_nombre' => $datos['transportista_nombre'],
                'transportista_mtc' => $datos['transportista_mtc'] ?? null,
            ];
        }

        $menor = (bool) ($datos['vehiculo_menor'] ?? false);
        $placa = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($datos['vehiculo_placa'] ?? '')));
        $conConductor = filled($datos['conductor_numero_doc'] ?? null);

        return [
            ...$vacio,
            'modalidad' => '02',
            'vehiculo_menor' => $menor,
            'vehiculo_placa' => $placa ?: null,
            ...($conConductor ? [
                'conductor_tipo_doc' => $datos['conductor_tipo_doc'] ?? '1',
                'conductor_numero_doc' => trim((string) $datos['conductor_numero_doc']),
                'conductor_nombres' => $datos['conductor_nombres'] ?? null,
                'conductor_apellidos' => $datos['conductor_apellidos'] ?? null,
                'conductor_licencia' => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($datos['conductor_licencia'] ?? ''))) ?: null,
            ] : []),
        ];
    }

    /**
     * Envía la guía a SUNAT y deja registrado el resultado. Nunca lanza: cualquier
     * falla la deja en "pendiente" con el motivo, para reintentar luego.
     */
    public function enviar(GuiaRemision $guia): GuiaRemision
    {
        $lock = Cache::lock("sunat:guia:{$guia->id}", 120);

        if (! $lock->get()) {
            return $guia;
        }

        try {
            $guia->refresh();

            if ($guia->estado !== 'emitida' || in_array($guia->estado_sunat, ['aceptado', 'observado', 'en_proceso'], true)) {
                return $guia;
            }

            $guia->intentos = (int) $guia->intentos + 1;
            $guia->enviado_en = now();

            try {
                if (! $guia->empresa->facturacion_electronica) {
                    throw new ErrorDeNegocio('Activa la facturación electrónica en Empresa para enviar guías a SUNAT.');
                }

                $respuesta = $this->enviador->enviar($guia->empresa, $this->construirDespatch($guia));
            } catch (\Throwable $e) {
                if (! $e instanceof ErrorDeNegocio) {
                    report($e);
                }

                $guia->estado_sunat = 'pendiente';
                $guia->sunat_mensaje = 'No se pudo enviar: '.$e->getMessage();
                $guia->save();

                return $guia;
            }

            $this->guardarResultado($guia, $respuesta);

            // SUNAT suele resolver el ticket en segundos: una primera consulta evita esperar al cron
            if ($guia->estado_sunat === 'en_proceso') {
                $this->consultar($guia, bloqueada: true);
            }

            return $guia;
        } finally {
            $lock->release();
        }
    }

    /** Consulta el ticket de una guía en proceso y actualiza su estado. */
    public function consultar(GuiaRemision $guia, bool $bloqueada = false): GuiaRemision
    {
        if ($guia->estado_sunat !== 'en_proceso' || blank($guia->sunat_ticket)) {
            return $guia;
        }

        $lock = $bloqueada ? null : Cache::lock("sunat:guia:{$guia->id}", 120);

        if ($lock && ! $lock->get()) {
            return $guia;
        }

        try {
            $respuesta = $this->enviador->consultarTicket($guia->empresa, $guia->sunat_ticket);
            $this->guardarResultado($guia, $respuesta);
        } catch (\Throwable $e) {
            report($e);
            // sigue en proceso: se volvera a consultar
        } finally {
            $lock?->release();
        }

        return $guia;
    }

    private function guardarResultado(GuiaRemision $guia, RespuestaSunat $respuesta): void
    {
        $empresa = $guia->empresa;
        $carpeta = "sunat/{$guia->empresa_id}/{$empresa->entorno_sunat}";
        $nombre = "{$empresa->ruc}-09-{$guia->serie}-{$guia->correlativo}";

        if ($respuesta->xml) {
            Storage::put("{$carpeta}/{$nombre}.xml", $respuesta->xml);
            $guia->xml_url = "{$carpeta}/{$nombre}.xml";
        }

        if ($respuesta->cdrZip) {
            Storage::put("{$carpeta}/R-{$nombre}.zip", $respuesta->cdrZip);
            $guia->cdr_url = "{$carpeta}/R-{$nombre}.zip";
        }

        // copia inmediata a la nube (en segundo plano: si la nube falla, la guia no se entera)
        CopiarArchivosANube::encolar([
            $respuesta->xml ? $guia->xml_url : null,
            $respuesta->cdrZip ? $guia->cdr_url : null,
        ]);

        $guia->estado_sunat = match (true) {
            $respuesta->enProceso => 'en_proceso',
            $respuesta->aceptado && $respuesta->observaciones !== [] => 'observado',
            $respuesta->aceptado => 'aceptado',
            $respuesta->errorComunicacion => 'pendiente',
            default => 'rechazado',
        };

        if ($respuesta->ticket) {
            $guia->sunat_ticket = $respuesta->ticket;
        }
        // un envio fallido por comunicacion invalida el ticket anterior: el reintento genera otro
        if ($guia->estado_sunat === 'pendiente') {
            $guia->sunat_ticket = null;
        }
        if ($respuesta->hash) {
            $guia->hash_cpe = $respuesta->hash;
        }
        if ($respuesta->referencia) {
            $guia->qr_url = $respuesta->referencia;
        }

        $guia->sunat_mensaje = trim("[{$respuesta->codigo}] {$respuesta->mensaje}"
            .($respuesta->observaciones !== [] ? ' | '.implode('; ', $respuesta->observaciones) : ''));
        $guia->save();
    }

    /** Arma el documento UBL (DespatchAdvice 2.1, versión 2022 de SUNAT) desde la guía guardada. */
    public function construirDespatch(GuiaRemision $guia): Despatch
    {
        $guia->loadMissing(['empresa', 'detalles', 'comprobante:id,serie,correlativo,tipo_comprobante_codigo']);
        $empresa = $guia->empresa;

        $emision = Carbon::parse($guia->fecha_emision->format('Y-m-d').' '.($guia->hora_emision ?? '00:00:00'), 'America/Lima');

        $envio = (new Shipment)
            ->setCodTraslado(trim($guia->motivo_codigo))
            ->setModTraslado(trim($guia->modalidad))
            ->setFecTraslado(new \DateTime($guia->fecha_traslado->format('Y-m-d'), new \DateTimeZone('America/Lima')))
            ->setPesoTotal((float) $guia->peso_bruto)
            ->setUndPesoTotal('KGM')
            ->setPartida($this->direccion($guia->partida_ubigeo, $guia->partida_direccion, $guia->partida_cod_local, $empresa->ruc))
            ->setLlegada($this->direccion($guia->llegada_ubigeo, $guia->llegada_direccion, $guia->llegada_cod_local, $empresa->ruc));

        if (trim($guia->motivo_codigo) === '13' && $guia->motivo_descripcion) {
            $envio->setDesTraslado($guia->motivo_descripcion);
        }
        if ($guia->bultos) {
            $envio->setNumBultos((int) $guia->bultos);
        }

        if (trim($guia->modalidad) === '01') {
            $envio->setTransportista((new Transportist)
                ->setTipoDoc('6')
                ->setNumDoc(trim((string) $guia->transportista_ruc))
                ->setRznSocial($guia->transportista_nombre)
                ->setNroMtc($guia->transportista_mtc ?: null));
        } else {
            if ($guia->vehiculo_menor) {
                $envio->setIndicadores([self::INDICADOR_VEHICULO_MENOR]);
            }
            if ($guia->vehiculo_placa) {
                $envio->setVehiculo((new Vehicle)->setPlaca($guia->vehiculo_placa));
            }
            if ($guia->conductor_numero_doc) {
                $envio->setChoferes([(new Driver)
                    ->setTipo('Principal')
                    ->setTipoDoc(trim((string) $guia->conductor_tipo_doc) ?: '1')
                    ->setNroDoc($guia->conductor_numero_doc)
                    ->setNombres($guia->conductor_nombres)
                    ->setApellidos($guia->conductor_apellidos)
                    ->setLicencia($guia->conductor_licencia)]);
            }
        }

        $despatch = (new Despatch)
            ->setVersion('2022')
            ->setTipoDoc('09')
            ->setSerie($guia->serie)
            ->setCorrelativo((string) $guia->correlativo)
            ->setFechaEmision($emision)
            ->setCompany((new Company)
                ->setRuc($empresa->ruc)
                ->setRazonSocial($empresa->razon_social)
                ->setNombreComercial($empresa->nombre_comercial ?: $empresa->razon_social))
            ->setDestinatario((new Client)
                ->setTipoDoc(trim($guia->destinatario_tipo_doc))
                ->setNumDoc($guia->destinatario_numero_doc)
                ->setRznSocial($guia->destinatario_nombre))
            ->setEnvio($envio)
            ->setDetails($guia->detalles->map(fn ($d) => (new DespatchDetail)
                ->setCodigo($d->codigo)
                ->setDescripcion($d->descripcion)
                ->setUnidad(trim((string) $d->unidad_codigo) ?: 'NIU')
                ->setCantidad((float) $d->cantidad))->all());

        if ($guia->observaciones) {
            $despatch->setObservacion($guia->observaciones);
        }

        // la boleta o factura que origina el traslado (catalogo 61)
        $venta = $guia->comprobante;
        if ($venta && in_array($venta->tipo_comprobante_codigo, ['01', '03'], true)) {
            $despatch->setAddDocs([(new AdditionalDoc)
                ->setTipo($venta->tipo_comprobante_codigo)
                ->setTipoDesc($venta->tipo_comprobante_codigo === '01' ? 'Factura' : 'Boleta de Venta')
                ->setNro("{$venta->serie}-{$venta->correlativo}")
                ->setEmisor($empresa->ruc)]);
        }

        return $despatch;
    }

    private function direccion(string $ubigeo, string $direccion, ?string $codLocal, string $ruc): Direction
    {
        $punto = new Direction(trim($ubigeo), $direccion);

        if (filled(trim((string) $codLocal))) {
            $punto->setCodLocal(trim($codLocal))->setRuc($ruc);
        }

        return $punto;
    }

    /**
     * Anulación interna. SUNAT no tiene comunicación de baja por sistema para guías:
     * una guía ya aceptada debe darse de baja también en el portal de SUNAT.
     *
     * @throws ErrorDeNegocio
     */
    public function anular(GuiaRemision $guia, Usuario $usuario, string $motivo): void
    {
        if ($guia->estado === 'anulada') {
            throw new ErrorDeNegocio('La guía ya está anulada.');
        }
        if ($guia->estado_sunat === 'en_proceso') {
            throw new ErrorDeNegocio('SUNAT aún está procesando esta guía. Espera su respuesta antes de anularla.');
        }

        $guia->update([
            'estado' => 'anulada',
            'anulada_en' => now(),
            'anulada_por' => $usuario->id,
            'motivo_anulacion' => $motivo,
        ]);

        Auditoria::registrar($usuario, 'guia.anulada', 'guia_remision', $guia->id, [
            'guia' => $guia->numero(),
            'motivo' => $motivo,
            'estado_sunat' => $guia->estado_sunat,
        ]);
    }

    /** Representación impresa en A4. */
    public function pdf(GuiaRemision $guia): PdfWrapper
    {
        $guia->loadMissing(['empresa', 'detalles', 'sucursal:id,nombre,direccion', 'usuario:id,nombre_completo', 'comprobante:id,serie,correlativo,tipo_comprobante_codigo']);

        $ubigeos = Ubigeo::whereIn('codigo', [$guia->partida_ubigeo, $guia->llegada_ubigeo])->get()->keyBy(fn ($u) => trim($u->codigo));
        $lugar = fn (string $codigo) => ($u = $ubigeos->get(trim($codigo)))
            ? mb_convert_case("{$u->distrito}, {$u->provincia}, {$u->departamento}", MB_CASE_TITLE)
            : $codigo;

        return SnappyPdf::loadView('pdf.guia-remision', [
            'guia' => $guia,
            'empresa' => $guia->empresa,
            'logo' => $guia->empresa->logoParaPdf(),
            'partidaLugar' => $lugar($guia->partida_ubigeo),
            'llegadaLugar' => $lugar($guia->llegada_ubigeo),
            'qr' => $this->qr($guia),
        ])
            ->setOption('page-size', 'A4')
            ->setOption('margin-top', '12')
            ->setOption('margin-bottom', '12')
            ->setOption('margin-left', '14')
            ->setOption('margin-right', '14')
            ->setOption('encoding', 'utf-8');
    }

    /** QR con el enlace de consulta que SUNAT entrega al aceptar la guía. */
    private function qr(GuiaRemision $guia): ?string
    {
        if (blank($guia->qr_url)) {
            return null;
        }

        $svg = (new Writer(new ImageRenderer(new RendererStyle(300, 1), new SvgImageBackEnd)))->writeString($guia->qr_url);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
