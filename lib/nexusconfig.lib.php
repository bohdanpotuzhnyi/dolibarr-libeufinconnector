<?php
/* Copyright (C) 2026       Bohdan Potuzhnyi
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    libeufinconnector/lib/nexusconfig.lib.php
 * \ingroup libeufinconnector
 * \brief   Helpers for LibEuFin Nexus configuration parsing and sync.
 */

/**
 * Return the managed Nexus config schema grouped by section.
 *
 * @return array<string,array{title:string,fields:array<string,string>}>
 */
function libeufinconnectorGetManagedNexusConfigSchema()
{
	return array(
		'nexus-ebics' => array(
			'title' => 'EBICS account identity',
			'fields' => array(
				'CURRENCY' => 'Currency',
				'HOST_BASE_URL' => 'Host base URL',
				'BANK_DIALECT' => 'Bank dialect',
				'HOST_ID' => 'Host ID',
				'USER_ID' => 'User ID',
				'PARTNER_ID' => 'Partner ID',
				'IBAN' => 'IBAN',
				'BIC' => 'BIC',
				'NAME' => 'Account holder',
				'BANK_PUBLIC_KEYS_FILE' => 'Bank public keys file',
				'CLIENT_PRIVATE_KEYS_FILE' => 'Client private keys file',
				'ACCOUNT_TYPE' => 'Account type',
			),
		),
		'nexus-submit' => array(
			'title' => 'Outgoing payment submission',
			'fields' => array(
				'FREQUENCY' => 'Frequency',
				'MANUAL_ACK' => 'Manual acknowledgement',
			),
		),
		'nexus-fetch' => array(
			'title' => 'Incoming payment fetch',
			'fields' => array(
				'FREQUENCY' => 'Frequency',
				'CHECKPOINT_TIME_OF_DAY' => 'Checkpoint time of day',
			),
		),
		'libeufin-nexusdb-postgres' => array(
			'title' => 'Database',
			'fields' => array(
				'CONFIG' => 'Connection string',
				'SQL_DIR' => 'SQL directory',
			),
		),
		'nexus-httpd' => array(
			'title' => 'HTTP server defaults',
			'fields' => array(
				'SERVE' => 'Serve mode',
				'PORT' => 'TCP port',
				'BIND_TO' => 'Bind address',
			),
		),
		'nexus-httpd-wire-gateway-api' => array(
			'title' => 'Disabled Wire Gateway API defaults',
			'fields' => array(
				'ENABLED' => 'Enabled',
				'AUTH_METHOD' => 'Authentication method',
			),
		),
		'nexus-httpd-wire-transfer-gateway-api' => array(
			'title' => 'Disabled Wire Transfer Gateway API defaults',
			'fields' => array(
				'ENABLED' => 'Enabled',
				'AUTH_METHOD' => 'Authentication method',
			),
		),
		'nexus-httpd-revenue-api' => array(
			'title' => 'Disabled Revenue API defaults',
			'fields' => array(
				'ENABLED' => 'Enabled',
				'AUTH_METHOD' => 'Authentication method',
			),
		),
		'nexus-httpd-observability-api' => array(
			'title' => 'Disabled Observability API defaults',
			'fields' => array(
				'ENABLED' => 'Enabled',
				'AUTH_METHOD' => 'Authentication method',
			),
		),
	);
}

/**
 * Return flat labels of managed Nexus config keys.
 *
 * @return array<string,array<string,string>>
 */
function libeufinconnectorGetManagedNexusConfigLabels()
{
	$schema = libeufinconnectorGetManagedNexusConfigSchema();
	$labels = array();

	foreach ($schema as $section => $meta) {
		$labels[$section] = $meta['fields'];
	}

	return $labels;
}

/**
 * Build expected Nexus config values from Dolibarr connector settings.
 *
 * @return array<string,array<string,string>>
 */
