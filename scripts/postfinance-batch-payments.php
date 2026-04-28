#!/usr/bin/env php
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
 * \file    libeufinconnector/scripts/postfinance-batch-payments.php
 * \ingroup libeufinconnector
 * \brief   Queue a small batch of outgoing PostFinance payments via LibEuFin Nexus
 */

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "This script can only be run from the command line.\n");
	exit(1);
}

require_once __DIR__.'/../../../master.inc.php';
require_once __DIR__.'/../lib/nexusconfig.lib.php';

/**
 * Print script usage.
 *
 * @return void
 */
function libeufinconnectorBatchPaymentsUsage()
{
	$script = basename(__FILE__);

	$text = <<<TXT
Usage:
  php htdocs/custom/libeufinconnector/scripts/{$script} --file=/path/to/payments.json [--dry-run] [--submit] [--log=INFO]

Options:
  --file=PATH    JSON file containing an array of payment objects
  --dry-run      Validate and print the commands without queueing payments
  --submit       Run ebics-submit --transient after all payments were initiated
  --log=LEVEL    LibEuFin log level (ERROR, WARN, INFO, DEBUG, TRACE). Default: INFO
  --help         Show this help

Input format:
  Each payment must provide:
    - amount
    - end_to_end_id
    - either payto, or iban + name

  Optional fields:
    - bic
    - subject
    - currency

Example:
  php htdocs/custom/libeufinconnector/scripts/{$script} \\
    --file=htdocs/custom/libeufinconnector/scripts/postfinance-batch-payments.example.json \\
    --dry-run

TXT;

	fwrite(STDOUT, $text);
}

/**
 * Print an error line to STDERR.
 *
 * @param string $message Message to print.
 * @return void
 */
function libeufinconnectorBatchPaymentsError($message)
{
	fwrite(STDERR, $message."\n");
}

/**
 * Parse CLI options.
 *
 * @param array<int,string> $argv Raw argv.
 * @return array{help:bool,file:string,dry_run:bool,submit:bool,log_level:string,error:string}
 */
function libeufinconnectorBatchPaymentsParseOptions(array $argv)
{
	$options = array(
		'help' => false,
		'file' => '',
		'dry_run' => false,
		'submit' => false,
		'log_level' => 'INFO',
		'error' => '',
	);

	for ($i = 1; $i < count($argv); $i++) {
		$arg = (string) $argv[$i];

		if ($arg === '--help' || $arg === '-h') {
			$options['help'] = true;
			continue;
		}
		if ($arg === '--dry-run') {
			$options['dry_run'] = true;
			continue;
		}
		if ($arg === '--submit') {
			$options['submit'] = true;
			continue;
		}
		if (strpos($arg, '--file=') === 0) {
			$options['file'] = substr($arg, 7);
			continue;
		}
		if (strpos($arg, '--log=') === 0) {
			$options['log_level'] = strtoupper(trim(substr($arg, 6)));
			continue;
		}

		$options['error'] = 'Unknown option: '.$arg;
		return $options;
	}

	if (!$options['help'] && $options['file'] === '') {
		$options['error'] = 'Missing required --file option.';
	}

	if (!in_array($options['log_level'], array('ERROR', 'WARN', 'INFO', 'DEBUG', 'TRACE'), true)) {
		$options['error'] = 'Unsupported log level: '.$options['log_level'];
	}

	return $options;
}

/**
 * Return the default payment currency.
 *
 * @return string
 */
function libeufinconnectorBatchPaymentsGetDefaultCurrency()
{
	$currency = trim((string) getDolGlobalString('LIBEUFINCONNECTOR_EXPECTED_CURRENCY'));
	if ($currency !== '') {
		return strtoupper($currency);
	}

	$config = libeufinconnectorReadNexusConfig(libeufinconnectorGetEffectiveNexusConfigPath());
	if ($config['error'] === '' && isset($config['sections']['nexus-ebics']['CURRENCY'])) {
		$currency = trim((string) $config['sections']['nexus-ebics']['CURRENCY']);
	}

	if ($currency !== '') {
		return strtoupper($currency);
	}

	return strtoupper((string) getDolGlobalString('MAIN_MONNAIE'));
}

