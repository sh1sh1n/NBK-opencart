# 001 — `curl_close()` даёт Deprecated на PHP 8.5

- **Статус:** done
- **Приоритет:** высокий. Это единственная причина, по которой `tests/run.sh` сейчас красный.
- **Файлы:** `admin/model/extension/currency/nbk.php:49`, `catalog/model/extension/currency/nbk.php:49`

## Проблема
Начиная с PHP 8.0 `curl_close()` ничего не делает: хэндл освобождается сам,
когда выходит из области видимости. В PHP 8.5 функция помечена устаревшей,
и каждый вызов `refresh()` даёт
`Deprecated: Function curl_close() is deprecated since 8.5`.

Если в OpenCart включён вывод ошибок (`config_error_display`), это
предупреждение попадает в HTML страницы и в тело ответа cron: вместо
`NBK: ok` мониторинг получает строку с Deprecated перед ним. Если вывод
выключен, предупреждение только засоряет лог.

## Воспроизведение
```bash
tests/run.sh 8.5
```
→ `static` FAIL (`curl_close() is deprecated in 8.5`) и smoke FAIL
(`[8192] Function curl_close() is deprecated … nbk.php:49 (x6)`).

## Ожидаемое поведение
Одна кодовая база для PHP 7.4–8.5: на 7.4 хэндл закрывается явно, на 8.0+
вызова нет. Например:
```php
if (PHP_VERSION_ID < 80000) {
	curl_close($curl);
}
```
Правка вносится в обе модели одинаково.

## Подсказки для критериев приёмки
- `tests/run.sh` → `RESULT: PASS` на всех установленных версиях; 7.4 и 8.5 обязательно.
- admin- и catalog-модели идентичны.
- Остальные результаты smoke не изменились: 46 проверок, все ok.

## Закрыто
Коммит «Skip curl_close() on PHP 8.0+ to avoid the 8.5 deprecation»: в
`refresh()` обеих моделей `curl_close($curl)` вызывается только при
`PHP_VERSION_ID < 80000`, модели идентичны байт-в-байт (+5/−1 в каждой),
`tests/` не менялся.

Проверено: `tests/run.sh` на PHP 8.4.19 → `RESULT: PASS` (static 7/7,
lint чистый, smoke 46/0); на baseline static падал на `nbk.php:49`.
PHP 7.4 и 8.5 проверены только симуляцией `PHP_VERSION_ID` (в окружении
не было интерпретаторов). Шаг Codex в verifier не выполнен (Codex
недоступен); задача закрыта по решению пользователя без полной
верификации. При первой возможности прогнать `tests/run.sh 7.4 8.5`.

Дополнение (прогон задачи 002, поверх этого исправления): `tests/run.sh`
→ PASS на реальных PHP 7.4.33 и 8.5.11, Codex отработал. Непроверенным по
001 ничего не осталось.