function libeufinconnectorGetExpectedNexusConfig()
{
	return array(
		'nexus-ebics' => array(
			'CURRENCY' => (string) getDolGlobalString('LIBEUFINCONNECTOR_EXPECTED_CURRENCY'),
			'HOST_BASE_URL' => '',
			'BANK_DIALECT' => '',
			'HOST_ID' => '',
			'USER_ID' => '',
			'PARTNER_ID' => '',
			'IBAN' => (string) getDolGlobalString('LIBEUFINCONNECTOR_EXPECTED_IBAN'),
			'BIC' => (string) getDolGlobalString('LIBEUFINCONNECTOR_EXPECTED_BIC'),
			'NAME' => (string) getDolGlobalString('LIBEUFINCONNECTOR_EXPECTED_ACCOUNT_HOLDER'),
			'BANK_PUBLIC_KEYS_FILE' => libeufinconnectorGetLocalNexusKeysDir().'/bank-ebics-keys.json',
			'CLIENT_PRIVATE_KEYS_FILE' => libeufinconnectorGetLocalNexusKeysDir().'/client-ebics-keys.json',
			'ACCOUNT_TYPE' => 'normal',
		),
		'nexus-submit' => array(
			'FREQUENCY' => '5m',
			'MANUAL_ACK' => 'NO',
		),
		'nexus-fetch' => array(
			'FREQUENCY' => '5m',
			'CHECKPOINT_TIME_OF_DAY' => '19:00',
		),
		'libeufin-nexusdb-postgres' => array(
			'CONFIG' => 'postgres:///libeufin-nexus',
			'SQL_DIR' => libeufinconnectorDetectNexusSqlDir(),
		),
		'nexus-httpd' => array(
			'SERVE' => 'tcp',
			'PORT' => '8080',
			'BIND_TO' => '127.0.0.1',
		),
		'nexus-httpd-wire-gateway-api' => array(
			'ENABLED' => 'NO',
			'AUTH_METHOD' => 'none',
		),
		'nexus-httpd-wire-transfer-gateway-api' => array(
			'ENABLED' => 'NO',
			'AUTH_METHOD' => 'none',
		),
		'nexus-httpd-revenue-api' => array(
			'ENABLED' => 'NO',
			'AUTH_METHOD' => 'none',
		),
		'nexus-httpd-observability-api' => array(
			'ENABLED' => 'NO',
			'AUTH_METHOD' => 'none',
		),
	);
}

/**
 * Try to detect the installed LibEuFin SQL directory.
 *
 * @return string
 */
function libeufinconnectorDetectNexusSqlDir()
{
	$candidates = array(
		'/usr/share/libeufin/sql',
		'/usr/local/share/libeufin/sql',
		'/usr/share/libeufin-nexus/sql',
		'/usr/local/share/libeufin-nexus/sql',
	);

	foreach ($candidates as $candidate) {
		if (is_dir($candidate) && is_readable($candidate)) {
			return $candidate;
		}
	}

	return '';
}

/**
 * Tell if the connector should use the module-owned local Nexus config.
 *
 * @return bool
 */
function libeufinconnectorUseLocalNexusConfig()
{
	return ((int) getDolGlobalInt('LIBEUFINCONNECTOR_USE_LOCAL_NEXUS_CONFIG') === 1);
}

/**
 * Return the module documents root.
 *
 * @return string
 */
function libeufinconnectorGetDocumentsDir()
{
	return DOL_DATA_ROOT.'/libeufinconnector';
}

/**
 * Return the module-managed Nexus config directory.
 *
 * @return string
 */
function libeufinconnectorGetLocalNexusConfigDir()
{
	return libeufinconnectorGetDocumentsDir().'/config';
}

/**
 * Return the module-managed Nexus EBICS key directory.
 *
 * @return string
 */
function libeufinconnectorGetLocalNexusKeysDir()
{
	return libeufinconnectorGetDocumentsDir().'/keys';
}

/**
 * Return the operation log directory.
 *
 * @return string
 */
function libeufinconnectorGetOperationsDir()
{
	return libeufinconnectorGetDocumentsDir().'/operations';
}

/**
 * Return the module temporary directory.
 *
 * @return string
 */
function libeufinconnectorGetTempDir()
{
	return libeufinconnectorGetDocumentsDir().'/temp';
}

/**
 * Return the module-managed Nexus config path.
 *
 * @return string
 */
function libeufinconnectorGetLocalNexusConfigPath()
{
	return libeufinconnectorGetLocalNexusConfigDir().'/libeufin-nexus.conf';
}

/**
 * Return the currently configured external Nexus config path.
 *
 * @return string
 */
function libeufinconnectorGetConfiguredNexusConfigPath()
{
	return (string) getDolGlobalString('LIBEUFINCONNECTOR_NEXUS_CONFIG');
}

/**
 * Return the effective Nexus config path used by the connector.
 *
 * @return string
 */
function libeufinconnectorGetEffectiveNexusConfigPath()
{
	if (libeufinconnectorUseLocalNexusConfig()) {
		return libeufinconnectorGetLocalNexusConfigPath();
	}

	return libeufinconnectorGetConfiguredNexusConfigPath();
}

/**
 * Ensure the module-owned config directory exists.
 *
 * @return array{ok:bool,error:string}
 */
function libeufinconnectorEnsureLocalNexusConfigDir()
{
	$dir = libeufinconnectorGetLocalNexusConfigDir();

	if (is_dir($dir)) {
		if (is_writable($dir)) {
			return array('ok' => true, 'error' => '');
		}

		return array('ok' => false, 'error' => 'directory_not_writable');
	}

	if (@mkdir($dir, 0775, true) || is_dir($dir)) {
		if (is_writable($dir)) {
			return array('ok' => true, 'error' => '');
		}

		return array('ok' => false, 'error' => 'directory_not_writable');
	}

	return array('ok' => false, 'error' => 'directory_create_failed');
}

/**
 * Ensure the module-owned EBICS key directory exists.
 *
 * @return array{ok:bool,error:string}
 */
