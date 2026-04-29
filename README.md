# Módulo VeriFactu para Dolibarr

Módulo de cumplimiento del **Reglamento VeriFactu** (Real Decreto 1007/2023) para la plataforma Dolibarr ERP.

Registra cada factura validada en un libro inmutable encadenado mediante Huellas SHA-256, genera el XML firmado con XAdES-BES/T y lo prepara para su envío a la AEAT.

---

## Qué hace

| Función | Detalle |
|---|---|
| **Registro automático** | Al validar una factura en Dolibarr se crea un registro ALTA en `llx_verifactu_registry` |
| **Huella (hash)** | SHA-256 con el algoritmo oficial AEAT: campos `Key=Value` separados por `&` |
| **Encadenamiento** | Cada registro contiene la Huella del anterior — la cadena es inmutable |
| **Tipo F1 / F2** | Detección automática: F1 si el cliente tiene NIF/CIF, F2 (simplificada) si no |
| **Desglose IVA** | El XML incluye base imponible y cuota por cada tipo impositivo (21%, 10%, 4%…) |
| **Firma XAdES** | Firma automática XAdES-BES con certificado PFX; opcionalmente XAdES-T con TSA |
| **Bloqueo fiscal** | Impide borrar o modificar facturas ya registradas en VeriFactu |
| **Envío AEAT** | Modo SEND: envío automático al validar. Modo NOSEND: almacenamiento local + cron |
| **Validación diaria** | Cron que recorre toda la cadena y alerta por email si detecta inconsistencias |
| **Bloqueo anual** | Una vez activado el modo fiscal de un ejercicio no se puede desactivar |

---

## Estructura de ficheros

```
verifactu/
├── admin/
│   ├── verifactu_setup.php          # Configuración (certificado, TSA, modo, sandbox)
│   ├── verifactu_about.php          # Información del módulo
│   └── verifactu_logs.php           # Visor de registros
│
├── aeat/
│   ├── xsd/
│   │   └── VeriFactu_v1_0.xsd       # Schema local de validación XML (no es el oficial AEAT)
│   ├── ws/
│   │   ├── VeriFactuAeatClient.class.php      # Cliente SOAP AEAT
│   │   └── VeriFactuAeatResponseParser.class.php
│   └── xml/
│       ├── VeriFactuXsdValidator.class.php    # Valida XML contra VeriFactu_v1_0.xsd
│       └── VeriFactuXmlValidator.class.php    # Valida firma XAdES
│
├── class/
│   ├── VeriFactuHash.class.php          # ⭐ Algoritmo Huella AEAT + detección F1/F2
│   ├── VerifactuXmlBuilder.php          # ⭐ Generador XML único (F1/F2, IVA, Huella)
│   ├── VeriFactuRegistry.class.php      # Acceso a llx_verifactu_registry
│   ├── VeriFactuChainValidator.class.php # Validador de la cadena de Huellas
│   ├── VerifactuXadesSigner.php         # Firma XAdES-BES y XAdES-T
│   └── VerifactuAeatClient.php          # Cliente AEAT (stub hasta que AEAT publique endpoint)
│
├── core/
│   ├── modules/modVerifactu.class.php   # Declaración del módulo Dolibarr
│   ├── triggers/
│   │   └── interface_99_modVerifactu_VerifactuTriggers.class.php  # ⭐ Trigger principal
│   └── hooks/
│       └── actions_verifactu.class.php  # Hooks UI (bloque en ficha de factura)
│
├── sql/
│   ├── llx_verifactu_registry.sql           # Tabla principal (instalación inicial)
│   └── llx_verifactu_registry_alter_v1_1.sql # Migración v1.1 (tipo_factura, cuota_total)
│
└── scripts/
    └── cron_retry_aeat.php          # Reintenta envíos PENDING a la AEAT
```

---

## Base de datos

### `llx_verifactu_registry`

| Columna | Tipo | Descripción |
|---|---|---|
| `rowid` | INT PK | ID del registro |
| `entity` | INT | Entidad Dolibarr (multicompany) |
| `fk_facture` | INT | FK a `llx_facture` |
| `record_type` | VARCHAR(16) | `ALTA` o `BAJA` |
| `tipo_factura` | VARCHAR(4) | `F1` (completa) o `F2` (simplificada) |
| `date_creation` | DATETIME | Timestamp de generación del registro |
| `total_ttc` | DOUBLE | Importe total con IVA |
| `cuota_total` | DOUBLE | Suma de cuotas IVA (para recalcular Huella) |
| `hash_actual` | VARCHAR(255) | Huella SHA-256 de este registro |
| `hash_anterior` | VARCHAR(255) | Huella del registro anterior (null = primero) |
| `xml_vf_path` | VARCHAR(255) | Ruta al XML generado |
| `xml_signed_path` | VARCHAR(255) | Ruta al XML firmado XAdES |
| `signature_status` | VARCHAR(20) | `XADES-BES`, `XADES-T` o null |
| `aeat_status` | VARCHAR(32) | `PENDING`, `ACCEPTED`, `REJECTED`, `ERROR` |
| `aeat_csv` | VARCHAR(64) | CSV devuelto por la AEAT |
| `aeat_sent_at` | DATETIME | Fecha/hora de envío a la AEAT |
| `aeat_message` | TEXT | Mensaje de respuesta AEAT |

