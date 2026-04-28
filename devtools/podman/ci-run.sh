#!/usr/bin/env bash
set -euo pipefail

echo "=== LibEuFinConnector Podman CI runner ==="

: "${DOLIBARR_BRANCH:=22.0.3}"
: "${DB:=mysql}"
: "${TRAVIS_PHP_VERSION:=8.3}"
: "${MODULE_NAME:=libeufinconnector}"
: "${MYSQL_PORT:=13306}"
: "${MYSQL_PASSWORD:=password}"
: "${WEB_PORT:=8000}"
: "${MODULE_DIR:=/opt/libeufinconnector-src}"

export DOLIBARR_BRANCH DB TRAVIS_PHP_VERSION MODULE_NAME MODULE_DIR MYSQL_PORT MYSQL_PASSWORD

echo "Using:"
echo "  DOLIBARR_BRANCH   = ${DOLIBARR_BRANCH}"
echo "  DB                = ${DB}"
echo "  TRAVIS_PHP_VERSION= ${TRAVIS_PHP_VERSION}"
echo "  MODULE_DIR        = ${MODULE_DIR}"
echo "  MYSQL_PORT        = ${MYSQL_PORT}"
echo "  WEB_PORT          = ${WEB_PORT}"

TRAVIS_BUILD_DIR="/opt/dolibarr"
export TRAVIS_BUILD_DIR

cd "${TRAVIS_BUILD_DIR}"
export PATH="${TRAVIS_BUILD_DIR}/vendor/bin:${TRAVIS_BUILD_DIR}/htdocs/includes/bin:${PATH}"

echo "Placing module into htdocs/custom/${MODULE_NAME}..."
MODULE_DEST="${TRAVIS_BUILD_DIR}/htdocs/custom/${MODULE_NAME}"
rm -rf "${MODULE_DEST}"
mkdir -p "${MODULE_DEST}"
cp -a "${MODULE_DIR}/." "${MODULE_DEST}/"

echo "== Verifying LibEuFin availability =="
command -v libeufin-nexus
dpkg -l | grep -E 'libeufin-(common|nexus)' || true

