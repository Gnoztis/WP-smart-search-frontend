================================================================================
SECURITY AUDIT - WP SMART SEARCH
OWASP Top 10 (2021) mapping + WordPress hardening checklist
================================================================================

СТАТУС:       AUDIT_COMPLETED_AND_FIXED
ДАТА:         2026-10-28
ВЕРСИЯ:       4.1.1
АВТОР:        Security audit fix

Найденные проблемы:
  CRITICAL: 2
  HIGH:     2
  MEDIUM:   3
  LOW:      1

Все проблемы исправлены в версии 4.1.1.


================================================================================
1. OWASP TOP 10 (2021) - КАРТА РИСКОВ
================================================================================

A01 Broken Access Control
    Отсутствие current_user_can(), IDOR, доступ к AJAX без nonce.
    Проверять: все AJAX-обработчики, формы admin_post_*, history.

A02 Cryptographic Failures
    Пароли/секреты в открытом виде, слабые куки.
    Проверять: setcookie(..., true) для HttpOnly.

A03 Injection
    SQL (конкатенация в $wpdb->query), XSS (echo без esc_*), LFI.
    Проверять: все SQL-запросы, весь вывод в HTML/JS.

A04 Insecure Design
    Гонки (race conditions), предсказуемые токены, отсутствие лимитов.
    Проверять: rate-limit, лок-ключи, пагинацию, размеры массивов.

A05 Security Misconfiguration
    Debug включён, листинг директорий, стандартные префиксы.
    Проверять: WP_DEBUG, .htaccess, префикс БД.

A06 Vulnerable Components
    Устаревшие библиотеки, известные CVE.
    Проверять: версии зависимостей (jQuery, CleanTalk SDK).

A07 Auth Failures
    Слабые пароли, session fixation, brute-force.
    Проверять: логин, восстановление пароля, rate-limit.

A08 Data Integrity Failures
    Небезопасная десериализация, отсутствие проверки целостности.
    Проверять: unserialize() с пользовательскими данными.

A09 Logging Failures
    Отсутствие аудита, утечка ошибок в прод.
    Проверять: error_log, обработка Throwable.

A10 SSRF
    wp_remote_get() с непроверенными URL.
    Проверять: все HTTP-вызовы.


================================================================================
2. ЧТО УЖЕ СДЕЛАНО ПРАВИЛЬНО
================================================================================

| Механизм          | Реализация                                              | OWASP |
|-------------------|---------------------------------------------------------|-------|
| Nonce + cap       | check_admin_referer + current_user_can('manage_options')| A01   |
| SQL prepare       | $wpdb->prepare + esc_like для LIKE                       | A03   |
| Output escape     | esc_html, esc_attr, esc_url, wp_kses_post               | A03   |
| Rate limiting     | WSSF_Security::rate_limit_ok (атомарный UPSERT)         | A04   |
| Atomic locks      | _wssf_ct_lock_* через INSERT IGNORE                     | A04   |
| Cookies           | setcookie(..., is_ssl(), true) HttpOnly                 | A02   |
| Error handling    | try/catch + error_log при WP_DEBUG                      | A09   |
| SSRF              | wp_remote_post только к константе CleanTalk URL         | A10   |
| Unserialize       | allowed_classes => false                                | A08   |


================================================================================
3. ИСПРАВЛЕНО В 4.1.1
================================================================================

3.1. CRITICAL: A04 - CleanTalk API rate-limit.
     Проблема: гость мог генерировать до 30 API-вызовов CleanTalk
     в минуту (по общему rate-limit), что создавало риск исчерпания
     квоты через спам разными запросами с одного IP.
     Исправление: WSSF_CleanTalk::ct_api_rate_ok() - независимый
     счётчик на scope (user_id или IP). По умолчанию 20/мин,
     настраивается фильтром wssf_ct_api_rate_max.
     Файлы: includes/class-cleantalk.php.

3.2. CRITICAL: A03 - SQL через $wpdb->prefix.
     Проблема: имена таблиц (wp_wssf_index, wp_wssf_embeddings)
     строились конкатенацией $wpdb->prefix. При компрометации
     wp-config.php возможна инъекция в DDL (CREATE/DROP/SHOW).
     Исправление: regex-валидация ^[a-zA-Z0-9_]+$ перед использованием.
     Файлы: includes/class-indexer.php,
            includes/class-search-engine.php,
            includes/class-migrations.php,
            uninstall.php.