function libeufinconnectorEnsureLocalNexusKeysDir()
{
	$dir = libeufinconnectorGetLocalNexusKeysDir();

	if (is_dir($dir)) {
		if (is_writable($dir)) {
			return array('ok' => true, 'error' => '');
		}

		return array('ok' => false, 'error' => 'key_directory_not_writable');
	}

	if (@mkdir($dir, 0770, true) || is_dir($dir)) {
		if (is_writable($dir)) {
			return array('ok' => true, 'error' => '');
		}

		return array('ok' => false, 'error' => 'key_directory_not_writable');
	}

	return array('ok' => false, 'error' => 'key_directory_create_failed');
}

/**
 * Ensure the operation log directory exists.
 *
 * @return array{ok:bool,error:string}
 */
function libeufinconnectorEnsureOperationsDir()
{
	$dir = libeufinconnectorGetOperationsDir();

	if (is_dir($dir)) {
		if (is_writable($dir)) {
			return array('ok' => true, 'error' => '');
		}

		return array('ok' => false, 'error' => 'directory_not_writable');
	}

	if (@mkdir($dir, 0775, true) || is_dir($dir)) {
		if (is_writable($dir)) {
			return array('ok' => true, 'error' => '');
		}

		return array('ok' => false, 'error' => 'directory_not_writable');
	}

	return array('ok' => false, 'error' => 'directory_create_failed');
}

/**
 * Ensure the module temporary directory exists.
 *
 * @return array{ok:bool,error:string}
 */
function libeufinconnectorEnsureTempDir()
{
	$dir = libeufinconnectorGetTempDir();

	if (is_dir($dir)) {
		if (is_writable($dir)) {
			return array('ok' => true, 'error' => '');
		}

		return array('ok' => false, 'error' => 'temp_directory_not_writable');
	}

	if (@mkdir($dir, 0775, true) || is_dir($dir)) {
		if (is_writable($dir)) {
			return array('ok' => true, 'error' => '');
		}

		return array('ok' => false, 'error' => 'temp_directory_not_writable');
	}

	return array('ok' => false, 'error' => 'temp_directory_create_failed');
}

/**
 * Parse a LibEuFin Nexus config file.
 *
 * @param string $path Config file path.
 * @return array{path:string,error:string,sections:array<string,array<string,string>>}
 */
function libeufinconnectorReadNexusConfig($path)
{
	$result = array(
		'path' => (string) $path,
		'error' => '',
		'sections' => array(),
	);

	if ($path === '') {
		$result['error'] = 'missing_path';
		return $result;
	}

	if (!file_exists($path)) {
		$result['error'] = 'missing_file';
		return $result;
	}

	if (!is_readable($path)) {
		$result['error'] = 'not_readable';
		return $result;
	}

	$lines = file($path, FILE_IGNORE_NEW_LINES);
	if ($lines === false) {
		$result['error'] = 'read_failed';
		return $result;
	}

	$currentSection = '';
	foreach ($lines as $line) {
		if (preg_match('/^\s*$/', $line) || preg_match('/^\s*[#%]/', $line)) {
			continue;
		}

		if (preg_match('/^\s*\[([^\]]+)\]\s*$/', $line, $matches)) {
			$currentSection = strtolower(trim($matches[1]));
			if (!isset($result['sections'][$currentSection])) {
				$result['sections'][$currentSection] = array();
			}
			continue;
		}

		if ($currentSection === '') {
			continue;
		}

		if (preg_match('/^\s*([A-Za-z0-9_-]+)\s*=\s*(.*?)\s*$/', $line, $matches)) {
			$key = strtoupper(trim($matches[1]));
			$value = (string) $matches[2];
			if (strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"') {
				$value = substr($value, 1, -1);
			}
			$result['sections'][$currentSection][$key] = $value;
		}
	}

	return $result;
}

/**
 * Compare actual Nexus config against expected values.
 *
 * @param array{path:string,error:string,sections:array<string,array<string,string>>} $actual Actual config.
 * @param array<string,array<string,string>> $expected Expected values.
 * @return array<string,array<string,array{expected:string,actual:string}>>
 */
function libeufinconnectorCompareNexusConfig(array $actual, array $expected)
{
	$mismatches = array();

	foreach ($expected as $section => $keys) {
		foreach ($keys as $key => $expectedValue) {
			if ($expectedValue === '') {
				continue;
			}

			$actualValue = '';
			if (isset($actual['sections'][$section][$key])) {
				$actualValue = (string) $actual['sections'][$section][$key];
			}

			if ($actualValue !== $expectedValue) {
				if (!isset($mismatches[$section])) {
					$mismatches[$section] = array();
				}
				$mismatches[$section][$key] = array(
					'expected' => $expectedValue,
					'actual' => $actualValue,
				);
			}
		}
	}

	return $mismatches;
}

/**
 * Format a Nexus config value for writing.
 *
 * @param string $value Raw value.
 * @return string
 */
