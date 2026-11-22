================================================================================
WP SMART SEARCH - DEV NOTES
Журнал для разработчиков и моделей. Обновляется при каждой итерации.
================================================================================


СОДЕРЖАНИЕ
----------
  1. Правила
  2. Архитектура
  3. Админка (структура)
  4. Поток поиска
  5. Ключевые решения
  6. Безопасность
  7. IOC (индикаторы компрометации)
  8. Расширение запроса, гибридное ранжирование, автодополнение
  9. История версий
 10. Roadmap
 11. Настройка nginx
 12. Известные ограничения
 13. Как обновлять плагин


================================================================================
1. ПРАВИЛА
================================================================================

1.1. Схема БД - только через миграции.
     Любое изменение таблиц (wp_wssf_index, wp_wssf_terms,
     wp_wssf_*) / опций оформляется как отдельная миграция в
     includes/class-migrations.php. Каждая миграция ИДЕМПОТЕНТНА.
     Прямое изменение опции wssf_schema_version запрещено.

1.2. CSS и JS - только inline.
     Все стили и скрипты собираются через WSSF_Assets и подключаются
     через wp_add_inline_style / wp_add_inline_script.

1.3. Никаких внешних API и внешних библиотек.
     Единственное исключение - CleanTalk. Всё остальное локально:
     PRF, fuzzy, RRF, IDF², term balance, автодополнение,
     MinHash - на чистом PHP.

1.4. Версионирование.
     Поднимать WSSF_VERSION при любом изменении. При изменении схемы
     БД - запись в WSSF_Migrations::all().

1.5. Кэш - только через WSSF_Cache. TTL обязателен.

1.6. Префиксы: WSSF_*, wssf_*, _wssf_ct_lock_*, _wssf_rl_*.

1.7. Внутренние файлы - в _internal/ (защищена .htaccess).

1.8. Безопасность (OWASP Top 10).
     Все AJAX и admin-post: nonce + capability. SQL: prepare().
     Имена таблиц: regex ^[a-zA-Z0-9_]+$. Вывод: esc_*().

1.9. IOC - целостность (см. раздел 7).

1.10. Только чистый PHP.

1.11. Админка - контроллер тонкий, действия в class-admin-actions.php.


================================================================================
2. АРХИТЕКТУРА
================================================================================

  wp-smart-search-frontend.php          bootstrap
    |
    +-- WSSF_Plugin                     DI-контейнер
          |
          +-- WSSF_Cache                Redis + transient
          +-- WSSF_Security             nonce, rate-limit, IP
          +-- WSSF_Tokenizer            IOC-плейсхолдеры + синонимы
          |     +-- WSSF_Morphology
          +-- WSSF_Query_Parser
          +-- WSSF_Search_Engine        FULLTEXT + LIKE + гибридный
          |                             + IDF² + exact + pt weights
          |                             + term balance + phrase + OR
          |                             + полное кеширование FULLTEXT
          |                             + similar_posts (MinHash)
          +-- WSSF_Semantic_Search      PRF + fuzzy
          |     +-- WSSF_PRF_Expander
          |     +-- WSSF_Fuzzy_Index
          |     +-- WSSF_Rank_Fusion
          +-- WSSF_Autocomplete         prefix + fuzzy (4.6.0)
          +-- WSSF_MinHash              near-duplicate (5.2.0)
          +-- WSSF_Security_Headers     CSP + базовые заголовки (5.2.0)
          +-- WSSF_CleanTalk
          +-- WSSF_Ajax_Handler         search + suggest + history
          +-- WSSF_Assets               inline CSS/JS
          +-- WSSF_Hooks
          +-- WSSF_Migrations

Админ:

  admin/class-admin-settings.php        контроллер + геттеры
  admin/class-admin-actions.php         handle_*, ajax_reindex, статистика
  admin/class-admin-diagnostics.php     диагностика
  admin/view-status.php
  admin/view-settings.php
  admin/view-semantic.php
  admin/view-ranking.php
  admin/view-diagnostics.php
  admin/view-maintenance.php


================================================================================
3. АДМИНКА
================================================================================

Табы: status (default), general, semantic, ranking, diagnostics, maintenance.

