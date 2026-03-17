# Microservicio LibreDTE (Wrapper Stateless)

Este proyecto es un microservicio REST construido en **PHP 8.3** utilizando **Slim Framework 4**. Actúa como un *wrapper* (envoltorio) para la librería oficial [libredte/libredte-lib-core](https://github.com/LibreDTE/libredte-lib-core) (v24.1.x), permitiendo la emisión y firma de Documentos Tributarios Electrónicos (DTE) para el Servicio de Impuestos Internos (SII) de Chile.

Este microservicio fue diseñado para ser consumido internamente por un backend principal (como Django, Node.js, etc.) en un entorno SaaS Multi-Tenant.

## 🏗 Arquitectura: Stateless & Zero-Trust

Para maximizar la seguridad, escalabilidad y evitar problemas de concurrencia (Race Conditions con los folios), la arquitectura es **100% Stateless (Sin Estado)** y **Zero-Trust**:

1. **Sin Base de Datos:** El microservicio no tiene base de datos.
2. **Sin Almacenamiento Persistente:** No almacena certificados `.p12` ni archivos de folios `CAF` en disco.
3. **Delegación de Responsabilidad:** El backend principal es quien gestiona la base de datos, asigna el número de folio exacto y almacena de forma segura los secretos del tenant.
4. **Procesamiento en Memoria (Efímero):** Cada petición `POST` recibe todo lo necesario (RUTs, montos, certificados en Base64 y CAF en Base64). El microservicio decodifica los secretos en archivos temporales dentro de `/tmp/`, procesa y firma el XML con LibreDTE, y **destruye obligatoriamente** los secretos del disco en un bloque `finally`, garantizando que no queden rastros de las llaves privadas si el contenedor se reutiliza.

## 🚀 Despliegue en Google Cloud Run

El proyecto está dockerizado y optimizado para ejecutarse en Google Cloud Run. Apache está configurado para escuchar en el puerto dinámico `$PORT` que asigna GCP.

### Paso 1: Autenticarse en Google Cloud
```bash
gcloud auth login
gcloud config set project TU_ID_DE_PROYECTO
```

### Paso 2: Desplegar el servicio
Ejecuta el siguiente comando en la raíz del proyecto (donde se ubica el `Dockerfile`):

```bash
gcloud run deploy libredte-service \
  --source . \
  --region southamerica-west1 \
  --allow-unauthenticated \
  --set-env-vars API_TOKEN="tu_token_secreto_aqui"
```
*Nota: Si no defines `API_TOKEN`, el sistema usará por defecto el valor `token_secreto_por_defecto`.*

## 💻 Desarrollo Local con Docker

Si deseas correr el microservicio en tu máquina local para hacer pruebas:

```bash
# 1. Construir la imagen
docker build -t libredte-service .

# 2. Ejecutar el contenedor (expuesto en el puerto 8080)
docker run -d -p 8080:8080 -e API_TOKEN="mi_token_local" --name libredte-app libredte-service

# 3. Comprobar que está vivo
curl http://localhost:8080/health
```

## 📚 Documentación de la API

La comunicación entre el backend principal y este microservicio está protegida por un token Bearer estático.
Debes incluir el header: `Authorization: Bearer <TU_API_TOKEN>` en todas las peticiones protegidas.

### 1. Monitoreo de Salud
- **Ruta:** `GET /health`
- **Auth:** No requiere.
- **Respuesta:** `{"status": "ok", "php_version": "8.3.x", "mode": "stateless"}`

### 2. Emitir DTE
- **Ruta:** `POST /dte/emitir`
- **Descripción:** Genera el XML, lo timbra con el CAF y lo firma con el certificado digital.

**Payload JSON Esperado:**
```json
{
    "tenant_slug": "empresa-demo",
    "tipo_dte": 39,
    "folio_asignado": 151,
    "credenciales": {
        "certificado_b64": "MIIJ... (contenido del .p12 codificado en base64 puro)",
        "password": "clave_del_certificado_p12",
        "caf_xml_b64": "PD94... (contenido del CAF.xml codificado en base64 puro)"
    },
    "emisor": {
        "rut": "76.111.222-3",
        "razon_social": "Empresa Demo SpA",
        "giro": "Comercio",
        "direccion": "Av. Siempre Viva 123",
        "comuna": "Santiago"
    },
    "receptor": {
        "rut": "66666666-6",
        "razon_social": "Cliente Final"
    },
    "detalle": [
        {
            "nombre": "Producto 1",
            "cantidad": 2,
            "precio": 800
        }
    ],
    "totales": {
        "monto_total": 1600
    }
}
```

**Respuesta Exitosa (200 OK):**
```json
{
    "folio": 151,
    "xml": "PD94... (XML final firmado y timbrado en Base64)",
    "pdf": null
}
```

### 3. Anular DTE (Nota de Crédito)
- **Ruta:** `POST /dte/anular`
- **Descripción:** Mismo comportamiento que `/dte/emitir`, pero fuerza internamente el `tipo_dte` a `61` (Nota de Crédito). El backend que lo consuma debe asegurar mandar las referencias al folio original dentro del payload de detalle o referencias (según la estructura que defina la API de LibreDTE).

## ⚠️ Manejo de Errores y Excepciones
El microservicio captura automáticamente cualquier excepción lanzada por la librería de LibreDTE o por el middleware de autenticación, asegurándose de **nunca** escupir código HTML o *stack traces* sucios. 

Las advertencias obsoletas (*Deprecation Warnings*) de PHP generadas por LibreDTE están suprimidas explícitamente en el `index.php` (`error_reporting()`) para no romper el formato de salida.

Siempre recibirás un JSON limpio con código HTTP 400 (Bad Request), 401 (Unauthorized) o 500 (Internal Server Error):

```json
{
  "error": "Error LibreDTE: Error al firmar el DTE. Revisa tus credenciales y CAF.",
  "code": 500
}
```

## 🛠 Variables de Entorno

| Variable | Descripción | Valor por defecto |
|----------|-------------|-------------------|
| `API_TOKEN` | Token estático para validar peticiones al microservicio. | `token_secreto_por_defecto` |
| `PORT` | Puerto de escucha de Apache (Inyectado por Google Cloud Run). | `8080` |
