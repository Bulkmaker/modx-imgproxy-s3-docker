# MODX + imgproxy + S3 на Docker

Готовый Docker-стек для MODX 3, где **оригиналы картинок лежат на S3**, а
**ресайз, WebP/AVIF и кеширование** делает [imgproxy](https://imgproxy.net) на
лету — без единой строчки обработки изображений в коде сайта.

Картинку запрашивают по «красивому» URL `/img/w:640/path/to/photo.jpg`, nginx
кеширует результат и проксирует в imgproxy, тот берёт оригинал из S3, ужимает и
конвертит в WebP. Итог: **−80…95 % веса** картинок, кеш на 30 дней, диск VPS не
пухнет (оригиналы на дешёвом S3, локально — только небольшой кеш с потолком).

> Это шаблон-стартер. Сам MODX не входит в репозиторий (его вы скачиваете на
> шаге 2) — поэтому репа лёгкая и чистая по лицензии.

---

## Архитектура

```
Браузер
   │  GET /img/w:640/projects/1/house.jpg
   ▼
┌─────────────────── контейнер app ───────────────────┐
│  nginx                                               │
│   • кеширует результат (proxy_cache, 7 дней)         │
│   • ставит браузеру Cache-Control: 30 дней           │
│   • rewrite → команда imgproxy                       │
│   • php-fpm + MODX (для обычных страниц)             │
└──────────────────────────┬───────────────────────────┘
                           │  http://imgproxy:8080  (по имени сервиса в сети compose)
                           ▼
                     контейнер imgproxy (Go)
                           │  ресайз + WebP/AVIF
                           ▼
                          S3  ── только оригиналы
```

Три слоя кеша: **браузер (30 дней) → nginx (7 дней) → imgproxy лезет в S3 один
раз на картинку+размер.** Контейнеры `app` и `imgproxy` живут в одной сети
docker-compose и видят друг друга по имени — поэтому nginx обращается к
`http://imgproxy:8080` без публикации портов imgproxy наружу.

---

## Что внутри

| Сервис | Образ | Роль |
|--------|-------|------|
| `app` | свой (php:8.3-fpm + nginx) | MODX: php-fpm + nginx с маршрутом `/img/` |
| `imgproxy` | `darthsim/imgproxy` | ресайз/WebP/AVIF из S3 |
| `db` | `mysql:8` | база MODX |
| `phpmyadmin` | `phpmyadmin` | удобно при установке (можно убрать) |

```
.
├── docker-compose.yml         # локальный/базовый стек (порты публикуются напрямую)
├── docker-compose.prod.yml    # прод-оверлей: ingress через Traefik + TLS
├── .env.example
├── docker/
│   └── php/
│       ├── Dockerfile        # php-fpm 8.3 + nginx + GD(webp) + envsubst
│       ├── nginx.conf        # MODX friendly URLs + маршрут /img/ + кеш + resolver
│       ├── entrypoint.sh     # envsubst ${S3_BUCKET}, ждёт БД, чинит site_url, права
│       └── php.ini
├── examples/
│   └── snippets/
│       └── imgproxy.php       # сниппет-обёртка для генерации /img/ URL
├── traefik/
│   └── docker-compose.yml     # минимальный Traefik v3 (если своего ещё нет)
└── modx/                      # сюда вы кладёте MODX (в git не коммитится)
```

---

## Требования

- Docker + Docker Compose
- S3-совместимое хранилище (AWS S3, Backblaze B2, Beget Cloud, Selectel, MinIO…)
  с бакетом, куда вы будете складывать оригиналы картинок
- Ключи доступа к этому бакету

---

## Быстрый старт

### 1. Клонировать и настроить `.env`

```bash
git clone https://github.com/Bulkmaker/modx-imgproxy-s3-docker.git
cd modx-imgproxy-s3-docker
cp .env.example .env
```

Откройте `.env` и заполните как минимум блок S3:

```env
S3_BUCKET=ваш-бакет
IMGPROXY_S3_ENDPOINT=https://s3.вашхостинг.com
S3_ACCESS_KEY_ID=...
S3_SECRET_ACCESS_KEY=...
S3_REGION=ru-1
```

`S3_BUCKET` автоматически подставится в nginx-конфиг при старте контейнера
(через `envsubst`), руками править конфиг не нужно.

### 2. Положить MODX

Скачайте [MODX 3](https://modx.com/download) и распакуйте его содержимое в папку
`modx/` так, чтобы рядом оказались `index.php`, `core/`, `manager/`, `setup/`:

```bash
curl -L -o modx.zip https://modx.com/download/direct?id=modx-3.x.x-pl.zip
unzip modx.zip -d /tmp/modx && cp -R /tmp/modx/modx-3.*/. modx/
```

### 3. Запустить стек

```bash
docker compose up -d --build
```

- сайт/установщик — http://localhost:8080
- phpMyAdmin — http://localhost:8081

### 4. Установить MODX

Откройте http://localhost:8080/setup и пройдите установку. Параметры БД берутся
из `.env`:

| Поле | Значение |
|------|----------|
| Database host | `db` |
| Database name | `modx` (`DB_NAME`) |
| Database user | `modx` (`DB_USER`) |
| Database password | `modx` (`DB_PASS`) |
| Table prefix | `modx_` (`TABLE_PREFIX`) |

После установки удалите папку `setup/` (как требует MODX).

### 5. Добавить сниппет imgproxy

Создайте в MODX сниппет с именем `imgproxy` и вставьте код из
[`examples/snippets/imgproxy.php`](examples/snippets/imgproxy.php). В системной
настройке `imgproxy_base` укажите публичный URL вашего хранилища (CDN-домен
бакета), например `https://media.вашсайт.ru/` — или поправьте константу в коде
сниппета.

### 6. Использовать в шаблонах

```smarty
{* было — полноразмерный оригинал напрямую с S3 *}
<img src="{$file.url}">

{* стало — webp 640px, ресайз, кеш 30 дней *}
<img src="{'imgproxy' | snippet : ['src' => $file.url, 'w' => 640]}">
```

Обёртка безопасна: ссылки на ваш бакет она оптимизирует, а локальные пути, SVG и
внешние URL возвращает без изменений — можно применять ко всем `<img>` подряд.

---

## Маршрут `/img/` — форматы URL

| URL | Что делает |
|-----|------------|
| `/img/w:640/<path>` | ресайз по ширине 640 (высота авто), WebP |
| `/img/w:640:h:480/<path>` | ресайз с кропом (fill) 640×480, WebP |
| `/img/w:640:h:480:q:90/<path>` | то же + качество 90 |
| `/img/orig/<path>` | оригинал без обработки |

`<path>` — путь объекта внутри бакета (то, что идёт после имени бакета в S3).

---

## Проверка

```bash
# через imgproxy — webp, лёгкий, с кешем
curl -sI http://localhost:8080/img/w:640/projects/1/house.jpg \
  | grep -iE 'content-type|content-length|cache-control|x-cache'
```

Ожидаемо:

```
content-type: image/webp
content-length: 62464              # вместо ~350 КБ оригинала
cache-control: max-age=2592000     # 30 дней
x-cache-status: HIT                # со второго запроса — из кеша nginx
```

---

## Продакшн за Traefik (TLS)

Базовый `docker-compose.yml` публикует порт `app` напрямую — удобно локально. На
проде ingress и TLS обычно отдают [Traefik](https://traefik.io). В репозитории
для этого есть оверлей `docker-compose.prod.yml` (лейблы роутинга + сертификат
Let's Encrypt) и минимальный Traefik в `traefik/`.

```bash
# 1. Общая сеть для Traefik и сайтов (один раз на сервере)
docker network create web

# 2. Поднять Traefik (если своего ещё нет)
cd traefik
cp ../.env.example .env        # задайте ACME_EMAIL=you@example.com
docker compose up -d
cd ..

# 3. В .env сайта указать домен
#    DOMAIN=ваш-домен.ru

# 4. Поднять сайт с прод-оверлеем (Traefik сам выпустит TLS-сертификат)
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
```

Что делает оверлей:

- убирает публикацию портов `app`/`phpmyadmin` (наружу пускает только Traefik);
- подключает `app` к внешней сети `web` и вешает Traefik-лейблы
  (`Host(\`${DOMAIN}\`)`, entrypoint `websecure`, certresolver `letsencrypt`);
- внутри `app` nginx по-прежнему слушает `:80`, Traefik проксирует на него.

> **Один imgproxy на несколько сайтов.** Если на сервере несколько проектов,
> вынесите `imgproxy` в сеть `web` и уберите его из стека каждого сайта — все
> будут ходить к общему `http://imgproxy:8080`. nginx уже резолвит upstream в
> рантайме (`resolver 127.0.0.11`), так что переживёт перезапуск imgproxy.

## Почему imgproxy, а не phpThumbOf / встроенный ресайз CMS

| | Встроенный ресайз (phpThumbOf и пр.) | imgproxy |
|---|---|---|
| **Источник** | ждёт локальный файл; читать из S3 — костыль | нативно читает `s3://…` |
| **Нагрузка** | крутится в PHP-воркере, ест CPU приложения | отдельный Go-сервис, изолирован, очень быстрый |
| **Диск** | пишет превью на локальный диск (то, от чего уходим) | кеш в volume с потолком и авто-очисткой |
| **Форматы** | WebP/AVIF зависят от сборки GD/Imagick | WebP/AVIF из коробки |
| **Размеры** | надо заранее плодить наборы превью | любой размер на лету из одного оригинала |
| **Связность** | завязан на конкретную CMS | один сервис на все сайты VPS |

---

## Нюансы и грабли

- **Кеш — регенерируемый volume, не бэкапьте его.** `imgproxy_cache` восстановится
  сам из оригиналов; `max_size=500m inactive=7d` держит его в узде. `core/cache`
  MODX смонтирован как `tmpfs` (в памяти) — тоже не попадает в бэкапы.
- **Безопасность URL.** Здесь используется `/unsafe/` (без подписи) — это
  нормально, пока imgproxy доступен только внутри Docker-сети (наружу не
  опубликован, ходит только nginx). Если выставляете imgproxy наружу — включите
  подпись: задайте `IMGPROXY_KEY` / `IMGPROXY_SALT` и подписывайте URL.
- **Размер под показ, а не «на всякий случай».** Подбирайте `w:` под реальный
  размер в вёрстке (с запасом ×2 под Retina) — это и есть основная экономия.
- **Один imgproxy на несколько сайтов.** Вынесите сервис `imgproxy` в общую
  внешнюю сеть (`networks: web: external: true`) и подключите к ней `app` каждого
  сайта — все будут ходить к одному `http://imgproxy:8080`.
- **Прод за обратным прокси.** Для TLS и нескольких сайтов используйте оверлей
  `docker-compose.prod.yml` с Traefik — см. раздел «Продакшн за Traefik».

---

## Лицензия

MIT — см. [LICENSE](LICENSE). MODX и imgproxy распространяются под собственными
лицензиями.