/**
 * Normalize a payment amount into the LibEuFin CLI format.
 *
 * @param mixed  $amount Amount value.
 * @param string $currency ISO currency code.
 * @return string
 */
function libeufinconnectorBatchPaymentsFormatAmount($amount, $currency)
{
	$normalizedAmount = price2num((string) $amount, 'MU');
	return strtoupper($currency).':'.rtrim(rtrim(number_format((float) $normalizedAmount, 8, '.', ''), '0'), '.');
}

/**
 * Build a payto URI from JSON fields.
 *
 * @param array<string,mixed> $payment Payment input.
 * @return string
 */
function libeufinconnectorBatchPaymentsBuildPayto(array $payment)
{
	if (!empty($payment['payto'])) {
		return trim((string) $payment['payto']);
	}

	$iban = strtoupper(str_replace(' ', '', trim((string) (isset($payment['iban']) ? $payment['iban'] : ''))));
	$bic = strtoupper(str_replace(' ', '', trim((string) (isset($payment['bic']) ? $payment['bic'] : ''))));
	$name = trim((string) (isset($payment['name']) ? $payment['name'] : ''));

	$path = ($bic !== '' ? $bic.'/' : '').$iban;
	$query = 'receiver-name='.rawurlencode($name);

	return 'payto://iban/'.$path.'?'.$query;
}

/**
 * Validate a payto URI used for initiation.
 *
 * @param string $payto Payto URI.
 * @return string Empty string when valid, otherwise an error message.
 */
function libeufinconnectorBatchPaymentsValidatePayto($payto)
{
	if ($payto === '' || strpos($payto, 'payto://') !== 0) {
		return 'must provide a valid payto URI';
	}

	$parts = parse_url($payto);
	if (!is_array($parts)) {
		return 'contains an invalid payto URI';
	}

	$query = array();
	if (!empty($parts['query'])) {
		parse_str((string) $parts['query'], $query);
	}

	if (empty($query['receiver-name'])) {
		return 'payto URI must contain receiver-name';
	}
	if (!empty($query['amount'])) {
		return 'payto URI must not contain amount; use the amount field in JSON';
	}
	if (!empty($query['message'])) {
		return 'payto URI must not contain message; use the subject field in JSON';
	}

	return '';
}

/**
 * Load and decode the input file.
 *
 * @param string $path JSON file path.
 * @return array{ok:bool,error:string,payments:array<int,array<string,mixed>>}
 */
function libeufinconnectorBatchPaymentsLoadFile($path)
{
	if (!is_readable($path)) {
		return array('ok' => false, 'error' => 'Input file is not readable: '.$path, 'payments' => array());
	}

	$content = file_get_contents($path);
	if (!is_string($content)) {
		return array('ok' => false, 'error' => 'Failed to read input file: '.$path, 'payments' => array());
	}

	$decoded = json_decode($content, true);
	if (!is_array($decoded)) {
		return array('ok' => false, 'error' => 'Input file must contain a JSON array.', 'payments' => array());
	}

	return array('ok' => true, 'error' => '', 'payments' => $decoded);
}

/**
 * Return the Postgres connection string from the effective config.
 *
 * @return array{ok:bool,error:string,connection:string}
 */
function libeufinconnectorBatchPaymentsGetPostgresConnection()
{
	$config = libeufinconnectorReadNexusConfig(libeufinconnectorGetEffectiveNexusConfigPath());
	if ($config['error'] !== '') {
		return array('ok' => false, 'error' => 'Unable to read Nexus config: '.$config['error'], 'connection' => '');
	}

	$connection = '';
	if (isset($config['sections']['libeufin-nexusdb-postgres']['CONFIG'])) {
		$connection = trim((string) $config['sections']['libeufin-nexusdb-postgres']['CONFIG']);
	}

	if ($connection === '') {
		return array('ok' => false, 'error' => 'Missing [libeufin-nexusdb-postgres] CONFIG in the effective Nexus config.', 'connection' => '');
	}

	return array('ok' => true, 'error' => '', 'connection' => $connection);
}