function libeufinconnectorFormatNexusConfigValue($value)
{
	$value = str_replace(array("\r", "\n"), ' ', (string) $value);
	if ($value === '') {
		return '""';
	}
	if (preg_match('/\s|[#%]/', $value)) {
		return '"'.str_replace('"', "'", $value).'"';
	}
	return $value;
}

/**
 * Write managed Nexus config values back to the file, preserving unrelated content.
 *
 * @param string $path Config file path.
 * @param array<string,array<string,string>> $values Values to write.
 * @return array{ok:bool,error:string}
 */
function libeufinconnectorWriteManagedNexusConfig($path, array $values, $bootstrapPath = '')
{
	$result = array(
		'ok' => false,
		'error' => '',
	);

	if ($path === '') {
		$result['error'] = 'missing_path';
		return $result;
	}

	$dir = dirname($path);
	if (!is_dir($dir)) {
		if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
			$result['error'] = 'directory_create_failed';
			return $result;
		}
	}
	if (!is_writable($dir)) {
		$result['error'] = 'directory_not_writable';
		return $result;
	}

	$existingLines = array();
	if (!file_exists($path) && $bootstrapPath !== '' && $bootstrapPath !== $path && is_readable($bootstrapPath)) {
		if (!@copy($bootstrapPath, $path)) {
			$result['error'] = 'bootstrap_copy_failed';
			return $result;
		}
	}
	if (file_exists($path)) {
		if (!is_readable($path)) {
			$result['error'] = 'not_readable';
			return $result;
		}
		if (!is_writable($path)) {
			$result['error'] = 'not_writable';
			return $result;
		}
		$existingLines = file($path);
		if ($existingLines === false) {
			$result['error'] = 'read_failed';
			return $result;
		}
	}

	$output = array();
	$currentSection = '';
	$seenSections = array();
	$seenKeys = array();

	$appendMissingKeys = function ($section) use (&$output, &$values, &$seenKeys) {
		if ($section === '' || !isset($values[$section])) {
			return;
		}
		foreach ($values[$section] as $key => $value) {
			if ((string) $value === '') {
				continue;
			}
			if (!isset($seenKeys[$section][$key])) {
				$output[] = $key.' = '.libeufinconnectorFormatNexusConfigValue($value).PHP_EOL;
				$seenKeys[$section][$key] = true;
			}
		}
	};

	foreach ($existingLines as $line) {
		if (preg_match('/^\s*\[([^\]]+)\]\s*$/', $line, $matches)) {
			$appendMissingKeys($currentSection);
			$currentSection = strtolower(trim($matches[1]));
			$seenSections[$currentSection] = true;
			$output[] = $line;
			continue;
		}

		if ($currentSection !== '' && isset($values[$currentSection]) && preg_match('/^\s*([A-Za-z0-9_-]+)\s*=/', $line, $matches)) {
			$key = strtoupper(trim($matches[1]));
			if (isset($values[$currentSection][$key])) {
				if (!isset($seenKeys[$currentSection][$key])) {
					if ((string) $values[$currentSection][$key] !== '') {
						$output[] = $key.' = '.libeufinconnectorFormatNexusConfigValue($values[$currentSection][$key]).PHP_EOL;
					}
					$seenKeys[$currentSection][$key] = true;
				}
				continue;
			}
		}

		$output[] = $line;
	}

	$appendMissingKeys($currentSection);

	foreach ($values as $section => $keys) {
		if (!isset($seenSections[$section])) {
			$keysToWrite = array();
			foreach ($keys as $key => $value) {
				if ((string) $value !== '') {
					$keysToWrite[$key] = $value;
				}
			}
			if (empty($keysToWrite)) {
				continue;
			}

			if (!empty($output) && substr((string) end($output), -1) !== PHP_EOL) {
				$output[] = PHP_EOL;
			}
			if (!empty($output)) {
				$output[] = PHP_EOL;
			}
			$output[] = '['.$section.']'.PHP_EOL;
			foreach ($keysToWrite as $key => $value) {
				$output[] = $key.' = '.libeufinconnectorFormatNexusConfigValue($value).PHP_EOL;
			}
		}
	}

	$written = file_put_contents($path, implode('', $output));
	if ($written === false) {
		$result['error'] = 'write_failed';
		return $result;
	}

	$result['ok'] = true;
	return $result;
}

/**
 * Probe whether the configured Nexus command can be executed by Dolibarr.
 *
 * @return array{status:string,command_preview:string,output:string}
 */
function libeufinconnectorProbeNexusRuntime()
{
	$binary = trim((string) getDolGlobalString('LIBEUFINCONNECTOR_NEXUS_BINARY'));
	$prefix = trim((string) getDolGlobalString('LIBEUFINCONNECTOR_NEXUS_COMMAND_PREFIX'));

	if ($binary === '') {
		return array(
			'status' => 'missing_binary',
			'command_preview' => '',
			'output' => '',
		);
	}

	$command = ($prefix !== '' ? $prefix.' ' : '').escapeshellarg($binary).' --version 2>&1';
	$output = array();
	$returnCode = 1;
	@exec($command, $output, $returnCode);

	return array(
		'status' => ($returnCode === 0 ? 'ok' : 'failed'),
		'command_preview' => trim(($prefix !== '' ? $prefix.' ' : '').$binary.' --version'),
		'output' => trim(implode("\n", $output)),
	);
}

