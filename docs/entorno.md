# Entorno de desarrollo

- Windows con WSL2, distro Ubuntu 20.04 (sin soporte estándar, migrar a 24.04 cuando convenga). git 2.50 desde el PPA git-core.
- Repo en ~/pyme-contable dentro de WSL, nunca en /mnt/c. LF en todo el repo con .gitattributes. Edición con VS Code abierto desde WSL.
- Docker Desktop con integración WSL. Los comandos de docker compose se corren siempre desde la raíz del repo.
- Imágenes: postgres:17-bookworm y php:8.4-fpm-bookworm (Pest exige PHP 8.4).
- Postgres publicado en 127.0.0.1:${PG_HOST_PORT}. En local es 5433 porque hay otro Postgres en 5432. Dentro de la red de Compose siempre es postgres:5432.
- .env de la raíz (no versionado) con POSTGRES_PASSWORD, PYME_OWNER_PASSWORD, PG_HOST_PORT, APP_HOST_PORT, UID y GID. Es la única fuente de la contraseña de la base para Compose.
- Sin Node ni PHP instalados en WSL: todo corre en contenedores. Node con la imagen node:24-bookworm-slim (jsdom exige Node 22.22.2 o 24.15 o superior) mediante el alias npmd, definido en ~/.bashrc y usado desde la raíz del repo:
  alias npmd='docker run --rm -it -u "$(id -u):$(id -g)" -e HOME=/tmp -e npm_config_update_notifier=false -v "$PWD/frontend":/app -w /app node:24-bookworm-slim'
- El PATH de WSL incluye el npm de Windows (/mnt/c/Program Files/nodejs/npm). Nunca usar npm suelto en el repo, siempre npmd.