/**
 * Escape a string for a SQL literal.
 *
 * @param string $value Value to escape.
 * @return string
 */
function libeufinconnectorBatchPaymentsEscapeSqlLiteral($value)
{
	return str_replace("'", "''", (string) $value);
}

/**
 * Return request UIDs already present in Nexus.
 *
 * @param array<int,string> $uids UIDs to check.
 * @return array{ok:bool,error:string,existing:array<int,string>}
 */
function libeufinconnectorBatchPaymentsFindExistingRequestUids(array $uids)
{
	$uids = array_values(array_unique(array_filter(array_map('trim', $uids), 'strlen')));
	if (empty($uids)) {
		return array('ok' => true, 'error' => '', 'existing' => array());
	}

	$connection = libeufinconnectorBatchPaymentsGetPostgresConnection();
	if (empty($connection['ok'])) {
		return array('ok' => false, 'error' => $connection['error'], 'existing' => array());
	}

	$literals = array();
	foreach ($uids as $uid) {
		$literals[] = "'".libeufinconnectorBatchPaymentsEscapeSqlLiteral($uid)."'";
	}

	$sql = "SELECT end_to_end_id FROM libeufin_nexus.initiated_outgoing_transactions WHERE end_to_end_id IN (".implode(', ', $literals).")";
	$sql .= " UNION ";
	$sql .= "SELECT end_to_end_id FROM libeufin_nexus.outgoing_transactions WHERE end_to_end_id IN (".implode(', ', $literals).")";
	$sql .= " ORDER BY end_to_end_id";

	$psql = '/usr/bin/psql';
	if (!is_executable($psql)) {
		$psql = 'psql';
	}

	$command = escapeshellarg($psql)
		.' '.escapeshellarg($connection['connection'])
		.' -Atc '.escapeshellarg($sql)
		.' 2>&1';

	$output = array();
	$returnCode = 1;
	@exec($command, $output, $returnCode);

	if ($returnCode !== 0) {
		return array('ok' => false, 'error' => trim(implode("\n", $output)), 'existing' => array());
	}

	$existing = array();
	foreach ($output as $line) {
		$line = trim((string) $line);
		if ($line !== '') {
			$existing[] = $line;
		}
	}

	return array('ok' => true, 'error' => '', 'existing' => $existing);
}

/**
 * Validate and normalize the payment batch.
 *
 * @param array<int,array<string,mixed>> $payments Raw payments.
 * @return array{ok:bool,error:string,items:array<int,array<string,string>>}
 */