/**
 * Return operation definitions supported from the admin UI.
 *
 * @return array<string,array{label:string,description:string,type:string,args:string[]}>
 */
function libeufinconnectorGetNexusOperations()
{
	return array(
		'nexus_dbinit' => array(
			'label' => 'Initialize Nexus database',
			'description' => 'Run libeufin-nexus-dbinit to create or update the Nexus database schema.',
			'type' => 'dbinit',
			'args' => array(),
		),
		'ebics_setup' => array(
			'label' => 'EBICS setup',
			'description' => 'Run libeufin-nexus ebics-setup. This is used for EBICS key setup and bank key acceptance.',
			'type' => 'nexus',
			'args' => array('ebics-setup'),
		),
		'ebics_accept_bank_keys' => array(
			'label' => 'Accept EBICS bank keys',
			'description' => 'Run libeufin-nexus ebics-setup with --auto-accept-keys after verifying the bank key fingerprints.',
			'type' => 'nexus',
			'args' => array('ebics-setup', '--auto-accept-keys'),
		),
		'ebics_fetch' => array(
			'label' => 'Fetch incoming transactions',
			'description' => 'Run one transient ebics-fetch pass for incoming transactions and payment status updates.',
			'type' => 'nexus',
			'args' => array('ebics-fetch'),
		),
		'ebics_submit' => array(
			'label' => 'Submit outgoing payments',
			'description' => 'Run one transient ebics-submit pass for pending outgoing payments.',
			'type' => 'nexus',
			'args' => array('ebics-submit'),
		),
	);
}

/**
 * Derive the dbinit binary path from the configured Nexus binary path.
 *
 * @return string
 */
function libeufinconnectorGetNexusDbinitBinary()
{
	$nexusBinary = trim((string) getDolGlobalString('LIBEUFINCONNECTOR_NEXUS_BINARY'));
	if ($nexusBinary === '') {
		return 'libeufin-nexus-dbinit';
	}

	$dirname = dirname($nexusBinary);
	$basename = basename($nexusBinary);
	if ($basename === 'libeufin-nexus') {
		return ($dirname === '.' ? 'libeufin-nexus-dbinit' : $dirname.'/libeufin-nexus-dbinit');
	}

	return 'libeufin-nexus-dbinit';
}

/**
 * Return the current Dolibarr runtime user.
 *
 * @return string
 */
function libeufinconnectorGetRuntimeUser()
{
	if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
		$userInfo = posix_getpwuid(posix_geteuid());
		if (is_array($userInfo) && !empty($userInfo['name'])) {
			return (string) $userInfo['name'];
		}
	}

	return 'www-data';
}

/**
 * Mask credentials in a PostgreSQL connection string before displaying it.
 *
 * @param string $connectionString PostgreSQL connection string.
 * @return string
 */
function libeufinconnectorMaskPostgresConnectionString($connectionString)
{
	return preg_replace('/(postgres(?:ql)?:\/\/[^:\/\s]+):([^@\/\s]+)@/i', '$1:***@', (string) $connectionString);
}

/**
 * Extract the PostgreSQL role and database name expected by a Nexus connection string.
 *
 * @param string $connectionString PostgreSQL connection string.
 * @param string $runtimeUser Fallback role for local peer-auth style connections.
 * @return array{role:string,database:string}
 */
function libeufinconnectorGetPostgresConnectionDetails($connectionString, $runtimeUser)
{
	$details = array(
		'role' => $runtimeUser,
		'database' => '',
	);
	$connectionString = trim((string) $connectionString);

	if (preg_match('/\buser=([^ ]+)/', $connectionString, $matches)) {
		$details['role'] = trim($matches[1], '"\'');
	}
	if (preg_match('/\bdbname=([^ ]+)/', $connectionString, $matches)) {
		$details['database'] = trim($matches[1], '"\'');
	}

	if (preg_match('/^postgres(?:ql)?:\/\/([^@\/]*@)?([^\/]*)\/?(.*)$/i', $connectionString, $matches)) {
		if (!empty($matches[1])) {
			$userInfo = rtrim($matches[1], '@');
			$userParts = explode(':', $userInfo, 2);
			if ($userParts[0] !== '') {
				$details['role'] = rawurldecode($userParts[0]);
			}
		}

		$path = isset($matches[3]) ? (string) $matches[3] : '';
		$path = preg_replace('/[?#].*$/', '', $path);
		if ($path !== '') {
			$details['database'] = rawurldecode($path);
		}
	}

	return $details;
}