3.3. HIGH: A03 - XSS/управляющие символы в диагностике.
     Проблема: значения из транзиентов (wssf_diag_last,
     wssf_diag_results) выводились в textarea через (string),
     что пропускало управляющие символы и потенциально опасные
     последовательности.
     Исправление: sanitize_text_field для всех значений.
     Файлы: admin/class-admin-diagnostics.php.

3.4. HIGH: A01/A04 - неограниченные массивы в history.
     Проблема: sanitize_names_map() не ограничивал размер массива,
     что создавало DoS-вектор через большой POST.
     Исправление: array_slice($map, 0, 20) + ограничение tags/xtags
     до 50 элементов (было без ограничения на входе).
     Файлы: includes/class-ajax-handler.php.

3.5. MEDIUM: A03 - SQL через префикс транзиентов.
     Проблема: delete_transients_by_prefix() принимал любой префикс
     и подставлял в LIKE. При ошибочном вызове из будущего кода
     возможна инъекция.
     Исправление: regex-валидация префикса.
     Файлы: includes/class-cache.php.

3.6. MEDIUM: A09 - error_log утечки.
     Проблема: сообщения исключений писались в error_log без
     очистки, что могло тащить чувствительные данные.
     Исправление: sanitize_text_field для всех сообщений.
     Файлы: includes/class-cleantalk.php,
            includes/class-hooks.php,
            includes/class-ajax-handler.php,
            admin/class-admin-diagnostics.php.

3.7. MEDIUM: A06 - DROP TABLE без валидации.
     Проблема: uninstall.php делал DROP TABLE с конкатенацией.
     Исправление: regex-валидация имён таблиц перед DROP.
     Файлы: uninstall.php.

3.8. LOW: A03 - недостаточная очистка BOOLEAN MODE.
     Проблема: groups_to_boolean_string() не удалял обратный слэш
     и %, которые могут сломать MATCH ... AGAINST.
     Исправление: добавлены \\ и % в чёрный список.
     Файлы: includes/class-tokenizer.php.


================================================================================
4. ЧЕК-ЛИСТ ДЛЯ БУДУЩИХ ИТЕРАЦИЙ
================================================================================

При любом изменении, затрагивающем ввод/вывод/SQL/файлы/HTTP,
проверить:

[ ] A01 - Все AJAX-обработчики: check_ajax_referer + current_user_can.
[ ] A01 - Все admin_post_*: check_admin_referer + current_user_can.
[ ] A01 - handle_history: ограничение размеров массивов (tags/xtags/names).
[ ] A02 - setcookie: HttpOnly=true, Secure=is_ssl().
[ ] A03 - SQL: $wpdb->prepare, esc_like для LIKE.
[ ] A03 - SHOW TABLES / DROP TABLE: regex ^[a-zA-Z0-9_]+$.
[ ] A03 - Вывод: esc_html, esc_attr, esc_url, esc_textarea.
[ ] A03 - BOOLEAN MODE: очистка операторов, обратного слэша, %.
[ ] A04 - CleanTalk: ct_api_rate_ok перед API-вызовом.
[ ] A04 - delete_transients_by_prefix: regex-валидация префикса.
[ ] A04 - Массивы из $_POST: ограничение длины.
[ ] A06 - Зависимости: проверка версий (jQuery, CleanTalk SDK).
[ ] A08 - unserialize: allowed_classes => false.
[ ] A09 - error_log: sanitize_text_field для сообщений.
[ ] A10 - wp_remote_*: только константные URL (CleanTalk).


================================================================================
5. КАК ДОБАВИТЬ НОВУЮ МИГРАЦИЮ БЕЗОПАСНОСТИ
================================================================================

Если правка требует изменения схемы БД (например, новая колонка
для CVSS в 4.2.0):

1. Добавить версию в WSSF_Migrations::all() с новым callable.
2. Метод миграции должен быть идемпотентным:
   - использовать dbDelta для ALTER TABLE;
   - проверять существование колонки через information_schema.
3. Если миграция меняет данные - сначала бэкап, потом
   изменения через prepared statements.
4. Тестировать на копии БД: пустая, полная, частично заполненная.
5. Обновить DEV-NOTES.md раздел 6 (История версий).