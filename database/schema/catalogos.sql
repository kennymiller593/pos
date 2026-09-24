--
-- PostgreSQL database dump
--

\restrict N4UcRoSuwuHXsGd98vWuwV1XtCIxLiU4ut80Iejlzva77FiQJRFG8LQPlWWhgMG

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
-- Data for Name: medios_pago; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.medios_pago (codigo, nombre, requiere_referencia) VALUES ('efectivo', 'Efectivo', false);
INSERT INTO public.medios_pago (codigo, nombre, requiere_referencia) VALUES ('yape', 'Yape', true);
INSERT INTO public.medios_pago (codigo, nombre, requiere_referencia) VALUES ('plin', 'Plin', true);
INSERT INTO public.medios_pago (codigo, nombre, requiere_referencia) VALUES ('tarjeta', 'Tarjeta', true);
INSERT INTO public.medios_pago (codigo, nombre, requiere_referencia) VALUES ('transferencia', 'Transferencia bancaria', true);


--
-- Data for Name: planes; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.planes (id, nombre, precio_mensual, max_sucursales, max_usuarios, max_comprobantes_mes, codigo, descripcion, activo, orden) VALUES ('01a0d594-64e4-7d35-9541-f811569e60d7', 'Prueba gratuita', 0.00, 2, 5, 300, 'prueba', '14 días con todo habilitado para conocer el sistema', true, 0);
INSERT INTO public.planes (id, nombre, precio_mensual, max_sucursales, max_usuarios, max_comprobantes_mes, codigo, descripcion, activo, orden) VALUES ('01a0d594-6820-7c83-99d6-2d604a03c933', 'Emprendedor', 49.00, 1, 3, 500, 'emprendedor', 'Para una tienda con hasta 3 usuarios', true, 1);
INSERT INTO public.planes (id, nombre, precio_mensual, max_sucursales, max_usuarios, max_comprobantes_mes, codigo, descripcion, activo, orden) VALUES ('01a0d594-6820-7b9e-a3e6-23d230eaa9bb', 'Negocio', 99.00, 3, 10, NULL, 'negocio', 'Hasta 3 sucursales y 10 usuarios, comprobantes ilimitados', true, 2);
INSERT INTO public.planes (id, nombre, precio_mensual, max_sucursales, max_usuarios, max_comprobantes_mes, codigo, descripcion, activo, orden) VALUES ('01a0d594-6820-70a7-9f5e-d1e5f56bb1ee', 'Empresa', 199.00, 10, 30, NULL, 'empresa', 'Hasta 10 sucursales y 30 usuarios, comprobantes ilimitados', true, 3);


--
-- Data for Name: roles; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.roles (id, codigo, nombre) OVERRIDING SYSTEM VALUE VALUES (1, 'admin', 'Administrador');
INSERT INTO public.roles (id, codigo, nombre) OVERRIDING SYSTEM VALUE VALUES (2, 'cajero', 'Cajero');
INSERT INTO public.roles (id, codigo, nombre) OVERRIDING SYSTEM VALUE VALUES (3, 'vendedor', 'Vendedor');
INSERT INTO public.roles (id, codigo, nombre) OVERRIDING SYSTEM VALUE VALUES (4, 'almacenero', 'Almacenero');


--
-- Data for Name: rubros; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.rubros (codigo, nombre) VALUES ('agro', 'Agroveterinaria');
INSERT INTO public.rubros (codigo, nombre) VALUES ('ferreteria', 'Ferreteria');
INSERT INTO public.rubros (codigo, nombre) VALUES ('bodega', 'Bodega');
INSERT INTO public.rubros (codigo, nombre) VALUES ('minimarket', 'Minimarket');
INSERT INTO public.rubros (codigo, nombre) VALUES ('botica', 'Botica');


--
-- Data for Name: tipos_afectacion_igv; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.tipos_afectacion_igv (codigo, nombre, afecto) VALUES ('10', 'Gravado - operacion onerosa', true);
INSERT INTO public.tipos_afectacion_igv (codigo, nombre, afecto) VALUES ('20', 'Exonerado - operacion onerosa', false);
INSERT INTO public.tipos_afectacion_igv (codigo, nombre, afecto) VALUES ('30', 'Inafecto - operacion onerosa', false);


--
-- Data for Name: tipos_comprobante; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.tipos_comprobante (codigo, nombre, es_electronico) VALUES ('01', 'Factura electronica', true);
INSERT INTO public.tipos_comprobante (codigo, nombre, es_electronico) VALUES ('03', 'Boleta de venta electronica', true);
INSERT INTO public.tipos_comprobante (codigo, nombre, es_electronico) VALUES ('07', 'Nota de credito', true);
INSERT INTO public.tipos_comprobante (codigo, nombre, es_electronico) VALUES ('08', 'Nota de debito', true);
INSERT INTO public.tipos_comprobante (codigo, nombre, es_electronico) VALUES ('00', 'Nota de venta interna', false);


--
-- Data for Name: tipos_documento_identidad; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.tipos_documento_identidad (codigo, nombre, longitud) VALUES ('0', 'Sin documento', NULL);
INSERT INTO public.tipos_documento_identidad (codigo, nombre, longitud) VALUES ('1', 'DNI', 8);
INSERT INTO public.tipos_documento_identidad (codigo, nombre, longitud) VALUES ('4', 'Carnet de extranjeria', NULL);
INSERT INTO public.tipos_documento_identidad (codigo, nombre, longitud) VALUES ('6', 'RUC', 11);
INSERT INTO public.tipos_documento_identidad (codigo, nombre, longitud) VALUES ('7', 'Pasaporte', NULL);


--
-- Data for Name: unidades_medida; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.unidades_medida (codigo, nombre, permite_decimales) VALUES ('NIU', 'Unidad', false);
INSERT INTO public.unidades_medida (codigo, nombre, permite_decimales) VALUES ('KGM', 'Kilogramo', true);
INSERT INTO public.unidades_medida (codigo, nombre, permite_decimales) VALUES ('LTR', 'Litro', true);
INSERT INTO public.unidades_medida (codigo, nombre, permite_decimales) VALUES ('MTR', 'Metro', true);
INSERT INTO public.unidades_medida (codigo, nombre, permite_decimales) VALUES ('BX', 'Caja', false);
INSERT INTO public.unidades_medida (codigo, nombre, permite_decimales) VALUES ('SA', 'Saco', false);
INSERT INTO public.unidades_medida (codigo, nombre, permite_decimales) VALUES ('GLL', 'Galon', true);


--
-- Name: roles_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.roles_id_seq', 4, true);


--
-- PostgreSQL database dump complete
--

\unrestrict N4UcRoSuwuHXsGd98vWuwV1XtCIxLiU4ut80Iejlzva77FiQJRFG8LQPlWWhgMG

