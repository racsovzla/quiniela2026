---
title: Quiniela FIFA 2026
emoji: ⚽
colorFrom: green
colorTo: blue
sdk: docker
app_port: 7860
pinned: false
---

# Quiniela FIFA 2026 (Symfony + PostgreSQL)

Implementacion inicial MVP.

## Incluye hoy

- Registro por correo + password con captcha matematico local.
- Verificacion por codigo de 6 digitos enviado por correo.
- Estado de usuario pendiente/aprobado (sin rechazo definitivo).
- Predicciones por partido con bloqueo de edicion 5 minutos antes del kickoff (UTC).
- Leaderboard con puntaje 3/1/0 y desempate por:
  1) mas aciertos exactos
  2) orden alfabetico
- Panel admin para:
  - aprobar usuarios
  - crear/editar grupos
  - crear/editar equipos
  - crear/editar partidos
- Leaderboard global y leaderboard por grupo.
- Comando para crear admin inicial.

## Requisitos locales

- PHP 8.4+
- Composer
- PostgreSQL en localhost:5432
- DB: quiniela2026
- Usuario DB: ver `.env.local` (ej. `postgres`)
- SMTP dev recomendado: Mailpit o Mailhog (ejemplo: 127.0.0.1:1025)

## Entornos y secretos

- Orden de carga de variables Symfony:
  1) `.env`
  2) `.env.local`
  3) `.env.$APP_ENV`
  4) `.env.$APP_ENV.local`
- En local usa `.env.local` para secretos y overrides de tu maquina.
- No guardes secretos reales de produccion en archivos versionados.
- En produccion define secretos como variables del servidor (o Symfony secrets).

## Configuracion rapida

1. Copia `.env.local.example` a `.env.local` y ajusta variables:
    - MAILER_FROM_EMAIL
    - MAILER_FROM_NAME
2. Instala dependencias:

```bash
composer install
```

3. Ejecuta migraciones:

```bash
php bin/console doctrine:migrations:migrate --no-interaction
```

4. Crea admin inicial:

```bash
php bin/console app:create-admin "Admin" "admin@quiniela.local" "Admin2026!Strong"
```

5. Arranca servidor local:

```bash
symfony serve -d
```

6. Crear grupos A-L de fase de grupos:

```bash
php bin/console app:seed-group-stage
```

7. Importar equipos oficiales por grupo desde CSV:

```bash
php bin/console app:import-group-teams data/group_teams_template.csv
```

8. Importar partidos de fase de grupos desde CSV:

```bash
php bin/console app:import-group-fixtures data/worldcup2026_group_fixtures.csv
```

9. Sincronizar horarios UTC oficiales de FIFA (actualiza existentes de forma idempotente):

```bash
php bin/console app:sync-official-group-fixtures
```

Formato CSV:

```csv
group_code,kickoff_at_utc,home_code,away_code
A,2026-06-11 19:00,MEX,RSA
A,2026-06-12 02:00,KOR,CZE
```

## Flujos

- Registro correo: /register
- Login: /login
- Validar codigo: /verify/code
- Predicciones: /predictions
- Leaderboard: /leaderboard
- Admin: /admin

## Dataset 2026 cargado

Actualmente solo están cargados los partidos de la fase de grupos. A medida que se definan los partidos de las fases eliminatorias (round of 32, round of 16, cuartos, semifinal, tercer puesto y final) se irán agregando.

- Equipos por grupo: data/worldcup2026_group_teams.csv
- Partidos fase de grupos: data/worldcup2026_group_fixtures.csv

Fuente utilizada para construir el dataset:
- https://www.fifa.com/en/articles/match-schedule-fixtures-results-teams-stadiums

## Notas de seguridad

- CSRF activo en login, aprobacion admin y guardado de predicciones.
- Regla de tiempo se valida en backend (no solo UI).
- En prod usa `APP_ENV=prod` y `APP_DEBUG=0`.
- En prod usa usuario DB dedicado (no `root`).
- La app ahora redirige a HTTPS en `prod` como fallback. Aun asi, el redirect principal debe vivir en Apache/Nginx para cubrir assets y requests antes de PHP.

## SMTP (Gmail)

- Para Gmail personal usa App Password (2FA obligatorio).
- `MAILER_FROM_EMAIL` normalmente debe coincidir con la cuenta autenticada en Gmail para evitar rechazos o reescritura del remitente.
- Ejemplo DSN para produccion:

```dotenv
MAILER_DSN="smtp://tu_cuenta@gmail.com:TU_APP_PASSWORD@smtp.gmail.com:587?encryption=tls&auth_mode=login"
MAILER_FROM_EMAIL=tu_cuenta@gmail.com
MAILER_FROM_NAME="Quiniela 2026"
```

- Prueba tecnica de envio:

```bash
php bin/console mailer:test tu_correo@dominio.com
```

- Prueba funcional:
  1) Registrar usuario nuevo en `/register`
  2) Confirmar recepcion de codigo
  3) Validar en `/verify/code`

## Deploy clasico (Apache + PHP)

- `DocumentRoot` debe apuntar a `public/`, no a raiz proyecto.
- Activa `mod_rewrite` y TLS antes de exponer sitio.
- Define variables de prod en servidor, no en archivos versionados.
- Usa mismo correo en `MAILER_FROM_EMAIL` y cuenta SMTP autenticada si sales por Gmail.
- Si app corre detras de proxy o balanceador, define `SYMFONY_TRUSTED_PROXIES` y `SYMFONY_TRUSTED_HOSTS` para que Symfony detecte bien HTTPS y host real.
- Tienes plantillas base listas en `deploy/apache/quiniela2026.conf` y `deploy/prod.env.example`.

Ejemplo minimo de variables de entorno para prod:

```dotenv
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=pon_aqui_un_secreto_largo_y_random
DATABASE_URL="postgresql://app_user:STRONG_PASSWORD@127.0.0.1:5432/quiniela2026?serverVersion=16&charset=utf8"
MAILER_DSN="smtp://tu_cuenta@gmail.com:TU_APP_PASSWORD@smtp.gmail.com:587?encryption=tls&auth_mode=login"
MAILER_FROM_EMAIL=tu_cuenta@gmail.com
MAILER_FROM_NAME="Quiniela 2026"
# Solo si hay proxy inverso / load balancer:
# SYMFONY_TRUSTED_PROXIES=127.0.0.1,REMOTE_ADDR
# SYMFONY_TRUSTED_HOSTS='^(quiniela2026\.com|www\.quiniela2026\.com)$'
```

Ejemplo base de VirtualHost Apache:

```apache
<VirtualHost *:80>
  ServerName quiniela2026.com
  ServerAlias www.quiniela2026.com
  Redirect permanent / https://quiniela2026.com/
</VirtualHost>

<IfModule mod_ssl.c>
<VirtualHost *:443>
  ServerName quiniela2026.com
  ServerAlias www.quiniela2026.com

  DocumentRoot /var/www/quiniela2026/public

  <Directory /var/www/quiniela2026/public>
    AllowOverride All
    Require all granted
    FallbackResource /index.php
  </Directory>

  SSLEngine on
  SSLCertificateFile /ruta/fullchain.pem
  SSLCertificateKeyFile /ruta/privkey.pem

  SetEnv APP_ENV prod
  SetEnv APP_DEBUG 0

  ErrorLog ${APACHE_LOG_DIR}/quiniela2026_error.log
  CustomLog ${APACHE_LOG_DIR}/quiniela2026_access.log combined
</VirtualHost>
</IfModule>
```

Checklist rapido post-deploy:

1. `php bin/console about --env=prod`
2. `php bin/console doctrine:migrations:migrate --no-interaction --env=prod`
3. `php bin/console cache:clear --env=prod`
4. `php bin/console cache:warmup --env=prod`
5. `php bin/console mailer:test tu_correo@dominio.com --env=prod`
6. Abrir sitio por `https://...` y confirmar que `http://...` redirige a HTTPS.
7. Verificar registro, llegada de correo y login.

## Deploy en Hugging Face Spaces

La app corre como **Docker Space** (`sdk: docker`, puerto `7860`). El repo incluye `Dockerfile` y `.dockerignore`.

### Sync automatico

Cada push a `master` en GitHub dispara `.github/workflows/huggingface.yml`, que sincroniza el codigo al Space `racsovzla/quiniela2026`.

Requisito: secret `HF_TOKEN` en GitHub con permiso de escritura al Space.

### Variables de entorno en el Space

Define en **Settings → Variables and secrets** del Space (ver tambien `deploy/prod.env.example`):

- `APP_ENV=prod`
- `APP_DEBUG=0`
- `APP_SECRET` (random largo)
- `DATABASE_URL` (Postgres externo, ej. Neon/Supabase)
- `MAILER_DSN`, `MAILER_FROM_EMAIL`, `MAILER_FROM_NAME`
- `CALLMEBOT_PHONE`, `CALLMEBOT_APIKEY` (WhatsApp desde el panel admin)
- `MESSENGER_TRANSPORT_DSN=sync://`

Estas variables alimentan la app web (incluido el boton **Probar WhatsApp** en `/admin`).

### Tareas programadas (GitHub Actions)

Los cron jobs no corren dentro del Space; se ejecutan via GitHub Actions + cron-job.org:

- `send-prediction-emails.yml` — correos y WhatsApp 5 min antes de cada partido
- `sync-live-fixture-scores.yml` — sincroniza marcadores en vivo

Los mismos secrets deben existir en **GitHub** (repo → Settings → Secrets), incluyendo `CALLMEBOT_PHONE` y `CALLMEBOT_APIKEY`, y el workflow correspondiente debe estar en `master`.

### Setup inicial (consola del Space)

```bash
php bin/console doctrine:migrations:migrate --no-interaction --env=prod
php bin/console app:seed-group-stage --env=prod
php bin/console app:import-group-teams data/worldcup2026_group_teams.csv --env=prod
php bin/console app:import-group-fixtures data/worldcup2026_group_fixtures.csv --env=prod
php bin/console app:create-admin "Admin" "admin@tu-dominio.com" "PasswordSegura2026!"
```

### Verificacion rapida

1. Registro por correo completo.
2. Crear y bloquear predicciones segun ventana de tiempo.
3. Leaderboard responde.
4. Boton **Probar WhatsApp** en admin llega al telefono.
5. Tras el proximo partido, revisar log de GitHub Actions para confirmar envio de correos/WhatsApp.

## Siguiente iteracion recomendada

- Auditoria de cambios de predicciones (historial por usuario/partido).
- Notificaciones (recordatorio previo al cierre de ventana).
- Premios de temporada (insignias persistentes por hitos).
- Mejoras adicionales de accesibilidad (navegacion con teclado y foco en modales).

## Mejoras implementadas en esta iteracion

- Verificacion por codigo reforzada con:
  - CSRF en validar y reenvio
  - rate limit de intentos de validacion
  - rate limit de reenvio de codigo
  - flujo de reenvio para evitar bloqueo por codigo expirado
- Login reforzado con `login_throttling`.
- Predicciones con estado por partido (abierta/cerrada) y countdown de cierre en UI.
- Leaderboard actualizado:
  - ranking semanal (ultimos 7 dias)
  - insignias por desempeno
  - racha activa por usuario
  - tarjeta de "Pick del dia"
- Tests agregados para auth verify, scoring y ventana de prediccion.