---

## Algoritmo de la Huella (hash de encadenamiento)

La Huella es un SHA-256 sobre la cadena (codificación UTF-8):

```
IDEmisorFactura=<NIF>&NumSerieFactura=<ref>&FechaExpedicionFactura=<DD-MM-YYYY>
&TipoFactura=<F1|F2>&CuotaTotal=<X.XX>&ImporteTotal=<X.XX>
&Huella=<hash_anterior>&FechaHoraHusoGenRegistro=<YYYY-MM-DDTHH:MM:SS±HH:MM>
```

- `FechaExpedicionFactura` en formato **DD-MM-YYYY** (español, no ISO)
- `FechaHoraHusoGenRegistro` en **ISO 8601 con offset** de zona horaria
- `Huella` es la huella del registro anterior, o cadena vacía si es el primero
- Separador entre pares: `&` (sin espacios)

---

## Flujo al validar una factura

```
BILL_VALIDATE
     │
     ├─ Recargar factura desde BD (evitar ref PROV…)
     ├─ Detectar tipo F1 / F2 (NIF del cliente)
     ├─ Calcular desglose IVA por tramo
     ├─ Obtener Huella del último registro de la entidad
     ├─ Calcular Huella SHA-256 (algoritmo AEAT)
     ├─ INSERT llx_verifactu_registry
     ├─ Generar XML → guardar en disco
     ├─ Si hay PFX → firmar XAdES-BES
     │     └─ Si hay TSA → añadir timestamp XAdES-T
     └─ Si modo SEND y XML firmado → enviar a AEAT
           ├─ ACCEPTED → actualizar registro con CSV
           └─ Error / timeout → dejar en PENDING (cron reintenta)
```

---

## Configuración (Admin → VeriFactu → Configuración)

| Parámetro | Descripción |
|---|---|
| `VERIFACTU_MODE` | `SEND` (envío automático) o `NOSEND` (sólo almacenamiento local) |
| `VERIFACTU_ENVIRONMENT` | `SANDBOX` (pruebas AEAT) o `REAL` (producción) |
| `VERIFACTU_PFX_PATH` | Ruta absoluta al certificado PFX de firma |
| `VERIFACTU_PFX_PASSWORD` | Contraseña del certificado PFX |
| `VERIFACTU_TSA_URL` | URL de la TSA para timestamp XAdES-T (opcional) |
| `VERIFACTU_TSA_USER` | Usuario TSA (si requiere autenticación) |
| `VERIFACTU_TSA_PASSWORD` | Contraseña TSA |
| `VERIFACTU_AEAT_SANDBOX_URL` | Endpoint sandbox AEAT |
| `VERIFACTU_ALERT_EMAIL` | Email para alertas de inconsistencia en la cadena |

---

## Detección automática F1 / F2

| Situación | Tipo asignado |
|---|---|
| Cliente con `idprof1` (NIF/CIF) o `tva_intra` relleno | **F1** — Factura completa |
| Cliente sin NIF, venta al contado, ticket | **F2** — Factura simplificada |

En F1 el XML incluye el nodo `<Destinatario>` con nombre y NIF del comprador.  
En F2 se omite el destinatario (no es obligatorio para facturas simplificadas).

---

## Instalación / actualización

### Instalación nueva
1. Copiar la carpeta en `dolibarr/custom/verifactu/`
2. Activar el módulo en **Inicio → Configuración → Módulos**
3. Ejecutar el SQL de instalación (Dolibarr lo hace automáticamente al activar)
4. Configurar certificado y modo en **Admin → VeriFactu**

### Actualización desde v1.0
Ejecutar manualmente la migración de base de datos:
```sql
-- sql/llx_verifactu_registry_alter_v1_1.sql
ALTER TABLE llx_verifactu_registry
    ADD COLUMN IF NOT EXISTS tipo_factura VARCHAR(4) NOT NULL DEFAULT 'F1',
    ADD COLUMN IF NOT EXISTS cuota_total DOUBLE(24,8) NOT NULL DEFAULT 0;
```

---

## Estado del cliente AEAT

El endpoint definitivo de producción VeriFactu **no está operativo** (pendiente de publicación por la AEAT). El módulo incluye un cliente simulado (`VerifactuAeatClient.php`) que devuelve `ACCEPTED` localmente para no bloquear el flujo. Cuando la AEAT publique el endpoint real, únicamente habrá que implementar la llamada real en ese fichero.

---

## Normativa de referencia

- Real Decreto 1007/2023 — Reglamento de requisitos de los sistemas informáticos de facturación
- Orden HAP — Especificaciones técnicas VeriFactu (Anexo I: algoritmo Huella)
- Ley 58/2003 General Tributaria (arts. 29 y 201 bis)