echo "== Resetting MariaDB datadir =="
rm -rf /var/lib/mysql/* || true
rm -rf /run/mysqld/* || true

MYSQL_SOCKET="/run/mysqld/mysqld.sock"
mkdir -p /run/mysqld
chown mysql:mysql /run/mysqld || true
MYSQL_PASSWORD_SQL=$(printf "%s" "${MYSQL_PASSWORD}" | sed "s/'/''/g")

if [ ! -d /var/lib/mysql/mysql ]; then
  if command -v mariadb-install-db >/dev/null 2>&1; then
    mariadb-install-db --user=mysql --ldata=/var/lib/mysql --basedir=/usr >/dev/null
  else
    mysql_install_db --user=mysql --ldata=/var/lib/mysql --basedir=/usr >/dev/null
  fi
fi

chown -R mysql:mysql /var/lib/mysql || true

mysqld --user=mysql \
  --bind-address=127.0.0.1 \
  --port="${MYSQL_PORT}" \
  --socket="${MYSQL_SOCKET}" \
  --datadir=/var/lib/mysql \
  --pid-file=/run/mysqld/mysqld.pid \
  >/var/log/mysqld.log 2>&1 &
MYSQL_PID=$!

for i in {1..60}; do
  if mysqladmin --protocol=socket --socket="${MYSQL_SOCKET}" ping --silent; then
    break
  fi
  sleep 1
done
if ! mysqladmin --protocol=socket --socket="${MYSQL_SOCKET}" ping --silent; then
  tail -n 200 /var/log/mysqld.log || true
  exit 1
fi

mysql --protocol=socket --socket="${MYSQL_SOCKET}" -uroot <<SQL
CREATE DATABASE IF NOT EXISTS travis CHARACTER SET utf8;
CREATE USER IF NOT EXISTS 'travis'@'127.0.0.1' IDENTIFIED BY '${MYSQL_PASSWORD_SQL}';
GRANT ALL PRIVILEGES ON travis.* TO 'travis'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

mysql --protocol=socket --socket="${MYSQL_SOCKET}" -uroot travis < dev/initdemo/mysqldump_dolibarr_3.5.0.sql
mysql --protocol=tcp --host=127.0.0.1 --port="${MYSQL_PORT}" --user=travis --password="${MYSQL_PASSWORD}" -e "SELECT 1" travis >/dev/null

echo "== Installing Composer tools =="
cd "${TRAVIS_BUILD_DIR}"
composer self-update 2.4.4
composer -n require --ignore-platform-reqs \
  phpunit/phpunit ^8 \
  php-parallel-lint/php-parallel-lint ^1.2 \
  php-parallel-lint/php-console-highlighter ^0 \
  php-parallel-lint/php-var-dump-check ~0.4 \
  squizlabs/php_codesniffer ^3

CONF_FILE="htdocs/conf/conf.php"
cat > "${CONF_FILE}" <<PHP
<?php
error_reporting(E_ALL);
\$dolibarr_main_url_root='http://127.0.0.1:${WEB_PORT}';
\$dolibarr_main_document_root='${TRAVIS_BUILD_DIR}/htdocs';
\$dolibarr_main_data_root='${TRAVIS_BUILD_DIR}/documents';
\$dolibarr_main_db_host='127.0.0.1';
\$dolibarr_main_db_name='travis';
\$dolibarr_main_instance_unique_id='travis1234567890';
\$dolibarr_main_db_type='mysqli';
\$dolibarr_main_db_port=${MYSQL_PORT};
\$dolibarr_main_db_user='travis';
\$dolibarr_main_db_pass='${MYSQL_PASSWORD}';
\$dolibarr_main_db_character_set='utf8';
\$dolibarr_main_db_collation='utf8_general_ci';
\$dolibarr_main_authentication='dolibarr';
PHP

mkdir -p "${TRAVIS_BUILD_DIR}/documents/admin/temp"
chmod -R a+rwx "${TRAVIS_BUILD_DIR}/documents"
echo "***** First line of dolibarr.log" > "${TRAVIS_BUILD_DIR}/documents/dolibarr.log"

INSTALL_FORCED_FILE="htdocs/install/install.forced.php"
cat > "${INSTALL_FORCED_FILE}" <<PHP
<?php
error_reporting(E_ALL);
\$force_install_noedit=2;
\$force_install_type='mysqli';
\$force_install_port=${MYSQL_PORT};
\$force_install_dbserver='127.0.0.1';
\$force_install_database='travis';
\$force_install_databaselogin='travis';
\$force_install_databasepass='${MYSQL_PASSWORD}';
\$force_install_prefix='llx_';
\$force_install_createdatabase=false;
\$force_install_createuser=false;
\$force_install_mainforcehttps=false;
\$force_install_main_data_root='${TRAVIS_BUILD_DIR}/documents';
PHP

echo "== Running DB upgrade chain =="
set +e
cd htdocs/install

run_upgrade() {
  local from="$1" to="$2"
  php upgrade.php "$from" "$to" ignoredbversion
  php upgrade2.php "$from" "$to"
  php step5.php "$from" "$to"
}

run_upgrade 3.5.0 3.6.0
run_upgrade 3.6.0 3.7.0
run_upgrade 3.7.0 3.8.0
run_upgrade 3.8.0 3.9.0
run_upgrade 3.9.0 4.0.0
run_upgrade 4.0.0 5.0.0
run_upgrade 5.0.0 6.0.0
run_upgrade 6.0.0 7.0.0
run_upgrade 7.0.0 8.0.0
run_upgrade 8.0.0 9.0.0
run_upgrade 9.0.0 10.0.0
run_upgrade 10.0.0 11.0.0
run_upgrade 11.0.0 12.0.0
run_upgrade 12.0.0 13.0.0
run_upgrade 13.0.0 14.0.0
run_upgrade 14.0.0 15.0.0
run_upgrade 15.0.0 16.0.0
run_upgrade 16.0.0 17.0.0
run_upgrade 17.0.0 18.0.0
run_upgrade 18.0.0 19.0.0
run_upgrade 19.0.0 20.0.0
run_upgrade 20.0.0 21.0.0
run_upgrade 21.0.0 22.0.0

set -e
cd "${TRAVIS_BUILD_DIR}"

echo "== Enabling common Dolibarr modules =="
set +e
cd htdocs/install
ENABLE_LOG="${TRAVIS_BUILD_DIR}/enablemodule.log"
php upgrade2.php 0.0.0 0.0.0 MAIN_MODULE_API,MAIN_MODULE_ProductBatch,MAIN_MODULE_SupplierProposal,MAIN_MODULE_STRIPE,MAIN_MODULE_ExpenseReport > "${ENABLE_LOG}" 2>&1
php upgrade2.php 0.0.0 0.0.0 MAIN_MODULE_WEBSITE,MAIN_MODULE_TICKET,MAIN_MODULE_ACCOUNTING,MAIN_MODULE_MRP >> "${ENABLE_LOG}" 2>&1
php upgrade2.php 0.0.0 0.0.0 MAIN_MODULE_RECEPTION,MAIN_MODULE_RECRUITMENT >> "${ENABLE_LOG}" 2>&1
php upgrade2.php 0.0.0 0.0.0 MAIN_MODULE_KnowledgeManagement,MAIN_MODULE_EventOrganization,MAIN_MODULE_PARTNERSHIP >> "${ENABLE_LOG}" 2>&1
php upgrade2.php 0.0.0 0.0.0 MAIN_MODULE_EmailCollector >> "${ENABLE_LOG}" 2>&1
echo "== Enabling LibEuFin connector module =="
php upgrade2.php 0.0.0 0.0.0 MAIN_MODULE_LIBEUFINCONNECTOR >> "${ENABLE_LOG}" 2>&1
set -e
tail -n 40 "${ENABLE_LOG}" || true
cd "${TRAVIS_BUILD_DIR}"

echo "== Starting PHP built-in server =="
php -S 127.0.0.1:${WEB_PORT} -t htdocs >/tmp/php-server.log 2>&1 &
PHPSERVER_PID=$!

cleanup() {
  kill "${PHPSERVER_PID}" >/dev/null 2>&1 || true
  kill "${MYSQL_PID}" >/dev/null 2>&1 || true
}
trap cleanup EXIT

run_phase() {
  local name="$1"
  shift
  local logfile="/tmp/${name}.log"

  echo "== Running ${name} =="
  set +e
  "$@" 2>&1 | tee "${logfile}"
  local cmd_status=${PIPESTATUS[0]}
  set -e

  echo "${name} return code = ${cmd_status}"
  if [ "${cmd_status}" -ne 0 ]; then
    echo "=== ${name} log ==="
    cat "${logfile}"
    exit "${cmd_status}"
  fi
}

run_phase dolibarr-core-phpunit \
  vendor/bin/phpunit -d memory_limit=-1 --debug -c test/phpunit/phpunittest.xml test/phpunit/AllTests.php

run_phase libeufinconnector-static-lint \
  vendor/bin/parallel-lint --exclude vendor --exclude node_modules htdocs/custom/libeufinconnector

run_phase libeufinconnector-static-phpunit \
  vendor/bin/phpunit -d memory_limit=-1 --debug -c test/phpunit/phpunittest.xml \
    htdocs/custom/libeufinconnector/test/phpunit/unit/LibeufinTransactionStaticTest.php

run_phase libeufinconnector-integration-customer-incoming \
  vendor/bin/phpunit -d memory_limit=-1 --debug -c test/phpunit/phpunittest.xml \
    htdocs/custom/libeufinconnector/test/phpunit/integration/LibeufinIncomingCustomerPaymentIntegrationTest.php

run_phase libeufinconnector-integration-supplier-refund \
  vendor/bin/phpunit -d memory_limit=-1 --debug -c test/phpunit/phpunittest.xml \
    htdocs/custom/libeufinconnector/test/phpunit/integration/LibeufinIncomingSupplierRefundIntegrationTest.php

run_phase libeufinconnector-integration-outgoing-collection \
  vendor/bin/phpunit -d memory_limit=-1 --debug -c test/phpunit/phpunittest.xml \
    htdocs/custom/libeufinconnector/test/phpunit/integration/LibeufinOutgoingCollectionIntegrationTest.php

echo "== Done. Tests passed. =="