/**
 * Build idempotent root commands that create the PostgreSQL role and database.
 *
 * @param string $role PostgreSQL role.
 * @param string $database PostgreSQL database.
 * @return string
 */
function libeufinconnectorBuildPostgresBootstrapHint($role, $database)
{
	$role = (string) $role;
	$database = (string) $database;
	$sqlRole = str_replace("'", "''", $role);
	$sqlDatabase = str_replace("'", "''", $database);

	$commands = array(
		'runuser -u postgres -- psql -tc '.escapeshellarg("SELECT 1 FROM pg_roles WHERE rolname = '".$sqlRole."'").' | grep -q 1 || runuser -u postgres -- createuser --no-superuser --no-createdb --no-createrole '.escapeshellarg($role),
	);

	if ($database !== '') {
		$commands[] = 'runuser -u postgres -- psql -tc '.escapeshellarg("SELECT 1 FROM pg_database WHERE datname = '".$sqlDatabase."'").' | grep -q 1 || runuser -u postgres -- createdb --owner='.escapeshellarg($role).' '.escapeshellarg($database);
	}

	return implode("\n\n", $commands);
}

/**
 * Probe whether the configured PostgreSQL connection can be reached by Dolibarr.
 *
 * @return array{status:string,command_preview:string,output:string,runtime_user:string,config:string,role:string,database:string,fix_hint:string}
 */
function libeufinconnectorProbePostgresRuntime()
{
	$result = array(
		'status' => 'missing_config',
		'command_preview' => '',
		'output' => '',
		'runtime_user' => libeufinconnectorGetRuntimeUser(),
		'config' => '',
		'role' => '',
		'database' => '',
		'fix_hint' => '',
	);

	$configPath = libeufinconnectorGetEffectiveNexusConfigPath();
	$config = libeufinconnectorReadNexusConfig($configPath);
	if ($config['error'] !== '') {
		$result['status'] = 'config_not_readable';
		$result['output'] = $config['error'];
		return $result;
	}

	$connectionString = '';
	if (isset($config['sections']['libeufin-nexusdb-postgres']['CONFIG'])) {
		$connectionString = trim((string) $config['sections']['libeufin-nexusdb-postgres']['CONFIG']);
	}
	if ($connectionString === '') {
		return $result;
	}

	$result['config'] = $connectionString;
	$connectionDetails = libeufinconnectorGetPostgresConnectionDetails($connectionString, $result['runtime_user']);
	$result['role'] = $connectionDetails['role'];
	$result['database'] = $connectionDetails['database'];

	$psql = '/usr/bin/psql';
	if (!is_executable($psql)) {
		$psql = 'psql';
	}

	$command = 'command -v '.escapeshellarg($psql).' >/dev/null 2>&1';
	$output = array();
	$returnCode = 1;
	@exec($command, $output, $returnCode);
	if ($returnCode !== 0) {
		$result['status'] = 'missing_psql';
		return $result;
	}

	$command = escapeshellarg($psql).' '.escapeshellarg($connectionString).' -v ON_ERROR_STOP=1 -tAc '.escapeshellarg('SELECT 1;').' 2>&1';
	$output = array();
	$returnCode = 1;
	@exec($command, $output, $returnCode);

	$result['status'] = ($returnCode === 0 ? 'ok' : 'failed');
	$result['command_preview'] = $psql.' '.libeufinconnectorMaskPostgresConnectionString($connectionString).' -v ON_ERROR_STOP=1 -tAc "SELECT 1;"';
	$result['output'] = trim(implode("\n", $output));
	if ($returnCode !== 0) {
		if (preg_match('/No such file or directory|Is the server running|Connection refused|could not connect to server|could not translate host name/i', $result['output'])) {
			$result['status'] = 'postgres_unreachable';
			$result['fix_hint'] = "Install and start PostgreSQL before creating the LibEuFin Nexus role/database.\n\nDebian example:\napt-get install postgresql postgresql-client\nsystemctl enable --now postgresql";
		} elseif (preg_match('/role "([^"]+)" does not exist/i', $result['output'], $matches)) {
			$result['status'] = 'missing_role';
			$result['role'] = $matches[1];
			$result['fix_hint'] = libeufinconnectorBuildPostgresBootstrapHint($result['role'], $result['database']);
		} elseif (preg_match('/database "([^"]+)" does not exist/i', $result['output'], $matches)) {
			$result['status'] = 'missing_database';
			$result['database'] = $matches[1];
			$result['fix_hint'] = libeufinconnectorBuildPostgresBootstrapHint($result['role'], $result['database']);
		} else {
			$result['fix_hint'] = '';
		}
	}

	return $result;
}

/**
 * Build a shell command for a supported Nexus operation.
 *
 * @param string $operation Operation code.
 * @return array{ok:bool,error:string,command:string,preview:string}
 */