5.2.0:
  - view-status.php: убран блок «Фоновая индексация (cron)»,
    убрано предупреждение DISABLE_WP_CRON. Добавлены строки
    MinHash и Security Headers в «Расширение запроса».
  - view-maintenance.php: добавлены блоки
    «Фоновая индексация (WP Cron)» с кнопкой «Запустить сейчас»
    и «IOC map» с кнопкой «Перестроить IOC map».


================================================================================
4. ПОТОК ПОИСКА
================================================================================

  1. POST admin-ajax.php action=wssf_search.
  2. WSSF_Security::verify_request() + rate_limit_ok().
  3. WSSF_Query_Parser::parse().
  4. WSSF_CleanTalk::check_ajax().
  5. WSSF_Search_Engine::search_fulltext():
     a) IOC → LIKE;
     b) проверка Redis-кеша FULLTEXT (wssf_ft_v1_*);
     c) BM25 (top-200) с фильтром i.post_type;
     d) apply_boosts (IDF²/exact/pt/balance);
     e) filter_by_phrases;
     f) OR fallback при пустом результате;
     g) PRF + fuzzy + RRF;
     h) пагинация + hydrate;
     i) get_similar_posts (MinHash) → similar_posts;
     j) кеширование финального ответа в Redis на 5 мин.
  6. JSON-ответ (с similar_posts).

Автодополнение (4.6.0):

  1. input → debounce 200ms.
  2. POST action=wssf_suggest.
  3. WSSF_Autocomplete::suggest():
     a) prefix из wp_wssf_terms (LIKE 'q%');
     b) fuzzy через WSSF_Fuzzy_Index (если prefix пуст);
     c) приоритизация IOC-типов;
     d) кеш на 5 мин.
  4. Рендер dropdown, клавиатурная навигация.


================================================================================
5. КЛЮЧЕВЫЕ РЕШЕНИЯ
================================================================================

5.1. Стемминг (Porter EN, Lingua RU), индекс wp_wssf_index,
     LIKE-fallback, pure IOC → LIKE.

5.2. Generation counter - bump_generation() при инвалидации.

5.3. Локальное расширение: PRF + синонимы (теги + ручные) + fuzzy.

5.4. CleanTalk - вердикты с TTL, атомарный лок, rate-limit.

5.5. IOC - не стеммятся, целостность через плейсхолдеры.

5.6. Гибридное ранжирование - RRF (k=60).

5.7. Бусты: IDF², exact match, post_type weights, term balance,
     phrase SQL, OR fallback.

5.8. Партиционирование индекса (4.6.0) - через колонку post_type
     с индексом post_type_idx. См. раздел 8.8.

5.9. Автодополнение (4.6.0) - таблица wp_wssf_terms + prefix scan
     через PRIMARY KEY (term). См. раздел 8.9.

5.10. Кеширование FULLTEXT (4.6.0) - в WSSF_Cache (Redis или
      transient fallback), TTL 300 сек, ключ с generation.

5.11. MinHash (5.2.0) - 128-элементная signature по 3-граммам
      слов (шинглы). Jaccard-сходство двух signature ≈
      Jaccard множеств шинглов. Хранится в wp_wssf_minhash (BLOB,
      PRIMARY KEY post_id). Используется:
        - при индексации — signature сохраняется;
        - при выдаче — get_similar_posts() находит near-duplicate
          (Jaccard ≥ 0.5) для top-1 результата.
      Отдельный кеш wssf_mh_sim_v1_{gen}_{post_id}, TTL 1 час.

5.12. Security Headers (5.2.0) - X-Content-Type-Options,
      X-Frame-Options, Referrer-Policy, Permissions-Policy
      + CSP (off / report_only / enforce). Отправляются на
      send_headers с приоритетом 5. CSP по умолчанию off.
      Настраиваемые домены: script-src, style-src, img-src,
      connect-src.

5.13. Шардирование отложено (5.2.0). wp_wssf_index содержит
      FULLTEXT, MySQL не поддерживает PARTITION BY на таблицах
      с FULLTEXT. Реальный шардинг = отдельные таблицы +
      FULLTEXT-merge через RRF — теряет сравнимость BM25-score
      между шардами и даёт выигрыш только при >500k строк.
      На 25k строк — оверкилл. Отложено до 6.x.


================================================================================
6. БЕЗОПАСНОСТЬ
================================================================================