function libeufinconnectorBatchPaymentsNormalize(array $payments)
{
	$items = array();
	$errors = array();
	$seenUids = array();
	$defaultCurrency = libeufinconnectorBatchPaymentsGetDefaultCurrency();

	foreach ($payments as $index => $payment) {
		if (!is_array($payment)) {
			$errors[] = 'Item '.($index + 1).' is not an object.';
			continue;
		}

		$amount = isset($payment['amount']) ? trim((string) $payment['amount']) : '';
		$currency = isset($payment['currency']) ? strtoupper(trim((string) $payment['currency'])) : $defaultCurrency;
		$subject = isset($payment['subject']) ? trim((string) $payment['subject']) : '';
		$endToEndId = isset($payment['end_to_end_id']) ? trim((string) $payment['end_to_end_id']) : '';
		$payto = libeufinconnectorBatchPaymentsBuildPayto($payment);

		if ($amount === '' || !is_numeric($amount) || (float) $amount <= 0) {
			$errors[] = 'Item '.($index + 1).' has an invalid positive amount.';
		}
		if ($currency === '') {
			$errors[] = 'Item '.($index + 1).' is missing currency and no default currency was found.';
		}
		if ($endToEndId === '') {
			$errors[] = 'Item '.($index + 1).' is missing end_to_end_id.';
		}
		$paytoError = libeufinconnectorBatchPaymentsValidatePayto($payto);
		if ($paytoError !== '') {
			$errors[] = 'Item '.($index + 1).' '.$paytoError.'.';
		}

		if ($endToEndId !== '') {
			if (isset($seenUids[$endToEndId])) {
				$errors[] = 'Duplicate end_to_end_id in input file: '.$endToEndId;
			}
			$seenUids[$endToEndId] = true;
		}

		$items[] = array(
			'index' => (string) ($index + 1),
			'amount' => libeufinconnectorBatchPaymentsFormatAmount($amount, $currency),
			'subject' => $subject,
			'end_to_end_id' => $endToEndId,
			'payto' => $payto,
		);
	}

	if (!empty($errors)) {
		return array('ok' => false, 'error' => implode("\n", $errors), 'items' => array());
	}

	$existing = libeufinconnectorBatchPaymentsFindExistingRequestUids(array_keys($seenUids));
	if (empty($existing['ok'])) {
		return array('ok' => false, 'error' => 'Duplicate check failed: '.$existing['error'], 'items' => array());
	}

	if (!empty($existing['existing'])) {
		return array('ok' => false, 'error' => 'The following end_to_end_id values already exist in Nexus: '.implode(', ', $existing['existing']), 'items' => array());
	}

	return array('ok' => true, 'error' => '', 'items' => $items);
}

/**
 * Build a shell command for libeufin-nexus.
 *
 * @param array<int,string> $args Command arguments after the binary name.
 * @param string            $logLevel Log level.
 * @return array{ok:bool,error:string,command:string,preview:string}
 */
function libeufinconnectorBatchPaymentsBuildNexusCommand(array $args, $logLevel)
{
	$configPath = libeufinconnectorGetEffectiveNexusConfigPath();
	if ($configPath === '' || !is_readable($configPath)) {
		return array('ok' => false, 'error' => 'Effective Nexus config is not readable: '.$configPath, 'command' => '', 'preview' => '');
	}

	$tempDirResult = libeufinconnectorEnsureTempDir();
	if (empty($tempDirResult['ok'])) {
		return array('ok' => false, 'error' => 'Unable to prepare temp directory: '.$tempDirResult['error'], 'command' => '', 'preview' => '');
	}

	$binary = trim((string) getDolGlobalString('LIBEUFINCONNECTOR_NEXUS_BINARY'));
	$prefix = trim((string) getDolGlobalString('LIBEUFINCONNECTOR_NEXUS_COMMAND_PREFIX'));
	if ($binary === '') {
		return array('ok' => false, 'error' => 'LIBEUFINCONNECTOR_NEXUS_BINARY is not configured.', 'command' => '', 'preview' => '');
	}

	$fullArgs = array_merge($args, array('-c', $configPath, '-L', $logLevel));
	$escaped = array();
	foreach ($fullArgs as $arg) {
		$escaped[] = escapeshellarg($arg);
	}

	$env = 'HOME='.escapeshellarg(libeufinconnectorGetDocumentsDir())
		.' TMPDIR='.escapeshellarg(libeufinconnectorGetTempDir())
		.' PATH='.escapeshellarg('/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin');

	$command = trim($env.' '.($prefix !== '' ? $prefix.' ' : '').escapeshellarg($binary).' '.implode(' ', $escaped));
	$preview = trim('HOME='.libeufinconnectorGetDocumentsDir().' TMPDIR='.libeufinconnectorGetTempDir().' PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin '.($prefix !== '' ? $prefix.' ' : '').$binary.' '.implode(' ', $fullArgs));

	return array('ok' => true, 'error' => '', 'command' => $command, 'preview' => $preview);
}

/**
 * Execute one shell command and return its output.
 *
 * @param string $command Full command.
 * @return array{ok:bool,output:string,code:int}
 */
