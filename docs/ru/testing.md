[English](../testing.md) | **Русский**

# Тестирование и Manticore Buddy

Выполняйте команды из корня репозитория после `composer install`. Нужен PHP 7.4 или PHP 8.1+
с JSON, mbstring, PDO и pdo_mysql. Зависимости должны соответствовать версии PHP, запускающей
PHPUnit: каталог vendor, собранный под PHP 8, может не подойти для проверки PHP 7.4.

## Тесты без сервера

```sh
php vendor/phpunit/phpunit/phpunit --testsuite unit
```

Эти тесты используют подменные клиенты; Manticore им не нужен.

## Отдельный сервер с Buddy

Официальный Docker-образ содержит Buddy и библиотеки Columnar/KNN. Перед выполнением команд
установите и запустите Docker; см. [руководство сервера по Docker](https://manual.manticoresearch.com/Starting_the_server/Docker).

```sh
docker run --name manticore-tests --rm -d -p 127.0.0.1:19306:9306 -p 127.0.0.1:19308:9308 manticoresearch/manticore:29.9.0
```

Нестандартные внешние порты отделяют тестовый экземпляр от локального сервера на 9306/9308.
Укажите SQL-порт в той же оболочке, где будет запущен PHPUnit. В PowerShell:

```powershell
$env:MANTICORE_HOST = '127.0.0.1'
$env:MANTICORE_PORT = '19306'
```

В Bash:

```sh
export MANTICORE_HOST=127.0.0.1
export MANTICORE_PORT=19306
```

Проверьте фактическую готовность компонентов, затем запустите весь набор:

```sh
php tests/ci/check-manticore.php
php vendor/phpunit/phpunit/phpunit --fail-on-skipped --fail-on-incomplete
```

Проверка ожидает работающие SQL, фильтрацию Columnar, KNN и переименование таблицы через Buddy.
Одного открытого TCP-порта недостаточно. Скрипт создаёт и удаляет временные таблицы;
используйте тестовый сервер. Время ожидания по умолчанию — 90 секунд, переменная
`MANTICORE_READY_TIMEOUT` задаёт от 1 до 120 секунд. На одном локальном сервере запускайте
прогоны последовательно.

После проверки остановите временный сервер:

```sh
docker stop manticore-tests
```

Контейнер с `--rm` без постоянного тома удаляет свои данные при остановке. Перед возвращением
к другому серверу восстановите прежние значения переменных окружения.

## Проверки, которым нужен Buddy

```sh
# CALL AUTOCOMPLETE: два теста группы buddy
php vendor/phpunit/phpunit/phpunit --testsuite integration --group buddy --fail-on-skipped

# Переименование тоже требует Buddy; этот тест не входит в группу buddy
php vendor/phpunit/phpunit/phpunit --filter 'AlterTableTest::testRename' --fail-on-skipped
```

Тесты автодополнения сами создают таблицы с `min_infix_len = 2`; подготавливать словарь
или прикладную таблицу не требуется. Отсутствие Buddy может привести к ошибке автодополнения
или пропуску переименования. Флаг `--fail-on-skipped` не позволяет считать пропуск успешной
проверкой.

Для ограниченной локальной проверки без Buddy:

```sh
php vendor/phpunit/phpunit/phpunit --exclude-group buddy
```

Команда исключает автодополнение, но не переименование: оно может пропускаться. Если сервер
недоступен, пропускаются все интеграционные тесты. Такие результаты не подтверждают работу
Buddy. При ошибке готовности смотрите `docker logs manticore-tests`. Нативная установка
Manticore на Windows требует Docker для Buddy; см.
[установку Buddy](https://manual.manticoresearch.com/Installation/Manticore_Buddy).

## GitHub Actions

[Workflow tests](../../.github/workflows/tests.yml) запускается при пушах в `main`, для pull
request и вручную через **Actions → tests → Run workflow**. Пуш в другую ветку без pull request
автоматически его не запускает. Ручной запуск проверяет выбранную удалённую ветку,
а не незакоммиченные локальные изменения.

| Задание | PHP | Manticore |
|---|---|---|
| Модульные тесты | 7.4, 8.1, 8.2, 8.3, 8.4 | Не требуется |
| Полный набор, включая Buddy | 7.4, 8.4 | 29.9.0 |
| Полный набор, включая Buddy | 8.4 | latest |

Каждое полное задание выполняет проверку готовности и PHPUnit с `--fail-on-skipped
--fail-on-incomplete`, без исключения группы buddy. Отсутствующие компоненты, пропуски и
незавершённые тесты приводят к ошибке задания. Пройти должны все задания матрицы.

Отчёты JUnit, журнал проверки готовности и диагностика контейнера Manticore сохраняются
в артефактах Actions на 14 дней, в том числе при ошибках. По ним можно отличить проблему
запуска сервера или отсутствующего компонента от ошибки тестируемой библиотеки.