Карта OWASP - в _internal/SECURITY-AUDIT.md.

Кратко: regex-валидация таблиц, sanitize_text_field, лимиты
массивов, очистка BOOLEAN MODE, error_log с sanitize.

Автодополнение: rate_limit_ok, verify_request, санитайз q,
лимит 8 подсказок, без раскрытия внутренних данных (только
term + post_count + type).

Security Headers (5.2.0): CSP по умолчанию off; report-only
рекомендуется первым шагом. Настраиваемые домены проходят
санитайз по regex ^(https?://|\*|data:|[a-z0-9.\-]+\.[a-z]{2,}).


================================================================================
7. IOC (ИНДИКАТОРЫ КОМПРОМЕТАЦИИ)
================================================================================

7.1. Типы: IPv4/IPv6, URL, домены, email, MD5/SHA1/SHA256/SHA512/
     Imphash/JA3/SSDeep, крипто-кошельки, CVE/BDU/ATT&CK/ASN,
     Windows Registry, GUID mutex, Onion, I2P, Domain:Port.

7.2. IOC проходят через конвейер как цельные токены (плейсхолдеры).

7.3. Pure IOC → LIKE. Mixed → BOOLEAN с фразой.

7.4. SHA512 (128 символов) → только LIKE.

7.5. В автодополнении IOC приоритизируются по типу
     (cve, bdu, attck, asn, ipv4, ipv6, hashes, url).

7.6. IOC map (5.1.0) - обратный индекс IOC → post_id для
     co-occurrence. Наполняется при индексации (WSSF_IOC_Map).
     Перестраивается вручную из Обслуживания.


================================================================================
8. РАСШИРЕНИЕ ЗАПРОСА, РАНЖИРОВАНИЕ, АВТОДОПОЛНЕНИЕ
================================================================================

8.1. PRF - WSSF_PRF_Expander (Rocchio α=1.0 β=0.75; RM3 λ=0.65 μ=1500).
     До 8 униграмм + до 5 биграмм из топ-20.

8.2. Fuzzy - WSSF_Fuzzy_Index (BK-tree + n-gram).
     Только при total=0. Словарь: топ-5000 title_stem+tags_stem.

8.3. RRF - WSSF_Rank_Fusion, k=60.

8.4. Бусты (4.5.0):
     - IDF² — умножает на (1 + avg_idf), idf = log((N+1)/(df+1)),
       кеш на 1 час (ключ wssf_idf_*);
     - exact match — ×5 title, ×2 content;
     - post type weights — опция wssf_post_type_weights (0.0-10.0);
     - term balance — GM/AM, опция wssf_term_balance_power (0=выкл);
     - phrase SQL — "фраза" → LIKE-фильтр;
     - OR fallback — NATURAL LANGUAGE MODE при пустом AND.

8.5. Синонимы - WSSF_Admin_Settings::get_synonyms() (wssf_synonyms).
     Формат "k=v;k=v2;k2=v3". В BOOLEAN добавляются опционально.

8.6. Порядок search_fulltext(): IOC→LIKE → FT-cache check →
     BM25 → boosts → phrases → OR fallback → PRF → fuzzy → RRF →
     пагинация → MinHash similar → cache set.

8.7. Настройки:
     - wssf_semantic_* — "Расширение запроса";
     - wssf_synonyms / _term_balance_power / _post_type_weights — "Ранжирование";
     - wssf_autocomplete_* — "Настройки" (общие);
     - wssf_minhash_enabled — "Настройки" (5.2.0);
     - wssf_hdr_* / wssf_csp_* — "Настройки" (5.2.0).

8.8. Партиционирование (4.6.0).
     Реализовано через колонку `post_type` в wp_wssf_index +
     обычный индекс `post_type_idx`.

     Почему НЕ физическое шардирование (отдельные таблицы на тип):
       - на 25k строк выгода < 5%;
       - сложность кода ×3 (все queries в цикле по shard'ам);
       - риск orphaned data при переносе;
       - FULLTEXT-merge across shards требует RRF → ломает
         сравнимость BM25-score.

     Что даёт post_type column:
       - фильтр по типу ДО join к wp_posts (query planner
         использует post_type_idx);
       - FULLTEXT остаётся на одной таблице (нет merge-overhead);
       - полная обратная совместимость.

     Если в будущем понадобится физическое шардирование —
     миграция 4.7.0 может добавить его через опцию
     wssf_shard_post_types без изменения публичного API.

8.9. Автодополнение (4.6.0).
     Источник — таблица `wp_wssf_terms`:
       - term VARCHAR(200) PRIMARY KEY (стемы + IOC целиком);
       - post_count (для сортировки по популярности);
       - term_type (word/cve/bdu/attck/asn/ipv4/ipv6/url/sha*/md5).

     Наполнение:
       - при index_post() — upsert новых терминов +1;
       - при delete_post() — decrement / delete zero-count;
       - точный учёт через array_diff_key(old_terms, new_terms).

     Prefix-scan: SELECT ... WHERE term LIKE 'prefix%' — index scan,
     ~1ms на 50k строк.

     Fuzzy fallback: если 0 prefix → WSSF_Fuzzy_Index::find_alternatives
     (n-gram по тому же словарю).

     Кеш: wssf_ac_v1_{md5(q)}, TTL 300 сек, сбрасывается через
     bump_generation() при изменении индекса/настроек.

     После обновления до 4.6.0 нужна ПОЛНАЯ ПЕРЕИНДЕКСАЦИЯ,
     чтобы наполнить wp_wssf_terms. До этого автодополнение
     вернёт пустой список (не сломает поиск).

8.10. Redis-кеш FULLTEXT (4.6.0).
      WSSF_Cache уже использует Redis (если доступен) с fallback
      на transient. Ключ `wssf_ft_v1_{generation}_{md5(...)}`,
      TTL 300 сек.

      Инвалидация: bump_generation() при save_post/delete/настройках
      автоматически инвалидирует все кешированные ответы (generation
      в ключе).

      Что кешируется: финальный payload (items + total + page +
      total_pages + related_iocs + similar_posts) после всех
      бустов, phrase-фильтра и RRF.

      Что НЕ кешируется:
        - чистый IOC-запрос (идёт в search_content, у него свой кеш);
        - IDf (свой кеш на час);
        - PRF/fuzzy-словари (свои кеши);
        - MinHash similar (свой кеш wssf_mh_sim_v1_*).

8.11. MinHash (5.2.0).
      WSSF_MinHash:
        - HASH_COUNT = 128, SHINGLE_SIZE = 3, MIN_SIMILARITY = 0.5;
        - signature() → pack('N*', ...$mins) (BLOB 512 байт);
        - jaccard() → доля совпавших позиций signature;
        - similar_to(post_id) → берёт signature поста, сравнивает
          с MAX_CANDIDATES=500 последними записями, сортирует,
          берёт MAX_RESULTS=5;
        - кеш wssf_mh_sim_v1_{gen}_{post_id}, TTL 1 час.

      Хранение: wp_wssf_minhash (post_id PK, signature BLOB,
      updated_at DATETIME, KEY updated_idx).

      Вызов из search_fulltext → get_similar_posts($items):
        - top-1 → similar_to;
        - hydrate (title, url) через wp_posts;
        - формат: {id, title, url, similarity}.

      На фронте: assets/js/wssf-render-similar.js:
        - window.WSSF_RenderSimilar.section(items);
        - рендер в .wssf-similar с процентом совпадения;
        - стили в assets/css/wssf-similar.css.

8.12. Security Headers (5.2.0).
      WSSF_Security_Headers::register() — на send_headers приоритет 5
      (только для фронта, is_admin() пропускает).

      Заголовки:
        - X-Content-Type-Options: nosniff;
        - X-Frame-Options: SAMEORIGIN;
        - Referrer-Policy: strict-origin-when-cross-origin;
        - Permissions-Policy: geolocation=(), microphone=(),
          camera=(), payment=(), usb=();
        - CSP (опционально, по режиму wssf_csp_mode):
          off / report_only / enforce.

      CSP по умолчанию (off, но если включить):
        default-src 'self'; script-src 'self' 'unsafe-inline'
        'unsafe-eval' {extra}; style-src 'self' 'unsafe-inline'
        {extra}; img-src 'self' data: {extra}; font-src 'self'
        data:; connect-src 'self' {extra}; frame-ancestors 'self';
        base-uri 'self'; form-action 'self'.

      Настройки: wssf_hdr_enabled / wssf_hdr_xcto /
      wssf_hdr_xfo / wssf_hdr_referrer / wssf_hdr_permissions /
      wssf_csp_mode / wssf_csp_{script,style,img,connect}_src.


================================================================================
9. ИСТОРИЯ ВЕРСИЙ
================================================================================

5.2.0 - MinHash + Security Headers + фиксы
  - MinHash (near-duplicate detection):
    * includes/class-minhash.php (новый);
    * таблица wp_wssf_minhash (миграция 5.2.0);
    * WSSF_Search_Engine::get_similar_posts() → similar_posts
      в payload search_fulltext/search_content;
    * WSSF_Indexer::index_post / delete_post → MinHash update/delete;
    * assets/js/wssf-render-similar.js (новый);
    * assets/css/wssf-similar.css (новый);
    * настройка wssf_minhash_enabled.
  - Security Headers:
    * includes/class-security-headers.php (новый);
    * регистрация в WSSF_Hooks::register();
    * CSP (off/report_only/enforce);
    * настройки wssf_hdr_* и wssf_csp_*.
  - Фиксы:
    * Layout Corrector — убраны спецсимволы из has_cyr (из-за
      них не конвертировались слова с запятой/точкой:
      hj,jnf = «работа»);
    * Layout Corrector — has_vowels не проверяется для ru→en
      (блокировала тест → ntcn, проверка → ghdjthrf);
    * Cron — предупреждение DISABLE_WP_CRON убрано из UI;
    * Статус — кнопки «Запустить сейчас» и «Перестроить IOC map»
      перенесены в «Обслуживание».
  - Шардирование отложено до 6.x (см. 5.13/8.8).

5.1.0 - IOC map + related + cron
  - таблица wp_wssf_ioc_map (миграция 5.1.0);
  - WSSF_IOC_Map (co-occurrence: co_occurring, related_for_post);
  - кнопка «Перестроить IOC map» (перенесена в 5.2.0);
  - настройки wssf_ioc_map_enabled / wssf_related_enabled /
    wssf_related_max;
  - включён cron по умолчанию;
  - кеш-ключи bump: wssf_coc_v1_*, wssf_ioc_rel_v1_*.

5.0.0 - стоп-слова + YARA + cron
  - таблица wp_wssf_stopwords (миграция 5.0.0);
  - WSSF_Stopwords (ru/en, дефолтные списки в assets/stopwords/);
  - WSSF_Yara (извлечение имён правил из контента);
  - настройки wssf_cron_reindex_enabled/_interval/last_run,
    wssf_yara_enabled, wssf_stopwords_enabled;
  - стоп-слова проверяются в tokenize() после стемминга.

4.6.0 - автодополнение + партиционирование + Redis-кеш
  - Автодополнение при вводе (AJAX wssf_suggest):
    * WSSF_Autocomplete;
    * таблица wp_wssf_terms;
    * assets/js/wssf-autocomplete.js;
    * assets/css/wssf-autocomplete.css;
    * настройки wssf_autocomplete_enabled/_min_len/_max.
  - Партиционирование индекса: колонка post_type +
    индекс post_type_idx.
  - Redis-кеш FULLTEXT.
  - Миграция 4.6.0.

4.5.0 - расширенное ранжирование и синонимы
  - IDF², exact match, OR fallback.
  - Ручные синонимы, term balance, phrase SQL, post type weights.
  - Новый таб "Ранжирование".

4.4.0 - рефакторинг админки (5 табов).
4.3.0 - PRF + fuzzy + RRF.
4.2.1 - расширенные IOC.
4.2.0 - IOC support.
4.1.1 - security hardening.
4.1.0 - миграции + защита.
4.0.0 - рефакторинг + локализация.


================================================================================
10. ROADMAP
================================================================================

10.1. Ближайшие (5.x)

5.3.0 - Мультиязычность.
5.4.0 - PWA и офлайн-режим.

10.2. Средние (6.x)

6.0.0 - Мульти-хоп поиск и граф.
6.1.0 - Рекомендательная система.
6.2.0 - Физическое шардирование (опция wssf_shard_post_types)
        если понадобится при росте >500k строк.
6.3.0 - Background-индексация.

(Пункты 5.0.0/5.1.0/5.2.0 из старого roadmap переехали в
историю — MinHash, IOC map, security headers реализованы.)


================================================================================
11. НАСТРОЙКА NGINX
================================================================================

  location ~* /wp-content/plugins/wp-smart-search-frontend/_internal/ {
      deny all;
      return 403;
  }

  location ~* /wp-content/plugins/wp-smart-search-frontend/\.(git|idea|vscode|env) {
      deny all;
      return 403;
  }


================================================================================
12. ИЗВЕСТНЫЕ ОГРАНИЧЕНИЯ
================================================================================

12.1. Mixed IOC query (IP вида "1.2.3.4" в mixed) — работает
      только как pure IOC (LIKE).

12.2. SHA512 в mixed query — только через LIKE-fallback.

12.3. GUID mutex — точное LIKE, ищите в том же формате.

12.4. get_filtered_post_ids_fulltext() → null при >5000 постов.

12.5. BK-tree строится из топ-5000 title_stem+tags_stem.

12.6. RRF в hybrid-режиме ограничен top-200 на список.

12.7. CleanTalk блокирует, если гостевой JS не выполнился.

12.8. CleanTalk API rate-limit 20/мин на scope
      (wssf_ct_api_rate_max).

12.9. IDf кешируется на час; при ручной правке индекса
      нужно очистить (Maintenance → Очистить кеш поиска).

12.10. Term balance — только для AND-запросов с ≥2 терминами.

12.11. Phrase SQL — LIKE по wp_posts, может быть медленным
       при очень больших индексах.

12.12. OR fallback — NATURAL LANGUAGE MODE, не учитывает
       синонимы из BOOLEAN.

12.13. Автодополнение после обновления до 4.6.0 требует
       полной переиндексации для наполнения wp_wssf_terms.

12.14. wp_wssf_terms хранит СТЕМЫ, не оригинальные формы слов.
       Для IOC (CVE, IP, hashes) это идентично raw-значению.
       Для русских слов пользователь увидит стем («уязвим»)
       вместо полной формы («уязвимость»). Приемлемо, т.к.
       основной кейс — IOC.

12.15. FULLTEXT cache TTL 300 сек. Сбрасывается через
       bump_generation() при save_post/delete/настройках.
       При массовых изменениях через SQL — очистить вручную.

12.16. Партиционирование через post_type column, не физическое
       шардирование. См. раздел 8.8.

12.17. При >500k строк индекса физическое шардирование
       может понадобиться — см. раздел 8.8/10.2 (6.2.0).

12.18. MinHash similar_to() сравнивает только с 500 последними
       записями (MAX_CANDIDATES). При большой базе (>500
       постов) возможны пропуски near-duplicate.

12.19. MinHash работает по 3-граммам СТЕМОВ. Тексты с разной
       разметкой, но одинаковым содержанием → сходство ~0.9;
       тексты с перестановкой абзацев → сходство падает
       (шинглы зависят от порядка).

12.20. Security Headers отправляются только для фронта
       (is_admin() пропускает). CSP по умолчанию off —
       включать только после проверки в report-only.

12.21. CSP report-uri не поддерживается (нет endpoint).
       При report-only браузеры пишут в консоль, но не на сервер.


================================================================================
13. КАК ОБНОВЛЯТЬ ПЛАГИН
================================================================================

 1. Изменил схему БД → миграция в includes/class-migrations.php.

 2. Подними WSSF_VERSION.

 3. Добавил стили/скрипты → файл в assets/css|js/ + строка в
    WSSF_Assets::CSS_MODULES|JS_MODULES.

 4. Добавил опцию → префикс wssf_, добавить в uninstall.php.

 5. Изменение затрагивает ввод/вывод/SQL/файлы/HTTP → чек-лист
    в _internal/SECURITY-AUDIT.md.

 6. Новый тип IOC → в WSSF_Tokenizer::IOC_PATTERNS +
    WSSF_Indexer::classify_term + WSSF_Autocomplete::prioritize_ioc.

 7. Обнови раздел 9 в этом файле.

 8. (5.2.0) Новый near-duplicate механизм → WSSF_MinHash +
    миграция + строка в admin/view-status.php + assets/css|js.