function libeufinconnectorBuildNexusOperationCommand($operation)
{
	$operations = libeufinconnectorGetNexusOperations();
	if (!isset($operations[$operation])) {
		return array('ok' => false, 'error' => 'unknown_operation', 'command' => '', 'preview' => '');
	}

	$configPath = libeufinconnectorGetEffectiveNexusConfigPath();
	if ($configPath === '' || !is_readable($configPath)) {
		return array('ok' => false, 'error' => 'config_not_readable', 'command' => '', 'preview' => '');
	}

	$prefix = trim((string) getDolGlobalString('LIBEUFINCONNECTOR_NEXUS_COMMAND_PREFIX'));
	$definition = $operations[$operation];
	$binary = '';
	$args = array();

	if ($definition['type'] === 'dbinit') {
		$binary = libeufinconnectorGetNexusDbinitBinary();
		$args = array('-c', $configPath);
	} else {
		$binary = trim((string) getDolGlobalString('LIBEUFINCONNECTOR_NEXUS_BINARY'));
		$args = array_merge($definition['args'], array('-c', $configPath));
		if ($operation === 'ebics_fetch' || $operation === 'ebics_submit') {
			$args[] = '--transient';
		}
	}
	if ($binary === '') {
		return array('ok' => false, 'error' => 'missing_binary', 'command' => '', 'preview' => '');
	}

	$escaped = array();
	foreach ($args as $arg) {
		$escaped[] = escapeshellarg($arg);
	}

	$env = 'HOME='.escapeshellarg(libeufinconnectorGetDocumentsDir())
		.' TMPDIR='.escapeshellarg(libeufinconnectorGetTempDir())
		.' PATH='.escapeshellarg('/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin');

	$command = trim($env.' '.($prefix !== '' ? $prefix.' ' : '').escapeshellarg($binary).' '.implode(' ', $escaped));
	$preview = trim('HOME='.libeufinconnectorGetDocumentsDir().' TMPDIR='.libeufinconnectorGetTempDir().' PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin '.($prefix !== '' ? $prefix.' ' : '').$binary.' '.implode(' ', $args));

	return array('ok' => true, 'error' => '', 'command' => $command, 'preview' => $preview);
}

/**
 * Compact multi-line command output into a cleaner message.
 *
 * @param string $message Raw command output.
 * @return string
 */
function libeufinconnectorCompactCommandMessage($message)
{
	$message = trim((string) $message);
	if ($message === '') {
		return '';
	}

	$lines = preg_split('/\r\n|\r|\n/', $message);
	if (!is_array($lines)) {
		return $message;
	}

	$seen = array();
	$kept = array();
	foreach ($lines as $line) {
		$line = trim((string) $line);
		if ($line === '') {
			continue;
		}
		if (isset($seen[$line])) {
			continue;
		}
		$seen[$line] = true;
		$kept[] = $line;
	}

	return implode("\n", $kept);
}

/**
 * Run a supported Nexus operation synchronously and capture its output.
 *
 * @param string $operation Operation code.
 * @return array{ok:bool,error:string,output:string,preview:string,code:int}
 */
function libeufinconnectorRunNexusOperationNow($operation)
{
	$tempDirResult = libeufinconnectorEnsureTempDir();
	if (empty($tempDirResult['ok'])) {
		return array('ok' => false, 'error' => $tempDirResult['error'], 'output' => '', 'preview' => '', 'code' => 1);
	}
	$keyDirResult = libeufinconnectorEnsureLocalNexusKeysDir();
	if (empty($keyDirResult['ok'])) {
		return array('ok' => false, 'error' => $keyDirResult['error'], 'output' => '', 'preview' => '', 'code' => 1);
	}

	$build = libeufinconnectorBuildNexusOperationCommand($operation);
	if (empty($build['ok'])) {
		return array('ok' => false, 'error' => $build['error'], 'output' => '', 'preview' => '', 'code' => 1);
	}

	$output = array();
	$returnCode = 1;
	@exec($build['command'].' 2>&1', $output, $returnCode);
	$rawOutput = trim(implode("\n", $output));

	return array(
		'ok' => ($returnCode === 0),
		'error' => ($returnCode === 0 ? '' : libeufinconnectorCompactCommandMessage($rawOutput)),
		'output' => libeufinconnectorCompactCommandMessage($rawOutput),
		'preview' => $build['preview'],
		'code' => $returnCode,
	);
}

/**
 * Return operation metadata path.
 *
 * @param string $operation Operation code.
 * @return string
 */
function libeufinconnectorGetOperationStatePath($operation)
{
	return libeufinconnectorGetOperationsDir().'/'.$operation.'.json';
}

/**
 * Return operation log path.
 *
 * @param string $operation Operation code.
 * @return string
 */
function libeufinconnectorGetOperationLogPath($operation)
{
	return libeufinconnectorGetOperationsDir().'/'.$operation.'.log';
}

/**
 * Start an operation asynchronously.
 *
 * @param string $operation Operation code.
 * @return array{ok:bool,error:string,pid:int,preview:string,log_file:string}
 */
