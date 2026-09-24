--
-- PostgreSQL database dump
--

\restrict ac0FhPHBu8AiVLaP1G3dGDUVRHNeOYjRc1Kh4ynmoJpcN0bCvaCYNhm0447hYvg

-- Dumped from database version 17.6
-- Dumped by pg_dump version 17.6

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: pg_trgm; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA public;


--
-- Name: EXTENSION pg_trgm; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION pg_trgm IS 'text similarity measurement and index searching based on trigrams';


--
-- Name: pgcrypto; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA public;


--
-- Name: EXTENSION pgcrypto; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION pgcrypto IS 'cryptographic functions';


--
-- Name: set_actualizado_en(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.set_actualizado_en() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    NEW.actualizado_en := now();
    RETURN NEW;
END;
$$;


--
-- Name: uuid_v7(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.uuid_v7() RETURNS uuid
    LANGUAGE plpgsql
    AS $$
DECLARE
    ts_millis BIGINT := (EXTRACT(EPOCH FROM clock_timestamp()) * 1000)::BIGINT;
    rand_bytes BYTEA := gen_random_bytes(10);
    uuid_bytes BYTEA;
BEGIN
    uuid_bytes :=
        substring(int8send(ts_millis) FROM 3 FOR 6) ||    -- 48 bits timestamp
        rand_bytes;                                        -- 80 bits aleatorios
    -- version 7
    uuid_bytes := set_byte(uuid_bytes, 6, (b'0111' || get_byte(uuid_bytes, 6)::bit(4))::bit(8)::int);
    -- variant 10xx
    uuid_bytes := set_byte(uuid_bytes, 8, (b'10' || get_byte(uuid_bytes, 8)::bit(6))::bit(8)::int);
    RETURN encode(uuid_bytes, 'hex')::uuid;
END;
$$;


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: aperturas_caja; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.aperturas_caja (
    id uuid DEFAULT public.uuid_v7() NOT NULL,
    empresa_id uuid NOT NULL,
    caja_id uuid NOT NULL,
    usuario_id uuid NOT NULL,
    monto_inicial numeric(12,2) DEFAULT 0 NOT NULL,
    monto_cierre numeric(12,2),
    monto_sistema numeric(12,2),
    abierta_en timestamp with time zone DEFAULT now() NOT NULL,
    cerrada_en timestamp with time zone
);


--
-- Name: auditoria; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.auditoria (
    id bigint NOT NULL,
    empresa_id uuid NOT NULL,
    usuario_id uuid,
    accion character varying(50) NOT NULL,
    entidad character varying(50) NOT NULL,
    entidad_id uuid,
    detalle jsonb,
    creado_en timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: auditoria_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.auditoria ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.auditoria_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: cajas; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cajas (
    id uuid DEFAULT public.uuid_v7() NOT NULL,
    empresa_id uuid NOT NULL,
    sucursal_id uuid NOT NULL,
    nombre character varying(50) NOT NULL,
    activo boolean DEFAULT true NOT NULL,
    ancho_ticket smallint DEFAULT 80 NOT NULL,
    CONSTRAINT cajas_ancho_ticket_check CHECK ((ancho_ticket = ANY (ARRAY[58, 80])))
);


--
-- Name: capas_costo; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.capas_costo (
    id uuid DEFAULT public.uuid_v7() NOT NULL,
    empresa_id uuid NOT NULL,
    producto_id uuid NOT NULL,
    sucursal_id uuid NOT NULL,
    lote_id uuid,
    compra_detalle_id uuid,
    cantidad_inicial numeric(12,3) NOT NULL,
    cantidad_restante numeric(12,3) NOT NULL,
    costo_unitario numeric(14,6) NOT NULL,
    fecha_ingreso timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT capas_costo_check CHECK (((cantidad_restante >= (0)::numeric) AND (cantidad_restante <= cantidad_inicial)))
);


--
-- Name: categorias; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.categorias (
    id uuid DEFAULT public.uuid_v7() NOT NULL,
    empresa_id uuid NOT NULL,
    padre_id uuid,
    nombre character varying(100) NOT NULL
);


--
-- Name: clientes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.clientes (
    id uuid DEFAULT public.uuid_v7() NOT NULL,
    empresa_id uuid NOT NULL,
    tipo_documento_codigo character(1) DEFAULT '1'::bpchar NOT NULL,
    numero_documento character varying(15),
    nombre character varying(200) NOT NULL,
    direccion character varying(250),
    telefono character varying(20),
    email character varying(150),
    limite_credito numeric(12,2) DEFAULT 0 NOT NULL,
    creado_en timestamp with time zone DEFAULT now() NOT NULL,
    actualizado_en timestamp with time zone DEFAULT now() NOT NULL,
    eliminado_en timestamp with time zone
);


--
-- Name: cobros; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cobros (
    id uuid DEFAULT public.uuid_v7() NOT NULL,
    empresa_id uuid NOT NULL,
    cuenta_id uuid NOT NULL,
    apertura_id uuid NOT NULL,
    usuario_id uuid NOT NULL,
    medio_pago_codigo character varying(20) NOT NULL,
    monto numeric(12,2) NOT NULL,
    referencia character varying(100),
    creado_en timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT cobros_monto_check CHECK ((monto > (0)::numeric))
);


--
-- Name: compra_detalles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.compra_detalles (
    id uuid DEFAULT public.uuid_v7() NOT NULL,
    empresa_id uuid NOT NULL,
    compra_id uuid NOT NULL,
    producto_id uuid NOT NULL,
    presentacion_id uuid,
    lote_id uuid,
    cantidad numeric(12,3) NOT NULL,
    costo_unitario numeric(14,6) NOT NULL,
    total numeric(12,2) NOT NULL,
    CONSTRAINT compra_detalles_cantidad_check CHECK ((cantidad > (0)::numeric))
);


--
-- Name: compras; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.compras (
    id uuid DEFAULT public.uuid_v7() NOT NULL,
    empresa_id uuid NOT NULL,
    sucursal_id uuid NOT NULL,
    proveedor_id uuid,
    usuario_id uuid,
    tipo_comprobante_codigo character(2),
    serie_numero character varying(20),
    fecha date NOT NULL,
    total numeric(12,2) NOT NULL,
    es_credito boolean DEFAULT false NOT NULL,
    creado_en timestamp with time zone DEFAULT now() NOT NULL,
    estado character varying(20) DEFAULT 'registrada'::character varying NOT NULL,
    anulada_en timestamp with time zone,
    anulada_por uuid,
    motivo_anulacion character varying(250),
    CONSTRAINT compras_estado_check CHECK (((estado)::text = ANY ((ARRAY['registrada'::character varying, 'anulada'::character varying])::text[])))
);


--
-- Name: comprobante_detalles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.comprobante_detalles (
    id uuid DEFAULT public.uuid_v7() NOT NULL,
    empresa_id uuid NOT NULL,
    comprobante_id uuid NOT NULL,
    producto_id uuid NOT NULL,
    presentacion_id uuid,
    lote_id uuid,
    descripcion character varying(250) NOT NULL,
    unidad_codigo character varying(5) NOT NULL,
    tipo_afectacion_codigo character(2) NOT NULL,
    cantidad numeric(12,3) NOT NULL,
    valor_unitario numeric(14,6) NOT NULL,
    precio_unitario numeric(14,6) NOT NULL,
    costo_unitario numeric(14,6) DEFAULT 0 NOT NULL,
    descuento numeric(12,2) DEFAULT 0 NOT NULL,
    igv numeric(12,2) DEFAULT 0 NOT NULL,
    total numeric(12,2) NOT NULL,
    CONSTRAINT comprobante_detalles_cantidad_check CHECK ((cantidad > (0)::numeric))
);


--
-- Name: comprobantes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.comprobantes (
    id uuid DEFAULT public.uuid_v7() NOT NULL,
    empresa_id uuid NOT NULL,
    sucursal_id uuid NOT NULL,
    caja_id uuid,
    apertura_id uuid,
    cliente_id uuid,
    usuario_id uuid NOT NULL,
    tipo_comprobante_codigo character(2) NOT NULL,
    serie character varying(4) NOT NULL,
    correlativo integer NOT NULL,
    fecha_emision date NOT NULL,
    hora_emision time without time zone DEFAULT CURRENT_TIME NOT NULL,
    moneda character(3) DEFAULT 'PEN'::bpchar NOT NULL,
    tipo_cambio numeric(8,4) DEFAULT 1 NOT NULL,
    cliente_tipo_doc character(1),
    cliente_numero_doc character varying(15),
    cliente_nombre character varying(200),
    cliente_direccion character varying(250),
    total_gravado numeric(12,2) DEFAULT 0 NOT NULL,
    total_exonerado numeric(12,2) DEFAULT 0 NOT NULL,
    total_inafecto numeric(12,2) DEFAULT 0 NOT NULL,
    total_igv numeric(12,2) DEFAULT 0 NOT NULL,
    total_icbper numeric(12,2) DEFAULT 0 NOT NULL,
    total_descuentos numeric(12,2) DEFAULT 0 NOT NULL,
    total numeric(12,2) NOT NULL,
    comprobante_ref_id uuid,
    motivo_nota character(2),
    es_credito boolean DEFAULT false NOT NULL,
    fecha_vencimiento date,
    estado character varying(20) DEFAULT 'emitido'::character varying NOT NULL,
    anulado_en timestamp with time zone,
    anulado_por uuid,
    motivo_anulacion character varying(250),
    origen character varying(10) DEFAULT 'online'::character varying NOT NULL,
    creado_en timestamp with time zone DEFAULT now() NOT NULL,
    hash_cpe character varying(60),
    estado_sunat character varying(20),
    sunat_ticket character varying(60),
    sunat_respuesta jsonb,
    CONSTRAINT comprobantes_estado_check CHECK (((estado)::text = ANY ((ARRAY['emitido'::character varying, 'anulado'::character varying])::text[]))),
    CONSTRAINT comprobantes_estado_sunat_check CHECK (((estado_sunat IS NULL) OR ((estado_sunat)::text = ANY ((ARRAY['pendiente'::character varying, 'aceptado'::character varying, 'rechazado'::character varying, 'observado'::character varying])::text[])))),
    CONSTRAINT comprobantes_origen_check CHECK (((origen)::text = ANY ((ARRAY['online'::character varying, 'offline'::character varying])::text[])))
);


--
-- Name: comprobantes_sunat; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.comprobantes_sunat (
    comprobante_id uuid NOT NULL,
    estado character varying(20) DEFAULT 'pendiente'::character varying NOT NULL,
    hash_cpe character varying(100),
    xml_url text,
    cdr_url text,
    pdf_url text,
    ticket character varying(50),
    mensaje_sunat text,
    intentos smallint DEFAULT 0 NOT NULL,
    enviado_en timestamp with time zone,
    actualizado_en timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT comprobantes_sunat_estado_check CHECK (((estado)::text = ANY ((ARRAY['pendiente'::character varying, 'aceptado'::character varying, 'rechazado'::character varying, 'observado'::character varying, 'baja_pendiente'::character varying, 'baja'::character varying])::text[])))
);


--
-- Name: cuentas_por_cobrar; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cuentas_por_cobrar (
    id uuid DEFAULT public.uuid_v7() NOT NULL,
    empresa_id uuid NOT NULL,
    comprobante_id uuid NOT NULL,
    cliente_id uuid NOT NULL,
    monto_total numeric(12,2) NOT NULL,
    monto_pagado numeric(12,2) DEFAULT 0 NOT NULL,
    estado character varying(20) DEFAULT 'pendiente'::character varying NOT NULL,
    CONSTRAINT cuentas_por_cobrar_check CHECK ((monto_pagado <= monto_total)),
    CONSTRAINT cuentas_por_cobrar_estado_check CHECK (((estado)::text = ANY ((ARRAY['pendiente'::character varying, 'parcial'::character varying, 'pagado'::character varying])::text[])))
);


--
-- Name: cuentas_por_pagar; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cuentas_por_pagar (
    id uuid DEFAULT public.uuid_v7() NOT NULL,
    empresa_id uuid NOT NULL,
    compra_id uuid NOT NULL,
    proveedor_id uuid NOT NULL,
    monto_total numeric(12,2) NOT NULL,
    monto_pagado numeric(12,2) DEFAULT 0 NOT NULL,
    fecha_vencimiento date,
    estado character varying(20) DEFAULT 'pendiente'::character varying NOT NULL,
    CONSTRAINT cuentas_por_pagar_check CHECK ((monto_pagado <= monto_total)),
    CONSTRAINT cuentas_por_pagar_estado_check CHECK (((estado)::text = ANY ((ARRAY['pendiente'::character varying, 'parcial'::character varying, 'pagado'::character varying])::text[])))
);


--
-- Name: detalle_consumo_capas; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.detalle_consumo_capas (
    id uuid DEFAULT public.uuid_v7() NOT NULL,
    detalle_id uuid NOT NULL,
    capa_id uuid NOT NULL,
    cantidad numeric(12,3) NOT NULL,
    costo_unitario numeric(14,6) NOT NULL,
    CONSTRAINT detalle_consumo_capas_cantidad_check CHECK ((cantidad > (0)::numeric))
);


--
-- Name: empresas; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.empresas (
    id uuid DEFAULT public.uuid_v7() NOT NULL,
    ruc character(11) NOT NULL,
    razon_social character varying(200) NOT NULL,
    nombre_comercial character varying(200),
    regimen_tributario character varying(30) DEFAULT 'RUS'::character varying NOT NULL,
    rubro_codigo character varying(20) NOT NULL,
    logo_url text,
    usuario_sol character varying(50),
    certificado_digital text,
    activo boolean DEFAULT true NOT NULL,
    creado_en timestamp with time zone DEFAULT now() NOT NULL,
    actualizado_en timestamp with time zone DEFAULT now() NOT NULL,
    facturacion_electronica boolean DEFAULT false NOT NULL,
    clave_sol text,
    clave_certificado text,
    entorno_sunat character varying(10) DEFAULT 'beta'::character varying NOT NULL,
    CONSTRAINT empresas_entorno_sunat_check CHECK (((entorno_sunat)::text = ANY ((ARRAY['beta'::character varying, 'produccion'::character varying])::text[]))),
    CONSTRAINT empresas_regimen_tributario_check CHECK (((regimen_tributario)::text = ANY ((ARRAY['RUS'::character varying, 'RER'::character varying, 'MYPE'::character varying, 'GENERAL'::character varying])::text[]))),
    CONSTRAINT empresas_ruc_check CHECK ((ruc ~ '^[0-9]{11}$'::text))
);


--
-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.failed_jobs (
    id bigint NOT NULL,
    uuid character varying(255) NOT NULL,
    connection text NOT NULL,
    queue text NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;


--
-- Name: job_batches; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.job_batches (
    id character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    total_jobs integer NOT NULL,
    pending_jobs integer NOT NULL,
    failed_jobs integer NOT NULL,
    failed_job_ids text NOT NULL,
    options text,
    cancelled_at integer,
    created_at integer NOT NULL,
    finished_at integer
);


--
-- Name: jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.jobs (
    id bigint NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    attempts smallint NOT NULL,
    reserved_at integer,
    available_at integer NOT NULL,
    created_at integer NOT NULL
);


--
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.jobs_id_seq OWNED BY public.jobs.id;


--
-- Name: lotes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.lotes (
    id uuid DEFAULT public.uuid_v7() NOT NULL,
    empresa_id uuid NOT NULL,
    producto_id uuid NOT NULL,
    sucursal_id uuid NOT NULL,
    numero_lote character varying(50) NOT NULL,
    fecha_vencimiento date
);


--
-- Name: marcas; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.marcas (
    id uuid DEFAULT public.uuid_v7() NOT NULL,
    empresa_id uuid NOT NULL,
    nombre character varying(100) NOT NULL
);


--
-- Name: medios_pago; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.medios_pago (
    codigo character varying(20) NOT NULL,
    nombre character varying(80) NOT NULL,
    requiere_referencia boolean DEFAULT false NOT NULL
);


--
-- Name: movimientos_caja; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.movimientos_caja (
    id uuid DEFAULT public.uuid_v7() NOT NULL,
    empresa_id uuid NOT NULL,
    apertura_id uuid NOT NULL,
    tipo character varying(20) NOT NULL,
    concepto character varying(200) NOT NULL,
    monto numeric(12,2) NOT NULL,
    usuario_id uuid,
    creado_en timestamp with time zone DEFAULT now() NOT NULL,
    medio_pago_codigo character varying(20) DEFAULT 'efectivo'::character varying NOT NULL,
    referencia character varying(100),
    CONSTRAINT movimientos_caja_monto_check CHECK ((monto > (0)::numeric)),
    CONSTRAINT movimientos_caja_tipo_check CHECK (((tipo)::text = ANY ((ARRAY['ingreso'::character varying, 'egreso'::character varying])::text[])))
);


--
-- Name: movimientos_inventario; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.movimientos_inventario (
    id uuid DEFAULT public.uuid_v7() NOT NULL,
    empresa_id uuid NOT NULL,
    sucursal_id uuid NOT NULL,
    producto_id uuid NOT NULL,
    lote_id uuid,
    tipo character varying(30) NOT NULL,
    cantidad numeric(12,3) NOT NULL,
    costo_unitario numeric(14,6),
    referencia_id uuid,
    usuario_id uuid,
    creado_en timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT movimientos_inventario_tipo_check CHECK (((tipo)::text = ANY ((ARRAY['venta'::character varying, 'compra'::character varying, 'ajuste'::character varying, 'merma'::character varying, 'transferencia_salida'::character varying, 'transferencia_entrada'::character varying, 'devolucion'::character varying, 'compra_anulada'::character varying])::text[])))
);


--
-- Name: pagos; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pagos (
    id uuid DEFAULT public.uuid_v7() NOT NULL,
    empresa_id uuid NOT NULL,
    comprobante_id uuid NOT NULL,
    apertura_id uuid,
    usuario_id uuid,
    medio_pago_codigo character varying(20) NOT NULL,
    monto numeric(12,2) NOT NULL,
    referencia character varying(100),
    creado_en timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT pagos_monto_check CHECK ((monto > (0)::numeric))
);


--
-- Name: pagos_proveedor; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pagos_proveedor (
    id uuid DEFAULT public.uuid_v7() NOT NULL,
    empresa_id uuid NOT NULL,
    cuenta_id uuid NOT NULL,
    apertura_id uuid,
    usuario_id uuid NOT NULL,
    medio_pago_codigo character varying(20) NOT NULL,
    monto numeric(12,2) NOT NULL,
    referencia character varying(100),
    creado_en timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT pagos_proveedor_monto_check CHECK ((monto > (0)::numeric))
);


--
-- Name: planes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.planes (
    id uuid DEFAULT public.uuid_v7() NOT NULL,
    nombre character varying(50) NOT NULL,
    precio_mensual numeric(10,2) NOT NULL,
    max_sucursales integer DEFAULT 1 NOT NULL,
    max_usuarios integer DEFAULT 3 NOT NULL,
    max_comprobantes_mes integer,
    codigo character varying(30),
    descripcion character varying(250),
    activo boolean DEFAULT true NOT NULL,
    orden smallint DEFAULT 0 NOT NULL
);


--
-- Name: precios_sucursal; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.precios_sucursal (
    id uuid DEFAULT public.uuid_v7() NOT NULL,
    empresa_id uuid NOT NULL,
    presentacion_id uuid NOT NULL,
    sucursal_id uuid NOT NULL,
    precio_venta numeric(12,4) NOT NULL,
    precio_mayorista numeric(12,4)
);


--
-- Name: producto_presentaciones; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.producto_presentaciones (
    id uuid DEFAULT public.uuid_v7() NOT NULL,
    empresa_id uuid NOT NULL,
    producto_id uuid NOT NULL,
    nombre character varying(80) NOT NULL,
    unidad_codigo character varying(5) DEFAULT 'NIU'::character varying NOT NULL,
    factor_conversion numeric(12,4) DEFAULT 1 NOT NULL,
    codigo_barras character varying(50),
    precio_venta numeric(12,4) NOT NULL,
    precio_mayorista numeric(12,4),
    cantidad_mayorista numeric(12,3),
    es_default boolean DEFAULT false NOT NULL,
    activo boolean DEFAULT true NOT NULL,
    CONSTRAINT producto_presentaciones_factor_conversion_check CHECK ((factor_conversion > (0)::numeric))
);


--
-- Name: productos; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.productos (
    id uuid DEFAULT public.uuid_v7() NOT NULL,
    empresa_id uuid NOT NULL,
    categoria_id uuid,
    marca_id uuid,
    codigo_interno character varying(50) NOT NULL,
    nombre character varying(200) NOT NULL,
    unidad_base_codigo character varying(5) DEFAULT 'NIU'::character varying NOT NULL,
    tipo_afectacion_codigo character(2) DEFAULT '10'::bpchar NOT NULL,
    permite_fraccion boolean DEFAULT false NOT NULL,
    controla_lote boolean DEFAULT false NOT NULL,
    controla_stock boolean DEFAULT true NOT NULL,
    stock_minimo numeric(12,3) DEFAULT 0 NOT NULL,
    atributos jsonb,
    activo boolean DEFAULT true NOT NULL,
    creado_en timestamp with time zone DEFAULT now() NOT NULL,
    actualizado_en timestamp with time zone DEFAULT now() NOT NULL,
    eliminado_en timestamp with time zone,
    imagen_url text
);


--
-- Name: proveedores; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.proveedores (
    id uuid DEFAULT public.uuid_v7() NOT NULL,
    empresa_id uuid NOT NULL,
    ruc character(11),
    razon_social character varying(200) NOT NULL,
    contacto character varying(150),
    telefono character varying(20),
    eliminado_en timestamp with time zone
);


--
-- Name: recuperaciones_password; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.recuperaciones_password (
    email character varying(150) NOT NULL,
    token text NOT NULL,
    creado_en timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: roles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.roles (
    id smallint NOT NULL,
    codigo character varying(30) NOT NULL,
    nombre character varying(80) NOT NULL
);


--
-- Name: roles_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.roles ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.roles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: rubros; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.rubros (
    codigo character varying(20) NOT NULL,
    nombre character varying(80) NOT NULL
);


--
-- Name: series_correlativos; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.series_correlativos (
    id uuid DEFAULT public.uuid_v7() NOT NULL,
    empresa_id uuid NOT NULL,
    sucursal_id uuid NOT NULL,
    caja_id uuid,
    tipo_comprobante_codigo character(2) NOT NULL,
    serie character varying(4) NOT NULL,
    correlativo integer DEFAULT 0 NOT NULL
);


--
-- Name: stock; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.stock (
    id uuid DEFAULT public.uuid_v7() NOT NULL,
    empresa_id uuid NOT NULL,
    producto_id uuid NOT NULL,
    sucursal_id uuid NOT NULL,
    cantidad numeric(12,3) DEFAULT 0 NOT NULL,
    actualizado_en timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: sucursales; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sucursales (
    id uuid DEFAULT public.uuid_v7() NOT NULL,
    empresa_id uuid NOT NULL,
    codigo_sunat character(4) DEFAULT '0000'::bpchar NOT NULL,
    nombre character varying(100) NOT NULL,
    direccion character varying(250),
    ubigeo character(6),
    telefono character varying(20),
    activo boolean DEFAULT true NOT NULL
);


--
-- Name: suscripciones; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.suscripciones (
    id uuid DEFAULT public.uuid_v7() NOT NULL,
    empresa_id uuid NOT NULL,
    plan_id uuid NOT NULL,
    fecha_inicio date NOT NULL,
    fecha_fin date NOT NULL,
    estado character varying(20) DEFAULT 'activa'::character varying NOT NULL,
    es_prueba boolean DEFAULT false NOT NULL,
    creado_en timestamp with time zone DEFAULT now() NOT NULL,
    nota character varying(250),
    CONSTRAINT suscripciones_check CHECK ((fecha_fin > fecha_inicio)),
    CONSTRAINT suscripciones_estado_check CHECK (((estado)::text = ANY ((ARRAY['activa'::character varying, 'vencida'::character varying, 'suspendida'::character varying])::text[])))
);


--
-- Name: tipos_afectacion_igv; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tipos_afectacion_igv (
    codigo character(2) NOT NULL,
    nombre character varying(120) NOT NULL,
    afecto boolean NOT NULL
);


--
-- Name: tipos_cambio; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tipos_cambio (
    fecha date NOT NULL,
    compra numeric(8,4) NOT NULL,
    venta numeric(8,4) NOT NULL
);


--
-- Name: tipos_comprobante; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tipos_comprobante (
    codigo character(2) NOT NULL,
    nombre character varying(80) NOT NULL,
    es_electronico boolean DEFAULT true NOT NULL
);


--
-- Name: tipos_documento_identidad; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tipos_documento_identidad (
    codigo character(1) NOT NULL,
    nombre character varying(80) NOT NULL,
    longitud smallint
);


--
-- Name: transferencia_detalles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.transferencia_detalles (
    id uuid DEFAULT public.uuid_v7() NOT NULL,
    transferencia_id uuid NOT NULL,
    producto_id uuid NOT NULL,
    lote_id uuid,
    cantidad numeric(12,3) NOT NULL,
    CONSTRAINT transferencia_detalles_cantidad_check CHECK ((cantidad > (0)::numeric))
);


--
-- Name: transferencias; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.transferencias (
    id uuid DEFAULT public.uuid_v7() NOT NULL,
    empresa_id uuid NOT NULL,
    sucursal_origen_id uuid NOT NULL,
    sucursal_destino_id uuid NOT NULL,
    usuario_id uuid NOT NULL,
    estado character varying(20) DEFAULT 'pendiente'::character varying NOT NULL,
    observacion character varying(250),
    creado_en timestamp with time zone DEFAULT now() NOT NULL,
    recibida_en timestamp with time zone,
    CONSTRAINT transferencias_check CHECK ((sucursal_origen_id <> sucursal_destino_id)),
    CONSTRAINT transferencias_estado_check CHECK (((estado)::text = ANY ((ARRAY['pendiente'::character varying, 'en_transito'::character varying, 'recibida'::character varying, 'anulada'::character varying])::text[])))
);


--
-- Name: ubigeos; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ubigeos (
    codigo character(6) NOT NULL,
    departamento character varying(60) NOT NULL,
    provincia character varying(60) NOT NULL,
    distrito character varying(60) NOT NULL
);


--
-- Name: unidades_medida; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.unidades_medida (
    codigo character varying(5) NOT NULL,
    nombre character varying(80) NOT NULL,
    permite_decimales boolean DEFAULT false NOT NULL
);


--
-- Name: usuario_sucursales; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.usuario_sucursales (
    usuario_id uuid NOT NULL,
    sucursal_id uuid NOT NULL,
    creado_en timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: usuarios; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.usuarios (
    id uuid DEFAULT public.uuid_v7() NOT NULL,
    empresa_id uuid NOT NULL,
    sucursal_id uuid,
    rol_id smallint NOT NULL,
    email character varying(150) NOT NULL,
    password_hash text NOT NULL,
    nombre_completo character varying(150) NOT NULL,
    activo boolean DEFAULT true NOT NULL,
    creado_en timestamp with time zone DEFAULT now() NOT NULL,
    actualizado_en timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- Name: jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);


--
-- Name: aperturas_caja aperturas_caja_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.aperturas_caja
    ADD CONSTRAINT aperturas_caja_pkey PRIMARY KEY (id);


--
-- Name: auditoria auditoria_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auditoria
    ADD CONSTRAINT auditoria_pkey PRIMARY KEY (id);


--
-- Name: cajas cajas_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cajas
    ADD CONSTRAINT cajas_pkey PRIMARY KEY (id);


--
-- Name: capas_costo capas_costo_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.capas_costo
    ADD CONSTRAINT capas_costo_pkey PRIMARY KEY (id);


--
-- Name: categorias categorias_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.categorias
    ADD CONSTRAINT categorias_pkey PRIMARY KEY (id);


--
-- Name: clientes clientes_empresa_id_tipo_documento_codigo_numero_documento_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.clientes
    ADD CONSTRAINT clientes_empresa_id_tipo_documento_codigo_numero_documento_key UNIQUE (empresa_id, tipo_documento_codigo, numero_documento);


--
-- Name: clientes clientes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.clientes
    ADD CONSTRAINT clientes_pkey PRIMARY KEY (id);


--
-- Name: cobros cobros_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cobros
    ADD CONSTRAINT cobros_pkey PRIMARY KEY (id);


--
-- Name: compra_detalles compra_detalles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.compra_detalles
    ADD CONSTRAINT compra_detalles_pkey PRIMARY KEY (id);


--
-- Name: compras compras_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.compras
    ADD CONSTRAINT compras_pkey PRIMARY KEY (id);


--
-- Name: comprobante_detalles comprobante_detalles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comprobante_detalles
    ADD CONSTRAINT comprobante_detalles_pkey PRIMARY KEY (id);


--
-- Name: comprobantes comprobantes_empresa_id_tipo_comprobante_codigo_serie_corre_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comprobantes
    ADD CONSTRAINT comprobantes_empresa_id_tipo_comprobante_codigo_serie_corre_key UNIQUE (empresa_id, tipo_comprobante_codigo, serie, correlativo);


--
-- Name: comprobantes comprobantes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comprobantes
    ADD CONSTRAINT comprobantes_pkey PRIMARY KEY (id);


--
-- Name: comprobantes_sunat comprobantes_sunat_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comprobantes_sunat
    ADD CONSTRAINT comprobantes_sunat_pkey PRIMARY KEY (comprobante_id);


--
-- Name: cuentas_por_cobrar cuentas_por_cobrar_comprobante_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cuentas_por_cobrar
    ADD CONSTRAINT cuentas_por_cobrar_comprobante_id_key UNIQUE (comprobante_id);


--
-- Name: cuentas_por_cobrar cuentas_por_cobrar_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cuentas_por_cobrar
    ADD CONSTRAINT cuentas_por_cobrar_pkey PRIMARY KEY (id);


--
-- Name: cuentas_por_pagar cuentas_por_pagar_compra_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cuentas_por_pagar
    ADD CONSTRAINT cuentas_por_pagar_compra_id_key UNIQUE (compra_id);


--
-- Name: cuentas_por_pagar cuentas_por_pagar_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cuentas_por_pagar
    ADD CONSTRAINT cuentas_por_pagar_pkey PRIMARY KEY (id);


--
-- Name: detalle_consumo_capas detalle_consumo_capas_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.detalle_consumo_capas
    ADD CONSTRAINT detalle_consumo_capas_pkey PRIMARY KEY (id);


--
-- Name: empresas empresas_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.empresas
    ADD CONSTRAINT empresas_pkey PRIMARY KEY (id);


--
-- Name: empresas empresas_ruc_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.empresas
    ADD CONSTRAINT empresas_ruc_key UNIQUE (ruc);


--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_uuid_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_key UNIQUE (uuid);


--
-- Name: job_batches job_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.job_batches
    ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);


--
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- Name: lotes lotes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lotes
    ADD CONSTRAINT lotes_pkey PRIMARY KEY (id);


--
-- Name: marcas marcas_empresa_id_nombre_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.marcas
    ADD CONSTRAINT marcas_empresa_id_nombre_key UNIQUE (empresa_id, nombre);


--
-- Name: marcas marcas_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.marcas
    ADD CONSTRAINT marcas_pkey PRIMARY KEY (id);


--
-- Name: medios_pago medios_pago_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.medios_pago
    ADD CONSTRAINT medios_pago_pkey PRIMARY KEY (codigo);


--
-- Name: movimientos_caja movimientos_caja_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.movimientos_caja
    ADD CONSTRAINT movimientos_caja_pkey PRIMARY KEY (id);


--
-- Name: movimientos_inventario movimientos_inventario_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.movimientos_inventario
    ADD CONSTRAINT movimientos_inventario_pkey PRIMARY KEY (id);


--
-- Name: pagos pagos_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pagos
    ADD CONSTRAINT pagos_pkey PRIMARY KEY (id);


--
-- Name: pagos_proveedor pagos_proveedor_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pagos_proveedor
    ADD CONSTRAINT pagos_proveedor_pkey PRIMARY KEY (id);


--
-- Name: planes planes_nombre_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.planes
    ADD CONSTRAINT planes_nombre_key UNIQUE (nombre);


--
-- Name: planes planes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.planes
    ADD CONSTRAINT planes_pkey PRIMARY KEY (id);


--
-- Name: precios_sucursal precios_sucursal_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.precios_sucursal
    ADD CONSTRAINT precios_sucursal_pkey PRIMARY KEY (id);


--
-- Name: precios_sucursal precios_sucursal_presentacion_id_sucursal_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.precios_sucursal
    ADD CONSTRAINT precios_sucursal_presentacion_id_sucursal_id_key UNIQUE (presentacion_id, sucursal_id);


--
-- Name: producto_presentaciones producto_presentaciones_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.producto_presentaciones
    ADD CONSTRAINT producto_presentaciones_pkey PRIMARY KEY (id);


--
-- Name: producto_presentaciones producto_presentaciones_producto_id_nombre_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.producto_presentaciones
    ADD CONSTRAINT producto_presentaciones_producto_id_nombre_key UNIQUE (producto_id, nombre);


--
-- Name: productos productos_empresa_id_codigo_interno_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.productos
    ADD CONSTRAINT productos_empresa_id_codigo_interno_key UNIQUE (empresa_id, codigo_interno);


--
-- Name: productos productos_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.productos
    ADD CONSTRAINT productos_pkey PRIMARY KEY (id);


--
-- Name: proveedores proveedores_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.proveedores
    ADD CONSTRAINT proveedores_pkey PRIMARY KEY (id);


--
-- Name: recuperaciones_password recuperaciones_password_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recuperaciones_password
    ADD CONSTRAINT recuperaciones_password_pkey PRIMARY KEY (email);


--
-- Name: roles roles_codigo_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_codigo_key UNIQUE (codigo);


--
-- Name: roles roles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_pkey PRIMARY KEY (id);


--
-- Name: rubros rubros_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rubros
    ADD CONSTRAINT rubros_pkey PRIMARY KEY (codigo);


--
-- Name: series_correlativos series_correlativos_empresa_id_tipo_comprobante_codigo_seri_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.series_correlativos
    ADD CONSTRAINT series_correlativos_empresa_id_tipo_comprobante_codigo_seri_key UNIQUE (empresa_id, tipo_comprobante_codigo, serie);


--
-- Name: series_correlativos series_correlativos_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.series_correlativos
    ADD CONSTRAINT series_correlativos_pkey PRIMARY KEY (id);


--
-- Name: stock stock_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock
    ADD CONSTRAINT stock_pkey PRIMARY KEY (id);


--
-- Name: stock stock_producto_id_sucursal_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock
    ADD CONSTRAINT stock_producto_id_sucursal_id_key UNIQUE (producto_id, sucursal_id);


--
-- Name: sucursales sucursales_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sucursales
    ADD CONSTRAINT sucursales_pkey PRIMARY KEY (id);


--
-- Name: suscripciones suscripciones_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.suscripciones
    ADD CONSTRAINT suscripciones_pkey PRIMARY KEY (id);


--
-- Name: tipos_afectacion_igv tipos_afectacion_igv_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tipos_afectacion_igv
    ADD CONSTRAINT tipos_afectacion_igv_pkey PRIMARY KEY (codigo);


--
-- Name: tipos_cambio tipos_cambio_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tipos_cambio
    ADD CONSTRAINT tipos_cambio_pkey PRIMARY KEY (fecha);


--
-- Name: tipos_comprobante tipos_comprobante_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tipos_comprobante
    ADD CONSTRAINT tipos_comprobante_pkey PRIMARY KEY (codigo);


--
-- Name: tipos_documento_identidad tipos_documento_identidad_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tipos_documento_identidad
    ADD CONSTRAINT tipos_documento_identidad_pkey PRIMARY KEY (codigo);


--
-- Name: transferencia_detalles transferencia_detalles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transferencia_detalles
    ADD CONSTRAINT transferencia_detalles_pkey PRIMARY KEY (id);


--
-- Name: transferencias transferencias_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transferencias
    ADD CONSTRAINT transferencias_pkey PRIMARY KEY (id);


--
-- Name: ubigeos ubigeos_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ubigeos
    ADD CONSTRAINT ubigeos_pkey PRIMARY KEY (codigo);


--
-- Name: unidades_medida unidades_medida_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.unidades_medida
    ADD CONSTRAINT unidades_medida_pkey PRIMARY KEY (codigo);


--
-- Name: usuario_sucursales usuario_sucursales_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usuario_sucursales
    ADD CONSTRAINT usuario_sucursales_pkey PRIMARY KEY (usuario_id, sucursal_id);


--
-- Name: usuarios usuarios_empresa_id_email_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usuarios
    ADD CONSTRAINT usuarios_empresa_id_email_key UNIQUE (empresa_id, email);


--
-- Name: usuarios usuarios_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usuarios
    ADD CONSTRAINT usuarios_pkey PRIMARY KEY (id);


--
-- Name: idx_aud_empresa; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_aud_empresa ON public.auditoria USING btree (empresa_id, creado_en);


--
-- Name: idx_capas_fifo; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_capas_fifo ON public.capas_costo USING btree (producto_id, sucursal_id, fecha_ingreso) WHERE (cantidad_restante > (0)::numeric);


--
-- Name: idx_categorias_empresa; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_categorias_empresa ON public.categorias USING btree (empresa_id);


--
-- Name: idx_cdet_compra; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cdet_compra ON public.compra_detalles USING btree (compra_id);


--
-- Name: idx_clientes_nombre_trgm; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_clientes_nombre_trgm ON public.clientes USING gin (nombre public.gin_trgm_ops);


--
-- Name: idx_cobros_apertura; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cobros_apertura ON public.cobros USING btree (apertura_id);


--
-- Name: idx_cobros_cuenta; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cobros_cuenta ON public.cobros USING btree (cuenta_id);


--
-- Name: idx_comp_apertura; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_comp_apertura ON public.comprobantes USING btree (apertura_id);


--
-- Name: idx_comp_cliente; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_comp_cliente ON public.comprobantes USING btree (cliente_id);


--
-- Name: idx_comp_fecha; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_comp_fecha ON public.comprobantes USING btree (empresa_id, fecha_emision);


--
-- Name: idx_compras_fecha; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_compras_fecha ON public.compras USING btree (empresa_id, fecha);


--
-- Name: idx_comprobantes_sunat_estado; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_comprobantes_sunat_estado ON public.comprobantes_sunat USING btree (estado);


--
-- Name: idx_consumo_detalle; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_consumo_detalle ON public.detalle_consumo_capas USING btree (detalle_id);


--
-- Name: idx_cxc_cliente; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cxc_cliente ON public.cuentas_por_cobrar USING btree (cliente_id, estado);


--
-- Name: idx_cxc_empresa; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cxc_empresa ON public.cuentas_por_cobrar USING btree (empresa_id, estado);


--
-- Name: idx_cxp_empresa; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cxp_empresa ON public.cuentas_por_pagar USING btree (empresa_id, estado);


--
-- Name: idx_cxp_proveedor; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cxp_proveedor ON public.cuentas_por_pagar USING btree (proveedor_id, estado);


--
-- Name: idx_det_comprobante; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_det_comprobante ON public.comprobante_detalles USING btree (comprobante_id);


--
-- Name: idx_lotes_vencimiento; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_lotes_vencimiento ON public.lotes USING btree (empresa_id, fecha_vencimiento);


--
-- Name: idx_mov_kardex; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_mov_kardex ON public.movimientos_inventario USING btree (empresa_id, producto_id, creado_en);


--
-- Name: idx_movcaja_apertura; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_movcaja_apertura ON public.movimientos_caja USING btree (apertura_id);


--
-- Name: idx_movimientos_caja_apertura_medio; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_movimientos_caja_apertura_medio ON public.movimientos_caja USING btree (apertura_id, tipo, medio_pago_codigo);


--
-- Name: idx_pagos_apertura; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_pagos_apertura ON public.pagos USING btree (apertura_id);


--
-- Name: idx_pagos_comprobante; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_pagos_comprobante ON public.pagos USING btree (comprobante_id);


--
-- Name: idx_pagos_prov_apertura; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_pagos_prov_apertura ON public.pagos_proveedor USING btree (apertura_id);


--
-- Name: idx_pagos_prov_cuenta; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_pagos_prov_cuenta ON public.pagos_proveedor USING btree (cuenta_id);


--
-- Name: idx_pres_barras; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_pres_barras ON public.producto_presentaciones USING btree (empresa_id, codigo_barras);


--
-- Name: idx_productos_empresa; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_productos_empresa ON public.productos USING btree (empresa_id) WHERE (eliminado_en IS NULL);


--
-- Name: idx_productos_nombre_trgm; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_productos_nombre_trgm ON public.productos USING gin (nombre public.gin_trgm_ops);


--
-- Name: idx_proveedores_empresa; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_proveedores_empresa ON public.proveedores USING btree (empresa_id);


--
-- Name: idx_stock_empresa; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_stock_empresa ON public.stock USING btree (empresa_id, sucursal_id);


--
-- Name: idx_sucursales_empresa; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_sucursales_empresa ON public.sucursales USING btree (empresa_id);


--
-- Name: idx_suscripciones_empresa_fin; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_suscripciones_empresa_fin ON public.suscripciones USING btree (empresa_id, fecha_fin DESC);


--
-- Name: jobs_queue_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue);


--
-- Name: uq_apertura_abierta; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_apertura_abierta ON public.aperturas_caja USING btree (caja_id) WHERE (cerrada_en IS NULL);


--
-- Name: uq_compras_proveedor_documento; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_compras_proveedor_documento ON public.compras USING btree (empresa_id, proveedor_id, serie_numero) WHERE (((estado)::text = 'registrada'::text) AND (proveedor_id IS NOT NULL) AND (serie_numero IS NOT NULL));


--
-- Name: uq_planes_codigo; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_planes_codigo ON public.planes USING btree (codigo);


--
-- Name: uq_presentacion_default; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_presentacion_default ON public.producto_presentaciones USING btree (producto_id) WHERE es_default;


--
-- Name: uq_suscripcion_activa; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_suscripcion_activa ON public.suscripciones USING btree (empresa_id) WHERE ((estado)::text = 'activa'::text);


--
-- Name: clientes trg_clientes_upd; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_clientes_upd BEFORE UPDATE ON public.clientes FOR EACH ROW EXECUTE FUNCTION public.set_actualizado_en();


--
-- Name: comprobantes_sunat trg_cpe_actualizado; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_cpe_actualizado BEFORE UPDATE ON public.comprobantes_sunat FOR EACH ROW EXECUTE FUNCTION public.set_actualizado_en();


--
-- Name: empresas trg_empresas_upd; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_empresas_upd BEFORE UPDATE ON public.empresas FOR EACH ROW EXECUTE FUNCTION public.set_actualizado_en();


--
-- Name: productos trg_productos_upd; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_productos_upd BEFORE UPDATE ON public.productos FOR EACH ROW EXECUTE FUNCTION public.set_actualizado_en();


--
-- Name: stock trg_stock_upd; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_stock_upd BEFORE UPDATE ON public.stock FOR EACH ROW EXECUTE FUNCTION public.set_actualizado_en();


--
-- Name: usuarios trg_usuarios_upd; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_usuarios_upd BEFORE UPDATE ON public.usuarios FOR EACH ROW EXECUTE FUNCTION public.set_actualizado_en();


--
-- Name: aperturas_caja aperturas_caja_caja_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.aperturas_caja
    ADD CONSTRAINT aperturas_caja_caja_id_fkey FOREIGN KEY (caja_id) REFERENCES public.cajas(id);


--
-- Name: aperturas_caja aperturas_caja_empresa_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.aperturas_caja
    ADD CONSTRAINT aperturas_caja_empresa_id_fkey FOREIGN KEY (empresa_id) REFERENCES public.empresas(id);


--
-- Name: aperturas_caja aperturas_caja_usuario_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.aperturas_caja
    ADD CONSTRAINT aperturas_caja_usuario_id_fkey FOREIGN KEY (usuario_id) REFERENCES public.usuarios(id);


--
-- Name: cajas cajas_empresa_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cajas
    ADD CONSTRAINT cajas_empresa_id_fkey FOREIGN KEY (empresa_id) REFERENCES public.empresas(id);


--
-- Name: cajas cajas_sucursal_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cajas
    ADD CONSTRAINT cajas_sucursal_id_fkey FOREIGN KEY (sucursal_id) REFERENCES public.sucursales(id);


--
-- Name: capas_costo capas_costo_empresa_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.capas_costo
    ADD CONSTRAINT capas_costo_empresa_id_fkey FOREIGN KEY (empresa_id) REFERENCES public.empresas(id);


--
-- Name: capas_costo capas_costo_lote_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.capas_costo
    ADD CONSTRAINT capas_costo_lote_id_fkey FOREIGN KEY (lote_id) REFERENCES public.lotes(id);


--
-- Name: capas_costo capas_costo_producto_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.capas_costo
    ADD CONSTRAINT capas_costo_producto_id_fkey FOREIGN KEY (producto_id) REFERENCES public.productos(id);


--
-- Name: capas_costo capas_costo_sucursal_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.capas_costo
    ADD CONSTRAINT capas_costo_sucursal_id_fkey FOREIGN KEY (sucursal_id) REFERENCES public.sucursales(id);


--
-- Name: categorias categorias_empresa_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.categorias
    ADD CONSTRAINT categorias_empresa_id_fkey FOREIGN KEY (empresa_id) REFERENCES public.empresas(id);


--
-- Name: categorias categorias_padre_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.categorias
    ADD CONSTRAINT categorias_padre_id_fkey FOREIGN KEY (padre_id) REFERENCES public.categorias(id);


--
-- Name: clientes clientes_empresa_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.clientes
    ADD CONSTRAINT clientes_empresa_id_fkey FOREIGN KEY (empresa_id) REFERENCES public.empresas(id);


--
-- Name: clientes clientes_tipo_documento_codigo_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.clientes
    ADD CONSTRAINT clientes_tipo_documento_codigo_fkey FOREIGN KEY (tipo_documento_codigo) REFERENCES public.tipos_documento_identidad(codigo);


--
-- Name: cobros cobros_apertura_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cobros
    ADD CONSTRAINT cobros_apertura_id_fkey FOREIGN KEY (apertura_id) REFERENCES public.aperturas_caja(id);


--
-- Name: cobros cobros_cuenta_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cobros
    ADD CONSTRAINT cobros_cuenta_id_fkey FOREIGN KEY (cuenta_id) REFERENCES public.cuentas_por_cobrar(id);


--
-- Name: cobros cobros_empresa_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cobros
    ADD CONSTRAINT cobros_empresa_id_fkey FOREIGN KEY (empresa_id) REFERENCES public.empresas(id);


--
-- Name: cobros cobros_medio_pago_codigo_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cobros
    ADD CONSTRAINT cobros_medio_pago_codigo_fkey FOREIGN KEY (medio_pago_codigo) REFERENCES public.medios_pago(codigo);


--
-- Name: cobros cobros_usuario_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cobros
    ADD CONSTRAINT cobros_usuario_id_fkey FOREIGN KEY (usuario_id) REFERENCES public.usuarios(id);


--
-- Name: compra_detalles compra_detalles_compra_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.compra_detalles
    ADD CONSTRAINT compra_detalles_compra_id_fkey FOREIGN KEY (compra_id) REFERENCES public.compras(id);


--
-- Name: compra_detalles compra_detalles_empresa_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.compra_detalles
    ADD CONSTRAINT compra_detalles_empresa_id_fkey FOREIGN KEY (empresa_id) REFERENCES public.empresas(id);


--
-- Name: compra_detalles compra_detalles_lote_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.compra_detalles
    ADD CONSTRAINT compra_detalles_lote_id_fkey FOREIGN KEY (lote_id) REFERENCES public.lotes(id);


--
-- Name: compra_detalles compra_detalles_presentacion_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.compra_detalles
    ADD CONSTRAINT compra_detalles_presentacion_id_fkey FOREIGN KEY (presentacion_id) REFERENCES public.producto_presentaciones(id);


--
-- Name: compra_detalles compra_detalles_producto_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.compra_detalles
    ADD CONSTRAINT compra_detalles_producto_id_fkey FOREIGN KEY (producto_id) REFERENCES public.productos(id);


--
-- Name: compras compras_anulada_por_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.compras
    ADD CONSTRAINT compras_anulada_por_fkey FOREIGN KEY (anulada_por) REFERENCES public.usuarios(id);


--
-- Name: compras compras_empresa_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.compras
    ADD CONSTRAINT compras_empresa_id_fkey FOREIGN KEY (empresa_id) REFERENCES public.empresas(id);


--
-- Name: compras compras_proveedor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.compras
    ADD CONSTRAINT compras_proveedor_id_fkey FOREIGN KEY (proveedor_id) REFERENCES public.proveedores(id);


--
-- Name: compras compras_sucursal_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.compras
    ADD CONSTRAINT compras_sucursal_id_fkey FOREIGN KEY (sucursal_id) REFERENCES public.sucursales(id);


--
-- Name: compras compras_tipo_comprobante_codigo_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.compras
    ADD CONSTRAINT compras_tipo_comprobante_codigo_fkey FOREIGN KEY (tipo_comprobante_codigo) REFERENCES public.tipos_comprobante(codigo);


--
-- Name: compras compras_usuario_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.compras
    ADD CONSTRAINT compras_usuario_id_fkey FOREIGN KEY (usuario_id) REFERENCES public.usuarios(id);


--
-- Name: comprobante_detalles comprobante_detalles_comprobante_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comprobante_detalles
    ADD CONSTRAINT comprobante_detalles_comprobante_id_fkey FOREIGN KEY (comprobante_id) REFERENCES public.comprobantes(id);


--
-- Name: comprobante_detalles comprobante_detalles_empresa_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comprobante_detalles
    ADD CONSTRAINT comprobante_detalles_empresa_id_fkey FOREIGN KEY (empresa_id) REFERENCES public.empresas(id);


--
-- Name: comprobante_detalles comprobante_detalles_lote_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comprobante_detalles
    ADD CONSTRAINT comprobante_detalles_lote_id_fkey FOREIGN KEY (lote_id) REFERENCES public.lotes(id);


--
-- Name: comprobante_detalles comprobante_detalles_presentacion_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comprobante_detalles
    ADD CONSTRAINT comprobante_detalles_presentacion_id_fkey FOREIGN KEY (presentacion_id) REFERENCES public.producto_presentaciones(id);


--
-- Name: comprobante_detalles comprobante_detalles_producto_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comprobante_detalles
    ADD CONSTRAINT comprobante_detalles_producto_id_fkey FOREIGN KEY (producto_id) REFERENCES public.productos(id);


--
-- Name: comprobante_detalles comprobante_detalles_tipo_afectacion_codigo_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comprobante_detalles
    ADD CONSTRAINT comprobante_detalles_tipo_afectacion_codigo_fkey FOREIGN KEY (tipo_afectacion_codigo) REFERENCES public.tipos_afectacion_igv(codigo);


--
-- Name: comprobante_detalles comprobante_detalles_unidad_codigo_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comprobante_detalles
    ADD CONSTRAINT comprobante_detalles_unidad_codigo_fkey FOREIGN KEY (unidad_codigo) REFERENCES public.unidades_medida(codigo);


--
-- Name: comprobantes comprobantes_anulado_por_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comprobantes
    ADD CONSTRAINT comprobantes_anulado_por_fkey FOREIGN KEY (anulado_por) REFERENCES public.usuarios(id);


--
-- Name: comprobantes comprobantes_apertura_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comprobantes
    ADD CONSTRAINT comprobantes_apertura_id_fkey FOREIGN KEY (apertura_id) REFERENCES public.aperturas_caja(id);


--
-- Name: comprobantes comprobantes_caja_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comprobantes
    ADD CONSTRAINT comprobantes_caja_id_fkey FOREIGN KEY (caja_id) REFERENCES public.cajas(id);


--
-- Name: comprobantes comprobantes_cliente_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comprobantes
    ADD CONSTRAINT comprobantes_cliente_id_fkey FOREIGN KEY (cliente_id) REFERENCES public.clientes(id);


--
-- Name: comprobantes comprobantes_comprobante_ref_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comprobantes
    ADD CONSTRAINT comprobantes_comprobante_ref_id_fkey FOREIGN KEY (comprobante_ref_id) REFERENCES public.comprobantes(id);


--
-- Name: comprobantes comprobantes_empresa_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comprobantes
    ADD CONSTRAINT comprobantes_empresa_id_fkey FOREIGN KEY (empresa_id) REFERENCES public.empresas(id);


--
-- Name: comprobantes comprobantes_sucursal_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comprobantes
    ADD CONSTRAINT comprobantes_sucursal_id_fkey FOREIGN KEY (sucursal_id) REFERENCES public.sucursales(id);


--
-- Name: comprobantes_sunat comprobantes_sunat_comprobante_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comprobantes_sunat
    ADD CONSTRAINT comprobantes_sunat_comprobante_id_fkey FOREIGN KEY (comprobante_id) REFERENCES public.comprobantes(id);


--
-- Name: comprobantes comprobantes_tipo_comprobante_codigo_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comprobantes
    ADD CONSTRAINT comprobantes_tipo_comprobante_codigo_fkey FOREIGN KEY (tipo_comprobante_codigo) REFERENCES public.tipos_comprobante(codigo);


--
-- Name: comprobantes comprobantes_usuario_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comprobantes
    ADD CONSTRAINT comprobantes_usuario_id_fkey FOREIGN KEY (usuario_id) REFERENCES public.usuarios(id);


--
-- Name: cuentas_por_cobrar cuentas_por_cobrar_cliente_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cuentas_por_cobrar
    ADD CONSTRAINT cuentas_por_cobrar_cliente_id_fkey FOREIGN KEY (cliente_id) REFERENCES public.clientes(id);


--
-- Name: cuentas_por_cobrar cuentas_por_cobrar_comprobante_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cuentas_por_cobrar
    ADD CONSTRAINT cuentas_por_cobrar_comprobante_id_fkey FOREIGN KEY (comprobante_id) REFERENCES public.comprobantes(id);


--
-- Name: cuentas_por_cobrar cuentas_por_cobrar_empresa_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cuentas_por_cobrar
    ADD CONSTRAINT cuentas_por_cobrar_empresa_id_fkey FOREIGN KEY (empresa_id) REFERENCES public.empresas(id);


--
-- Name: cuentas_por_pagar cuentas_por_pagar_compra_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cuentas_por_pagar
    ADD CONSTRAINT cuentas_por_pagar_compra_id_fkey FOREIGN KEY (compra_id) REFERENCES public.compras(id);


--
-- Name: cuentas_por_pagar cuentas_por_pagar_empresa_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cuentas_por_pagar
    ADD CONSTRAINT cuentas_por_pagar_empresa_id_fkey FOREIGN KEY (empresa_id) REFERENCES public.empresas(id);


--
-- Name: cuentas_por_pagar cuentas_por_pagar_proveedor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cuentas_por_pagar
    ADD CONSTRAINT cuentas_por_pagar_proveedor_id_fkey FOREIGN KEY (proveedor_id) REFERENCES public.proveedores(id);


--
-- Name: detalle_consumo_capas detalle_consumo_capas_capa_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.detalle_consumo_capas
    ADD CONSTRAINT detalle_consumo_capas_capa_id_fkey FOREIGN KEY (capa_id) REFERENCES public.capas_costo(id);


--
-- Name: detalle_consumo_capas detalle_consumo_capas_detalle_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.detalle_consumo_capas
    ADD CONSTRAINT detalle_consumo_capas_detalle_id_fkey FOREIGN KEY (detalle_id) REFERENCES public.comprobante_detalles(id);


--
-- Name: empresas empresas_rubro_codigo_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.empresas
    ADD CONSTRAINT empresas_rubro_codigo_fkey FOREIGN KEY (rubro_codigo) REFERENCES public.rubros(codigo);


--
-- Name: capas_costo fk_capa_compra_detalle; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.capas_costo
    ADD CONSTRAINT fk_capa_compra_detalle FOREIGN KEY (compra_detalle_id) REFERENCES public.compra_detalles(id);


--
-- Name: lotes lotes_empresa_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lotes
    ADD CONSTRAINT lotes_empresa_id_fkey FOREIGN KEY (empresa_id) REFERENCES public.empresas(id);


--
-- Name: lotes lotes_producto_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lotes
    ADD CONSTRAINT lotes_producto_id_fkey FOREIGN KEY (producto_id) REFERENCES public.productos(id);


--
-- Name: lotes lotes_sucursal_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lotes
    ADD CONSTRAINT lotes_sucursal_id_fkey FOREIGN KEY (sucursal_id) REFERENCES public.sucursales(id);


--
-- Name: marcas marcas_empresa_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.marcas
    ADD CONSTRAINT marcas_empresa_id_fkey FOREIGN KEY (empresa_id) REFERENCES public.empresas(id);


--
-- Name: movimientos_caja movimientos_caja_apertura_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.movimientos_caja
    ADD CONSTRAINT movimientos_caja_apertura_id_fkey FOREIGN KEY (apertura_id) REFERENCES public.aperturas_caja(id);


--
-- Name: movimientos_caja movimientos_caja_empresa_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.movimientos_caja
    ADD CONSTRAINT movimientos_caja_empresa_id_fkey FOREIGN KEY (empresa_id) REFERENCES public.empresas(id);


--
-- Name: movimientos_caja movimientos_caja_medio_pago_codigo_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.movimientos_caja
    ADD CONSTRAINT movimientos_caja_medio_pago_codigo_fkey FOREIGN KEY (medio_pago_codigo) REFERENCES public.medios_pago(codigo);


--
-- Name: movimientos_caja movimientos_caja_usuario_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.movimientos_caja
    ADD CONSTRAINT movimientos_caja_usuario_id_fkey FOREIGN KEY (usuario_id) REFERENCES public.usuarios(id);


--
-- Name: movimientos_inventario movimientos_inventario_empresa_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.movimientos_inventario
    ADD CONSTRAINT movimientos_inventario_empresa_id_fkey FOREIGN KEY (empresa_id) REFERENCES public.empresas(id);


--
-- Name: movimientos_inventario movimientos_inventario_lote_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.movimientos_inventario
    ADD CONSTRAINT movimientos_inventario_lote_id_fkey FOREIGN KEY (lote_id) REFERENCES public.lotes(id);


--
-- Name: movimientos_inventario movimientos_inventario_producto_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.movimientos_inventario
    ADD CONSTRAINT movimientos_inventario_producto_id_fkey FOREIGN KEY (producto_id) REFERENCES public.productos(id);


--
-- Name: movimientos_inventario movimientos_inventario_sucursal_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.movimientos_inventario
    ADD CONSTRAINT movimientos_inventario_sucursal_id_fkey FOREIGN KEY (sucursal_id) REFERENCES public.sucursales(id);


--
-- Name: movimientos_inventario movimientos_inventario_usuario_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.movimientos_inventario
    ADD CONSTRAINT movimientos_inventario_usuario_id_fkey FOREIGN KEY (usuario_id) REFERENCES public.usuarios(id);


--
-- Name: pagos pagos_apertura_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pagos
    ADD CONSTRAINT pagos_apertura_id_fkey FOREIGN KEY (apertura_id) REFERENCES public.aperturas_caja(id);


--
-- Name: pagos pagos_comprobante_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pagos
    ADD CONSTRAINT pagos_comprobante_id_fkey FOREIGN KEY (comprobante_id) REFERENCES public.comprobantes(id);


--
-- Name: pagos pagos_empresa_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pagos
    ADD CONSTRAINT pagos_empresa_id_fkey FOREIGN KEY (empresa_id) REFERENCES public.empresas(id);


--
-- Name: pagos pagos_medio_pago_codigo_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pagos
    ADD CONSTRAINT pagos_medio_pago_codigo_fkey FOREIGN KEY (medio_pago_codigo) REFERENCES public.medios_pago(codigo);


--
-- Name: pagos_proveedor pagos_proveedor_apertura_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pagos_proveedor
    ADD CONSTRAINT pagos_proveedor_apertura_id_fkey FOREIGN KEY (apertura_id) REFERENCES public.aperturas_caja(id);


--
-- Name: pagos_proveedor pagos_proveedor_cuenta_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pagos_proveedor
    ADD CONSTRAINT pagos_proveedor_cuenta_id_fkey FOREIGN KEY (cuenta_id) REFERENCES public.cuentas_por_pagar(id);


--
-- Name: pagos_proveedor pagos_proveedor_empresa_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pagos_proveedor
    ADD CONSTRAINT pagos_proveedor_empresa_id_fkey FOREIGN KEY (empresa_id) REFERENCES public.empresas(id);


--
-- Name: pagos_proveedor pagos_proveedor_medio_pago_codigo_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pagos_proveedor
    ADD CONSTRAINT pagos_proveedor_medio_pago_codigo_fkey FOREIGN KEY (medio_pago_codigo) REFERENCES public.medios_pago(codigo);


--
-- Name: pagos_proveedor pagos_proveedor_usuario_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pagos_proveedor
    ADD CONSTRAINT pagos_proveedor_usuario_id_fkey FOREIGN KEY (usuario_id) REFERENCES public.usuarios(id);


--
-- Name: pagos pagos_usuario_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pagos
    ADD CONSTRAINT pagos_usuario_id_fkey FOREIGN KEY (usuario_id) REFERENCES public.usuarios(id);


--
-- Name: precios_sucursal precios_sucursal_empresa_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.precios_sucursal
    ADD CONSTRAINT precios_sucursal_empresa_id_fkey FOREIGN KEY (empresa_id) REFERENCES public.empresas(id);


--
-- Name: precios_sucursal precios_sucursal_presentacion_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.precios_sucursal
    ADD CONSTRAINT precios_sucursal_presentacion_id_fkey FOREIGN KEY (presentacion_id) REFERENCES public.producto_presentaciones(id);


--
-- Name: precios_sucursal precios_sucursal_sucursal_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.precios_sucursal
    ADD CONSTRAINT precios_sucursal_sucursal_id_fkey FOREIGN KEY (sucursal_id) REFERENCES public.sucursales(id);


--
-- Name: producto_presentaciones producto_presentaciones_empresa_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.producto_presentaciones
    ADD CONSTRAINT producto_presentaciones_empresa_id_fkey FOREIGN KEY (empresa_id) REFERENCES public.empresas(id);


--
-- Name: producto_presentaciones producto_presentaciones_producto_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.producto_presentaciones
    ADD CONSTRAINT producto_presentaciones_producto_id_fkey FOREIGN KEY (producto_id) REFERENCES public.productos(id);


--
-- Name: producto_presentaciones producto_presentaciones_unidad_codigo_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.producto_presentaciones
    ADD CONSTRAINT producto_presentaciones_unidad_codigo_fkey FOREIGN KEY (unidad_codigo) REFERENCES public.unidades_medida(codigo);


--
-- Name: productos productos_categoria_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.productos
    ADD CONSTRAINT productos_categoria_id_fkey FOREIGN KEY (categoria_id) REFERENCES public.categorias(id);


--
-- Name: productos productos_empresa_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.productos
    ADD CONSTRAINT productos_empresa_id_fkey FOREIGN KEY (empresa_id) REFERENCES public.empresas(id);


--
-- Name: productos productos_marca_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.productos
    ADD CONSTRAINT productos_marca_id_fkey FOREIGN KEY (marca_id) REFERENCES public.marcas(id);


--
-- Name: productos productos_tipo_afectacion_codigo_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.productos
    ADD CONSTRAINT productos_tipo_afectacion_codigo_fkey FOREIGN KEY (tipo_afectacion_codigo) REFERENCES public.tipos_afectacion_igv(codigo);


--
-- Name: productos productos_unidad_base_codigo_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.productos
    ADD CONSTRAINT productos_unidad_base_codigo_fkey FOREIGN KEY (unidad_base_codigo) REFERENCES public.unidades_medida(codigo);


--
-- Name: proveedores proveedores_empresa_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.proveedores
    ADD CONSTRAINT proveedores_empresa_id_fkey FOREIGN KEY (empresa_id) REFERENCES public.empresas(id);


--
-- Name: series_correlativos series_correlativos_caja_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.series_correlativos
    ADD CONSTRAINT series_correlativos_caja_id_fkey FOREIGN KEY (caja_id) REFERENCES public.cajas(id);


--
-- Name: series_correlativos series_correlativos_empresa_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.series_correlativos
    ADD CONSTRAINT series_correlativos_empresa_id_fkey FOREIGN KEY (empresa_id) REFERENCES public.empresas(id);


--
-- Name: series_correlativos series_correlativos_sucursal_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.series_correlativos
    ADD CONSTRAINT series_correlativos_sucursal_id_fkey FOREIGN KEY (sucursal_id) REFERENCES public.sucursales(id);


--
-- Name: series_correlativos series_correlativos_tipo_comprobante_codigo_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.series_correlativos
    ADD CONSTRAINT series_correlativos_tipo_comprobante_codigo_fkey FOREIGN KEY (tipo_comprobante_codigo) REFERENCES public.tipos_comprobante(codigo);


--
-- Name: stock stock_empresa_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock
    ADD CONSTRAINT stock_empresa_id_fkey FOREIGN KEY (empresa_id) REFERENCES public.empresas(id);


--
-- Name: stock stock_producto_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock
    ADD CONSTRAINT stock_producto_id_fkey FOREIGN KEY (producto_id) REFERENCES public.productos(id);


--
-- Name: stock stock_sucursal_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock
    ADD CONSTRAINT stock_sucursal_id_fkey FOREIGN KEY (sucursal_id) REFERENCES public.sucursales(id);


--
-- Name: sucursales sucursales_empresa_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sucursales
    ADD CONSTRAINT sucursales_empresa_id_fkey FOREIGN KEY (empresa_id) REFERENCES public.empresas(id);


--
-- Name: suscripciones suscripciones_empresa_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.suscripciones
    ADD CONSTRAINT suscripciones_empresa_id_fkey FOREIGN KEY (empresa_id) REFERENCES public.empresas(id);


--
-- Name: suscripciones suscripciones_plan_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.suscripciones
    ADD CONSTRAINT suscripciones_plan_id_fkey FOREIGN KEY (plan_id) REFERENCES public.planes(id);


--
-- Name: transferencia_detalles transferencia_detalles_lote_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transferencia_detalles
    ADD CONSTRAINT transferencia_detalles_lote_id_fkey FOREIGN KEY (lote_id) REFERENCES public.lotes(id);


--
-- Name: transferencia_detalles transferencia_detalles_producto_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transferencia_detalles
    ADD CONSTRAINT transferencia_detalles_producto_id_fkey FOREIGN KEY (producto_id) REFERENCES public.productos(id);


--
-- Name: transferencia_detalles transferencia_detalles_transferencia_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transferencia_detalles
    ADD CONSTRAINT transferencia_detalles_transferencia_id_fkey FOREIGN KEY (transferencia_id) REFERENCES public.transferencias(id);


--
-- Name: transferencias transferencias_empresa_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transferencias
    ADD CONSTRAINT transferencias_empresa_id_fkey FOREIGN KEY (empresa_id) REFERENCES public.empresas(id);


--
-- Name: transferencias transferencias_sucursal_destino_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transferencias
    ADD CONSTRAINT transferencias_sucursal_destino_id_fkey FOREIGN KEY (sucursal_destino_id) REFERENCES public.sucursales(id);


--
-- Name: transferencias transferencias_sucursal_origen_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transferencias
    ADD CONSTRAINT transferencias_sucursal_origen_id_fkey FOREIGN KEY (sucursal_origen_id) REFERENCES public.sucursales(id);


--
-- Name: transferencias transferencias_usuario_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transferencias
    ADD CONSTRAINT transferencias_usuario_id_fkey FOREIGN KEY (usuario_id) REFERENCES public.usuarios(id);


--
-- Name: usuario_sucursales usuario_sucursales_sucursal_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usuario_sucursales
    ADD CONSTRAINT usuario_sucursales_sucursal_id_fkey FOREIGN KEY (sucursal_id) REFERENCES public.sucursales(id);


--
-- Name: usuario_sucursales usuario_sucursales_usuario_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usuario_sucursales
    ADD CONSTRAINT usuario_sucursales_usuario_id_fkey FOREIGN KEY (usuario_id) REFERENCES public.usuarios(id);


--
-- Name: usuarios usuarios_empresa_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usuarios
    ADD CONSTRAINT usuarios_empresa_id_fkey FOREIGN KEY (empresa_id) REFERENCES public.empresas(id);


--
-- Name: usuarios usuarios_rol_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usuarios
    ADD CONSTRAINT usuarios_rol_id_fkey FOREIGN KEY (rol_id) REFERENCES public.roles(id);


--
-- Name: usuarios usuarios_sucursal_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usuarios
    ADD CONSTRAINT usuarios_sucursal_id_fkey FOREIGN KEY (sucursal_id) REFERENCES public.sucursales(id);


--
-- PostgreSQL database dump complete
--

\unrestrict ac0FhPHBu8AiVLaP1G3dGDUVRHNeOYjRc1Kh4ynmoJpcN0bCvaCYNhm0447hYvg