function libeufinconnectorBatchPaymentsExec($command)
{
	$output = array();
	$returnCode = 1;
	@exec($command.' 2>&1', $output, $returnCode);

	return array(
		'ok' => ($returnCode === 0),
		'output' => trim(implode("\n", $output)),
		'code' => $returnCode,
	);
}

$options = libeufinconnectorBatchPaymentsParseOptions($argv);
if ($options['help']) {
	libeufinconnectorBatchPaymentsUsage();
	exit(0);
}
if ($options['error'] !== '') {
	libeufinconnectorBatchPaymentsError($options['error']);
	libeufinconnectorBatchPaymentsUsage();
	exit(1);
}

$loaded = libeufinconnectorBatchPaymentsLoadFile($options['file']);
if (empty($loaded['ok'])) {
	libeufinconnectorBatchPaymentsError($loaded['error']);
	exit(1);
}

$normalized = libeufinconnectorBatchPaymentsNormalize($loaded['payments']);
if (empty($normalized['ok'])) {
	libeufinconnectorBatchPaymentsError($normalized['error']);
	exit(1);
}

if (empty($normalized['items'])) {
	libeufinconnectorBatchPaymentsError('The input file does not contain any payments.');
	exit(1);
}

$commands = array();
foreach ($normalized['items'] as $item) {
	$args = array(
		'initiate-payment',
		$item['payto'],
		'--amount='.$item['amount'],
		'--end-to-end-id='.$item['end_to_end_id'],
	);
	if ($item['subject'] !== '') {
		$args[] = '--subject='.$item['subject'];
	}

	$build = libeufinconnectorBatchPaymentsBuildNexusCommand($args, $options['log_level']);
	if (empty($build['ok'])) {
		libeufinconnectorBatchPaymentsError($build['error']);
		exit(1);
	}

	$commands[] = array(
		'label' => 'payment #'.$item['index'].' '.$item['end_to_end_id'],
		'command' => $build['command'],
		'preview' => $build['preview'],
	);
}

$submitCommand = null;
if ($options['submit']) {
	$buildSubmit = libeufinconnectorBatchPaymentsBuildNexusCommand(array('ebics-submit', '--transient'), $options['log_level']);
	if (empty($buildSubmit['ok'])) {
		libeufinconnectorBatchPaymentsError($buildSubmit['error']);
		exit(1);
	}

	$submitCommand = $buildSubmit;
}

fwrite(STDOUT, 'Validated '.count($commands)." payments.\n");
foreach ($commands as $command) {
	fwrite(STDOUT, '[plan] '.$command['label'].":\n".$command['preview']."\n");
}
if ($submitCommand !== null) {
	fwrite(STDOUT, "[plan] final submit:\n".$submitCommand['preview']."\n");
}

if ($options['dry_run']) {
	fwrite(STDOUT, "Dry run only. No payments were initiated.\n");
	exit(0);
}

foreach ($commands as $command) {
	fwrite(STDOUT, '[run] '.$command['label']."\n");
	$result = libeufinconnectorBatchPaymentsExec($command['command']);
	if (!$result['ok']) {
		libeufinconnectorBatchPaymentsError('Command failed for '.$command['label'].' (exit '.$result['code'].')');
		if ($result['output'] !== '') {
			libeufinconnectorBatchPaymentsError($result['output']);
		}
		exit(1);
	}
	if ($result['output'] !== '') {
		fwrite(STDOUT, $result['output']."\n");
	}
}

if ($submitCommand !== null) {
	fwrite(STDOUT, "[run] ebics-submit --transient\n");
	$result = libeufinconnectorBatchPaymentsExec($submitCommand['command']);
	if (!$result['ok']) {
		libeufinconnectorBatchPaymentsError('ebics-submit failed (exit '.$result['code'].')');
		if ($result['output'] !== '') {
			libeufinconnectorBatchPaymentsError($result['output']);
		}
		exit(1);
	}
	if ($result['output'] !== '') {
		fwrite(STDOUT, $result['output']."\n");
	}
}

fwrite(STDOUT, "Batch completed successfully.\n");
exit(0);
