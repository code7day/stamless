# Genesis CMS

Genesis CMS es una plataforma CMS headless e híbrida, multi-tenant y multi-domain, diseñada especialmente para freelancers y agencias de Latinoamérica.

El primer hito (MVP) está orientado a entregar un sitio web de forma Headless para el Cliente 0 (una consultora uruguaya de seguros y jurídica) bajo un plan Free Forever.

---

## 🛠️ Stack Tecnológico

- **Framework**: Laravel 13 (PHP 8.3+)
- **Panel de Administración**: Filament 5 (soporta Livewire v4)
- **Base de Datos**: PostgreSQL 16 (Single Database con aislamiento por columna `tenant_id`)
- **Estilos**: Tailwind CSS 4 + Alpine.js 4 (Vite integrado)
- **Almacenamiento**: Local (desarrollo) y Cloudflare R2 (producción - compatible con S3)

---

## ⚙️ Requisitos de Desarrollo

- PHP 8.3 o superior
- Composer
- PostgreSQL 16 (corriendo en Docker en el puerto `5434`)
- Node.js (para la compilación de assets si es necesario)

---

## 🚀 Instalación y Setup Local

1. **Clonar el repositorio**:
   ```bash
   git clone <repo-url> genesis-cms
   cd genesis-cms
   ```

2. **Configurar el archivo `.env`**:
   Copia el archivo de ejemplo y asegúrate de que la conexión a PostgreSQL apunta al contenedor Docker (puerto `5434`) y usa el schema `genesis`:
   ```bash
   cp .env.example .env
   ```
   Variables clave en `.env`:
   ```env
   DB_CONNECTION=pgsql
   DB_HOST=127.0.0.1
   DB_PORT=5434
   DB_DATABASE=beezync_db
   DB_USERNAME=postgres
   DB_PASSWORD=postgres123
   DB_SCHEMA=genesis
   ```

3. **Instalar dependencias de PHP**:
   ```bash
   composer install
   ```

4. **Generar la clave de la aplicación**:
   ```bash
   php artisan key:generate
   ```

5. **Ejecutar las migraciones**:
   Las migraciones crearán de forma automática las tablas dentro del schema `genesis`:
   ```bash
   php artisan migrate
   ```

6. **Ejecutar las pruebas unitarias y funcionales**:
   Asegúrate de que todo el entorno funcione y el aislamiento de inquilinos esté verificado:
   ```bash
   php artisan test
   ```

7. **Iniciar el servidor local**:
   ```bash
   php artisan serve
   ```
   El panel de administración estará accesible en `http://localhost:8000/admin`.

---

## 🛡️ Arquitectura Multi-tenant

El multi-tenancy se implementa mediante un enfoque de **Base de datos única + columna `tenant_id`** (Single Database Multi-tenancy). 

- **Resolución**: El middleware global `ResolveTenant` intercepta la petición y busca el tenant correspondiente en la base de datos por:
  1. Parámetro de URL `?tenant=slug` (conveniente para desarrollo/API).
  2. Headers `X-Tenant-Slug` o `X-Tenant-Id`.
  3. Nombre de host/dominio de la petición (mapeado en la tabla `domains`).
- **Aislamiento**: El trait `HasTenant` registra un global scope (`TenantScope`) en los modelos Eloquent de negocio que añade automáticamente la restricción `where tenant_id = tenant_id` en todas las consultas.
- **Asociación**: El mismo trait asigna de forma automática el `tenant_id` activo del request al guardar nuevos registros.
- **Filament**: Se configuró la integración nativa de Filament Tenancy en `AdminPanelProvider` y el contrato `HasTenants` en el modelo `User`.