function libeufinconnectorStartNexusOperation($operation)
{
	$dirResult = libeufinconnectorEnsureOperationsDir();
	if (empty($dirResult['ok'])) {
		return array('ok' => false, 'error' => $dirResult['error'], 'pid' => 0, 'preview' => '', 'log_file' => '');
	}
	$tempDirResult = libeufinconnectorEnsureTempDir();
	if (empty($tempDirResult['ok'])) {
		return array('ok' => false, 'error' => $tempDirResult['error'], 'pid' => 0, 'preview' => '', 'log_file' => '');
	}
	$keyDirResult = libeufinconnectorEnsureLocalNexusKeysDir();
	if (empty($keyDirResult['ok'])) {
		return array('ok' => false, 'error' => $keyDirResult['error'], 'pid' => 0, 'preview' => '', 'log_file' => '');
	}

	$build = libeufinconnectorBuildNexusOperationCommand($operation);
	if (empty($build['ok'])) {
		return array('ok' => false, 'error' => $build['error'], 'pid' => 0, 'preview' => '', 'log_file' => '');
	}

	$logFile = libeufinconnectorGetOperationLogPath($operation);
	$stateFile = libeufinconnectorGetOperationStatePath($operation);
	$startedAt = gmdate('c');
	$command = '( printf "%s\n" '.escapeshellarg('Started at '.$startedAt).'; '.$build['command'].'; code=$?; printf "\n__LIBEUFINCONNECTOR_EXIT_CODE=%s\n" "$code"; ) > '.escapeshellarg($logFile).' 2>&1 & echo $!';
	$output = array();
	$returnCode = 1;
	@exec($command, $output, $returnCode);

	$pid = isset($output[0]) ? (int) $output[0] : 0;
	if ($pid <= 0) {
		return array('ok' => false, 'error' => 'start_failed', 'pid' => 0, 'preview' => $build['preview'], 'log_file' => $logFile);
	}

	$state = array(
		'operation' => $operation,
		'pid' => $pid,
		'started_at' => $startedAt,
		'command_preview' => $build['preview'],
		'log_file' => $logFile,
	);
	@file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));

	return array('ok' => true, 'error' => '', 'pid' => $pid, 'preview' => $build['preview'], 'log_file' => $logFile);
}

/**
 * Check if a process looks alive.
 *
 * @param int $pid Process ID.
 * @return bool
 */
function libeufinconnectorIsPidRunning($pid)
{
	if ($pid <= 0) {
		return false;
	}

	$output = array();
	$returnCode = 1;
	@exec('ps -p '.((int) $pid).' -o pid=', $output, $returnCode);

	return ($returnCode === 0 && !empty($output));
}

/**
 * Read the last bytes of an operation log.
 *
 * @param string $path Log path.
 * @param int $bytes Number of bytes.
 * @return string
 */
function libeufinconnectorReadLogTail($path, $bytes = 12000)
{
	if ($path === '' || !is_readable($path)) {
		return '';
	}

	$size = filesize($path);
	if ($size === false) {
		return '';
	}

	$handle = fopen($path, 'rb');
	if (!is_resource($handle)) {
		return '';
	}

	if ($size > $bytes) {
		fseek($handle, -1 * $bytes, SEEK_END);
	}

	$content = stream_get_contents($handle);
	fclose($handle);

	return is_string($content) ? $content : '';
}

/**
 * Return operation status and recent log output.
 *
 * @param string $operation Operation code.
 * @return array{operation:string,status:string,pid:int,started_at:string,command_preview:string,log_file:string,exit_code:string,log_tail:string}
 */
function libeufinconnectorGetNexusOperationStatus($operation)
{
	$stateFile = libeufinconnectorGetOperationStatePath($operation);
	$logFile = libeufinconnectorGetOperationLogPath($operation);
	$state = array();

	if (is_readable($stateFile)) {
		$content = file_get_contents($stateFile);
		$decoded = is_string($content) ? json_decode($content, true) : null;
		if (is_array($decoded)) {
			$state = $decoded;
		}
	}

	$pid = isset($state['pid']) ? (int) $state['pid'] : 0;
	$logTail = libeufinconnectorReadLogTail($logFile);
	$exitCode = '';
	if (preg_match('/__LIBEUFINCONNECTOR_EXIT_CODE=([0-9]+)/', $logTail, $matches)) {
		$exitCode = $matches[1];
	}

	$status = 'never_run';
	if ($pid > 0) {
		if ($exitCode !== '') {
			$status = ($exitCode === '0' ? 'success' : 'failed');
		} elseif (libeufinconnectorIsPidRunning($pid)) {
			$status = 'running';
		} else {
			$status = 'unknown';
		}
	}

	return array(
		'operation' => $operation,
		'status' => $status,
		'pid' => $pid,
		'started_at' => isset($state['started_at']) ? (string) $state['started_at'] : '',
		'command_preview' => isset($state['command_preview']) ? (string) $state['command_preview'] : '',
		'log_file' => $logFile,
		'exit_code' => $exitCode,
		'log_tail' => $logTail,
	);
}
