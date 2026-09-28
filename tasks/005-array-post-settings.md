# 005 — Массив в POST-полях настроек даёт «Array» и notice в форме

- **Статус:** open
- **Приоритет:** низкий. Через обычную форму не воспроизводится, нужен подделанный запрос.
- **Файлы:** `index()` и `validate()` в `admin/controller/extension/currency/nbk.php`, `admin/view/template/extension/currency/nbk.twig`.

## Проблема
`index()` возвращает в `$data` значения из POST как есть. Если прислать
`currency_nbk_margins[]=x` (или то же для `currency_nbk_ip`), то после
ошибки валидации Twig выведет в `value="…"` строку `Array`, а PHP выдаст
notice «Array to string conversion». `filter_var()` для `currency_nbk_ip`
массив тоже не ожидает.

**Обновлено после 003.** Вывод в шаблон уже закрыт: `escapeAttr()` в
контроллере превращает массив в `''`, notice при выводе нет. Открытой
осталась только сторона `validate()`: `filter_var()` для
`currency_nbk_ip` и разбор `currency_nbk_margins` получают массив как есть.

Найдено architect'ом при проектировании задачи 002. Задача близка к 003
(экранирование значений в шаблоне), их удобно делать вместе или 005 сразу
после 003.

## Ожидаемое поведение
Скалярные настройки из POST приводятся к строке или отклоняются до
валидации и до вывода в шаблон. Notice нет ни на одной версии PHP 7.4–8.5.

## Подсказки для критериев приёмки
- Проверка в smoke: POST с массивом в `currency_nbk_margins` и в
  `currency_nbk_ip` не сохраняется, в `$data` не попадает массив, notice нет.
- `tests/run.sh` → PASS.
