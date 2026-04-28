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
 * \file    libeufinconnector/lib/transactionworkflow.lib.php
 * \ingroup libeufinconnector
 * \brief   Import and workflow helpers for staged LibEuFin transactions
 */

require_once __DIR__.'/nexusconfig.lib.php';
require_once __DIR__.'/transactionstaging.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/price.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/security.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';

/**
 * Return the configured Nexus PostgreSQL connection string.
 *
 * @return array{ok:bool,error:string,connection:string}
 */
function libeufinconnectorGetNexusPostgresConnectionConfig()
{
	$configPath = libeufinconnectorGetEffectiveNexusConfigPath();
	$config = libeufinconnectorReadNexusConfig($configPath);
	if ($config['error'] !== '') {
		return array('ok' => false, 'error' => 'config_'.$config['error'], 'connection' => '');
	}

	$connectionString = '';
	if (isset($config['sections']['libeufin-nexusdb-postgres']['CONFIG'])) {
		$connectionString = trim((string) $config['sections']['libeufin-nexusdb-postgres']['CONFIG']);
	}

	if ($connectionString === '') {
		return array('ok' => false, 'error' => 'missing_connection_string', 'connection' => '');
	}

	return array('ok' => true, 'error' => '', 'connection' => $connectionString);
}

/**
 * Run a JSON-line query against the Nexus PostgreSQL database.
 *
 * @param string $sql SQL query returning one JSON object per row.
 * @return array{ok:bool,error:string,rows:array<int,array<string,mixed>>,output:string}
 */
function libeufinconnectorRunNexusJsonQuery($sql)
{
	$config = libeufinconnectorGetNexusPostgresConnectionConfig();
	if (empty($config['ok'])) {
		return array('ok' => false, 'error' => $config['error'], 'rows' => array(), 'output' => '');
	}

	$psql = '/usr/bin/psql';
	if (!is_executable($psql)) {
		$psql = 'psql';
	}

	$command = escapeshellarg($psql)
		.' '.escapeshellarg($config['connection'])
		.' -v ON_ERROR_STOP=1 -Atc '.escapeshellarg($sql)
		.' 2>&1';

	$output = array();
	$returnCode = 1;
	@exec($command, $output, $returnCode);
	$rawOutput = trim(implode("\n", $output));

	if ($returnCode !== 0) {
		return array('ok' => false, 'error' => 'query_failed', 'rows' => array(), 'output' => $rawOutput);
	}

	$rows = array();
	foreach ($output as $line) {
		$line = trim((string) $line);
		if ($line === '') {
			continue;
		}

		$decoded = json_decode($line, true);
		if (!is_array($decoded)) {
			return array('ok' => false, 'error' => 'invalid_json_row', 'rows' => array(), 'output' => $line);
		}

		$rows[] = $decoded;
	}

	return array('ok' => true, 'error' => '', 'rows' => $rows, 'output' => $rawOutput);
}

/**
 * Return the effective transaction currency for imported Nexus rows.
 *
 * @return string
 */
function libeufinconnectorGetImportedTransactionCurrency()
{
	$currency = trim((string) getDolGlobalString('LIBEUFINCONNECTOR_EXPECTED_CURRENCY'));
	if ($currency !== '') {
		return strtoupper($currency);
	}

	$configPath = libeufinconnectorGetEffectiveNexusConfigPath();
	$config = libeufinconnectorReadNexusConfig($configPath);
	if ($config['error'] === '' && isset($config['sections']['nexus-ebics']['CURRENCY'])) {
		$currency = trim((string) $config['sections']['nexus-ebics']['CURRENCY']);
	}

	if ($currency !== '') {
		return strtoupper($currency);
	}

	return strtoupper((string) getDolGlobalString('MAIN_MONNAIE'));
}

/**
 * Normalize a Nexus bigint epoch value into a Dolibarr datetime string.
 *
 * @param mixed $value Epoch-like value.
 * @return string|null
 */
function libeufinconnectorNormalizeNexusEpoch($value)
{
	if ($value === null || $value === '') {
		return null;
	}

	$timestamp = (float) $value;
	if ($timestamp <= 0) {
		return null;
	}

	if ($timestamp > 1000000000000000000) {
		$timestamp = $timestamp / 1000000000;
	} elseif ($timestamp > 1000000000000000) {
		$timestamp = $timestamp / 1000000;
	} elseif ($timestamp > 1000000000000) {
		$timestamp = $timestamp / 1000;
	}

	return dol_print_date((int) round($timestamp), '%Y-%m-%d %H:%M:%S');
}

/**
 * Convert a staged SQL datetime into a Dolibarr timestamp.
 *
 * @param string|null $value SQL datetime-like value.
 * @return int
 */
function libeufinconnectorResolveTransactionTimestamp($value)
{
	if (!empty($value)) {
		$timestamp = dol_stringtotime((string) $value);
		if (!empty($timestamp)) {
			return (int) $timestamp;
		}
	}

	return dol_now();
}

/**
 * Convert a LibEuFin taler_amount pair to a decimal value.
 *
 * @param mixed $val Whole amount.
 * @param mixed $frac Fraction amount on 1e8.
 * @return float
 */
function libeufinconnectorNormalizeNexusAmount($val, $frac)
{
	$whole = (int) $val;
	$fraction = (int) $frac;
	$sign = ($whole < 0 || $fraction < 0) ? -1 : 1;

	return (float) price2num($sign * (abs($whole) + (abs($fraction) / 100000000)), 'MU');
}

/**
 * Build a payto://iban URI from normalized account data.
 *
 * @param string $iban IBAN.
 * @param string $name Counterparty name.
 * @param string $bic  Optional BIC.
 * @return string
 */
function libeufinconnectorBuildIbanPaytoUri($iban, $name, $bic = '')
{
	$iban = strtoupper(str_replace(' ', '', trim((string) $iban)));
	$name = trim((string) $name);
	$bic = strtoupper(str_replace(' ', '', trim((string) $bic)));

	if ($iban === '' || $name === '') {
		return '';
	}

	$path = ($bic !== '' ? $bic.'/' : '').$iban;

	return 'payto://iban/'.$path.'?receiver-name='.rawurlencode($name);
}

/**
 * Parse a payto URI and extract counterparty account data.
 *
 * @param string $paytoUri Payto URI.
 * @return array{uri:string,iban:string,bic:string,name:string,params:array<string,string>}
 */
function libeufinconnectorParsePaytoUri($paytoUri)
{
	$result = array(
		'uri' => trim((string) $paytoUri),
		'iban' => '',
		'bic' => '',
		'name' => '',
		'params' => array(),
	);

	if ($result['uri'] === '') {
		return $result;
	}

	$parts = parse_url($result['uri']);
	if (!is_array($parts)) {
		return $result;
	}

	$query = array();
	if (!empty($parts['query'])) {
		parse_str((string) $parts['query'], $query);
		foreach ($query as $key => $value) {
			$result['params'][strtolower((string) $key)] = trim((string) $value);
		}
	}

	$path = trim((string) (isset($parts['path']) ? $parts['path'] : ''), '/');
	$segments = array_values(array_filter(explode('/', $path), 'strlen'));

	if (count($segments) >= 2) {
		$result['bic'] = strtoupper(str_replace(' ', '', $segments[0]));
		$result['iban'] = strtoupper(str_replace(' ', '', $segments[1]));
	} elseif (count($segments) === 1) {
		$result['iban'] = strtoupper(str_replace(' ', '', $segments[0]));
	}

	if (isset($result['params']['bic']) && $result['params']['bic'] !== '') {
		$result['bic'] = strtoupper(str_replace(' ', '', $result['params']['bic']));
	}

	foreach (array('receiver-name', 'sender-name', 'beneficiary-name', 'name') as $key) {
		if (!empty($result['params'][$key])) {
			$result['name'] = trim((string) $result['params'][$key]);
			break;
		}
	}

	return $result;
}

/**
 * Tell whether a string looks like an IBAN.
 *
 * @param string $value Candidate value.
 * @return bool
 */
function libeufinconnectorLooksLikeIban($value)
{
	$value = strtoupper(str_replace(' ', '', trim((string) $value)));

	return ($value !== '' && preg_match('/^[A-Z]{2}[0-9A-Z]{13,32}$/', $value) === 1);
}

/**
 * Decrypt a Dolibarr bank field when it is stored encrypted.
 *
 * @param mixed $value Raw database value.
 * @return string
 */
function libeufinconnectorDecryptBankField($value)
{
	$value = trim((string) $value);
	if ($value === '') {
		return '';
	}

	if (stripos($value, 'dolcrypt:') !== 0) {
		return $value;
	}

	$decrypted = dolDecrypt($value);
	if (!is_string($decrypted) || $decrypted === '') {
		return '';
	}

	return $decrypted;
}

/**
 * Build a deterministic end-to-end id for Dolibarr-prepared outgoing rows.
 *
 * @param array<string,mixed> $row Prepared payment row.
 * @return string
 */
function libeufinconnectorBuildPreparedOutgoingEndToEndId(array $row)
{
	$entity = !empty($row['entity']) ? (int) $row['entity'] : 1;
	$bonId = !empty($row['prelevement_bons_id']) ? (int) $row['prelevement_bons_id'] : 0;
	$requestId = !empty($row['prelevement_demande_id']) ? (int) $row['prelevement_demande_id'] : 0;

	return 'LFC-DOL-E'.$entity.'-B'.$bonId.'-D'.$requestId;
}

/**
 * Build a human-readable subject for Dolibarr-prepared outgoing rows.
 *
 * @param array<string,mixed> $row Prepared payment row.
 * @return string
 */
function libeufinconnectorBuildPreparedOutgoingSubject(array $row)
{
	if (!empty($row['supplier_invoice_ref'])) {
		return 'Payment '.$row['supplier_invoice_ref'];
	}
	if (!empty($row['customer_invoice_ref'])) {
		return 'Payment '.$row['customer_invoice_ref'];
	}
	if (!empty($row['prelevement_bons_ref'])) {
		return 'Transfer order '.$row['prelevement_bons_ref'];
	}

	return 'Transfer order #'.((int) (!empty($row['prelevement_bons_id']) ? $row['prelevement_bons_id'] : 0));
}

/**
 * Validate whether a prepared outgoing row has enough beneficiary details to be sent.
 *
 * @param array<string,mixed>                 $row Prepared payment row.
 * @param array{iban:string,bic:string,name:string,payto:string} $counterparty Resolved counterparty details.
 * @return array{ready:bool,issues:array<int,string>}
 */
function libeufinconnectorValidatePreparedOutgoingCounterparty(array $row, array $counterparty)
{
	$issues = array();

	if (empty($row['fk_societe_rib'])) {
		$issues[] = 'missing_bank_account';
	}
	if ($counterparty['name'] === '') {
		$issues[] = 'missing_counterparty_name';
	}
	if ($counterparty['iban'] === '') {
		$issues[] = 'missing_counterparty_iban';
	}

	return array(
		'ready' => empty($issues),
		'issues' => $issues,
	);
}

/**
 * Read one third-party bank account.
 *
 * @param DoliDB $db Database handler.
 * @param int    $bankAccountId Third-party bank account id.
 * @param int    $socid Optional third-party id constraint.
 * @return array{ok:bool,error:string,row:array<string,mixed>}
 */
function libeufinconnectorReadThirdpartyBankAccount($db, $bankAccountId, $socid = 0)
{
	$sql = "SELECT rowid, fk_soc, label, proprio, bic, iban_prefix, number, default_rib, status";
	$sql .= " FROM ".MAIN_DB_PREFIX."societe_rib";
	$sql .= " WHERE rowid = ".((int) $bankAccountId);
	if ($socid > 0) {
		$sql .= " AND fk_soc = ".((int) $socid);
	}
	$sql .= " AND type = 'ban'";

	$resql = $db->query($sql);
	if (!$resql) {
		return array('ok' => false, 'error' => $db->lasterror(), 'row' => array());
	}

	$obj = $db->fetch_object($resql);
	$db->free($resql);
	if (!$obj) {
		return array('ok' => false, 'error' => 'bank_account_not_found', 'row' => array());
	}

	$row = (array) $obj;
	$row['iban_prefix'] = libeufinconnectorDecryptBankField(isset($row['iban_prefix']) ? $row['iban_prefix'] : '');

	return array('ok' => true, 'error' => '', 'row' => $row);
}

/**
 * Read selectable third-party bank accounts.
 *
 * @param DoliDB $db Database handler.
 * @param int    $socid Third-party id.
 * @return array{ok:bool,error:string,rows:array<int,array<string,mixed>>}
 */
function libeufinconnectorReadThirdpartyBankAccounts($db, $socid)
{
	$rows = array();
	if ((int) $socid <= 0) {
		return array('ok' => true, 'error' => '', 'rows' => $rows);
	}

	$sql = "SELECT rowid, fk_soc, label, proprio, bic, iban_prefix, number, default_rib, status";
	$sql .= " FROM ".MAIN_DB_PREFIX."societe_rib";
	$sql .= " WHERE fk_soc = ".((int) $socid);
	$sql .= " AND type = 'ban'";
	$sql .= " ORDER BY default_rib DESC, rowid ASC";

	$resql = $db->query($sql);
	if (!$resql) {
		return array('ok' => false, 'error' => $db->lasterror(), 'rows' => array());
	}

	while ($obj = $db->fetch_object($resql)) {
		$row = (array) $obj;
		$row['iban_prefix'] = libeufinconnectorDecryptBankField(isset($row['iban_prefix']) ? $row['iban_prefix'] : '');
		$rows[] = $row;
	}
	$db->free($resql);

	return array('ok' => true, 'error' => '', 'rows' => $rows);
}

/**
 * Resolve outgoing counterparty account data from a third-party bank account row.
 *
 * @param array<string,mixed> $bankAccount Third-party bank account row.
 * @param string              $fallbackName Fallback beneficiary name.
 * @return array{iban:string,bic:string,name:string,payto:string}
 */
function libeufinconnectorResolveBankAccountCounterparty(array $bankAccount, $fallbackName = '')
{
	$name = '';
	foreach (array('proprio', 'label') as $field) {
		if (!empty($bankAccount[$field])) {
			$name = trim((string) $bankAccount[$field]);
			break;
		}
	}
	if ($name === '') {
		$name = trim((string) $fallbackName);
	}

	$iban = '';
	foreach (array('iban_prefix', 'number') as $field) {
		if (empty($bankAccount[$field])) {
			continue;
		}

		$candidate = libeufinconnectorDecryptBankField($bankAccount[$field]);
		if (libeufinconnectorLooksLikeIban($candidate)) {
			$iban = strtoupper(str_replace(' ', '', trim($candidate)));
			break;
		}
	}

	$bic = '';
	if (!empty($bankAccount['bic'])) {
		$candidate = strtoupper(str_replace(' ', '', trim((string) $bankAccount['bic'])));
		if (preg_match('/^[A-Z0-9]{8,11}$/', $candidate) === 1) {
			$bic = $candidate;
		}
	}

	return array(
		'iban' => $iban,
		'bic' => $bic,
		'name' => $name,
		'payto' => libeufinconnectorBuildIbanPaytoUri($iban, $name, $bic),
	);
}

/**
 * Resolve outgoing counterparty account data from a Dolibarr payment request row.
 *
 * @param array<string,mixed> $row Prepared payment row.
 * @return array{iban:string,bic:string,name:string,payto:string}
 */
function libeufinconnectorResolvePreparedOutgoingCounterparty(array $row)
{
	$name = '';
	foreach (array('supplier_name', 'customer_name', 'rib_owner', 'rib_label') as $field) {
		if (!empty($row[$field])) {
			$name = trim((string) $row[$field]);
			break;
		}
	}

	$iban = '';
	foreach (array('rib_iban', 'request_number', 'rib_number') as $field) {
		if (empty($row[$field])) {
			continue;
		}

		$candidate = libeufinconnectorDecryptBankField($row[$field]);
		if (libeufinconnectorLooksLikeIban($candidate)) {
			$iban = strtoupper(str_replace(' ', '', trim($candidate)));
			break;
		}
	}

	$bic = '';
	foreach (array('rib_bic', 'request_code_banque') as $field) {
		if (empty($row[$field])) {
			continue;
		}

		$candidate = strtoupper(str_replace(' ', '', trim((string) $row[$field])));
		if (preg_match('/^[A-Z0-9]{8,11}$/', $candidate) === 1) {
			$bic = $candidate;
			break;
		}
	}

	return array(
		'iban' => $iban,
		'bic' => $bic,
		'name' => $name,
		'payto' => libeufinconnectorBuildIbanPaytoUri($iban, $name, $bic),
	);
}

/**
 * Apply a selected third-party bank account to outgoing staging data.
 *
 * @param array<string,mixed> $data Staging data.
 * @param array<string,mixed> $bankAccount Third-party bank account row.
 * @return array<string,mixed>
 */
function libeufinconnectorApplyOutgoingBeneficiaryBankAccountToData(array $data, array $bankAccount)
{
	$payload = array();
	if (isset($data['raw_payload']) && is_array($data['raw_payload'])) {
		$payload = $data['raw_payload'];
	} elseif (!empty($data['raw_payload'])) {
		$decoded = json_decode((string) $data['raw_payload'], true);
		$payload = is_array($decoded) ? $decoded : array();
	}

	$dolibarr = (isset($payload['dolibarr']) && is_array($payload['dolibarr'])) ? $payload['dolibarr'] : array();
	$fallbackName = '';
	foreach (array('supplier_name', 'customer_name') as $field) {
		if (!empty($dolibarr[$field])) {
			$fallbackName = (string) $dolibarr[$field];
			break;
		}
	}
	if ($fallbackName === '' && !empty($data['counterparty_name'])) {
		$fallbackName = (string) $data['counterparty_name'];
	}

	$counterparty = libeufinconnectorResolveBankAccountCounterparty($bankAccount, $fallbackName);
	$dolibarr['fk_societe_rib'] = !empty($bankAccount['rowid']) ? (int) $bankAccount['rowid'] : 0;
	$dolibarr['rib_label'] = isset($bankAccount['label']) ? (string) $bankAccount['label'] : '';
	$dolibarr['rib_owner'] = isset($bankAccount['proprio']) ? (string) $bankAccount['proprio'] : '';
	$dolibarr['rib_bic'] = isset($bankAccount['bic']) ? (string) $bankAccount['bic'] : '';
	$dolibarr['rib_iban'] = isset($bankAccount['iban_prefix']) ? (string) $bankAccount['iban_prefix'] : '';
	$dolibarr['rib_number'] = isset($bankAccount['number']) ? (string) $bankAccount['number'] : '';

	$payload['dolibarr'] = $dolibarr;
	$payload['beneficiary_bank_account'] = $bankAccount;
	$payload['payto'] = $counterparty['payto'];
	$payload['validation'] = libeufinconnectorValidatePreparedOutgoingCounterparty($dolibarr, $counterparty);

	$data['raw_payload'] = $payload;
	$data['counterparty_iban'] = $counterparty['iban'];
	$data['counterparty_bic'] = $counterparty['bic'];
	$data['counterparty_name'] = $counterparty['name'];

	return $data;
}

/**
 * Preserve a manually selected beneficiary bank account when collecting again.
 *
 * @param DoliDB              $db Database handler.
 * @param array<string,mixed> $data New staging data.
 * @return array<string,mixed>
 */
function libeufinconnectorMergeOutgoingBeneficiarySelectionWithExisting($db, array $data)
{
	$transaction = new LibeufinTransaction($db);
	$entity = isset($data['entity']) ? (int) $data['entity'] : (int) getEntity($transaction->element, 1);
	$fetch = $transaction->fetchByExternalIdentifiers($data, $entity);
	if ($fetch <= 0) {
		return $data;
	}

	$existingPayload = libeufinconnectorGetTransactionPayloadArray($transaction);
	if (empty($existingPayload['beneficiary_bank_account']) || !is_array($existingPayload['beneficiary_bank_account'])) {
		return $data;
	}

	return libeufinconnectorApplyOutgoingBeneficiaryBankAccountToData($data, $existingPayload['beneficiary_bank_account']);
}

/**
 * Return the third-party id from an outgoing staged payload.
 *
 * @param array<string,mixed> $payload Staged payload.
 * @return int
 */
function libeufinconnectorGetOutgoingPayloadSocid(array $payload)
{
	if (!isset($payload['dolibarr']) || !is_array($payload['dolibarr'])) {
		return 0;
	}

	foreach (array('fk_soc', 'supplier_socid', 'customer_socid') as $field) {
		if (!empty($payload['dolibarr'][$field])) {
			return (int) $payload['dolibarr'][$field];
		}
	}

	return 0;
}

/**
 * Read Dolibarr-prepared outgoing transfer rows.
 *
 * @param DoliDB $db Database handler.
 * @param int    $limit Optional limit.
 * @return array{ok:bool,error:string,rows:array<int,array<string,mixed>>}
 */
function libeufinconnectorReadPreparedOutgoingTransactions($db, $limit = 0)
{
	global $conf;

	$rows = array();
	$sql = "SELECT pd.rowid AS prelevement_demande_id, pd.entity, pd.amount, pd.date_demande,";
	$sql .= " pd.fk_prelevement_bons AS prelevement_bons_id, pd.fk_facture, pd.fk_facture_fourn, pd.fk_salary, pd.sourcetype,";
	$sql .= " COALESCE(ff.fk_soc, f.fk_soc) AS fk_soc,";
	$sql .= " pd.fk_societe_rib, pd.code_banque AS request_code_banque, pd.code_guichet AS request_code_guichet,";
	$sql .= " pd.number AS request_number, pd.cle_rib AS request_cle_rib, pd.type AS request_type,";
	$sql .= " pb.ref AS prelevement_bons_ref, pb.type AS prelevement_bons_type, pb.statut AS prelevement_bons_status,";
	$sql .= " pb.note AS prelevement_bons_note, pb.fk_bank_account,";
	$sql .= " sr.label AS rib_label, sr.proprio AS rib_owner, sr.bic AS rib_bic, sr.iban_prefix AS rib_iban, sr.number AS rib_number,";
	$sql .= " ff.ref AS supplier_invoice_ref, f.ref AS customer_invoice_ref,";
	$sql .= " socf.nom AS supplier_name, socc.nom AS customer_name";
	$sql .= " FROM ".MAIN_DB_PREFIX."prelevement_demande AS pd";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."prelevement_bons AS pb ON pb.rowid = pd.fk_prelevement_bons";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe_rib AS sr ON sr.rowid = pd.fk_societe_rib";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."facture_fourn AS ff ON ff.rowid = pd.fk_facture_fourn";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe AS socf ON socf.rowid = ff.fk_soc";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."facture AS f ON f.rowid = pd.fk_facture";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe AS socc ON socc.rowid = f.fk_soc";
	$sql .= " WHERE pd.entity = ".((int) $conf->entity);
	$sql .= " AND pd.fk_prelevement_bons IS NOT NULL";
	$sql .= " AND pb.entity = ".((int) $conf->entity);
	$sql .= " AND pb.type IN ('bank-transfer', 'credit-transfer')";
	$sql .= " AND pb.statut = 0";
	$sql .= " ORDER BY pd.date_demande DESC, pd.rowid DESC";
	if ($limit > 0) {
		$sql .= $db->plimit($limit, 0);
	}

	$resql = $db->query($sql);
	if (!$resql) {
		return array('ok' => false, 'error' => $db->lasterror(), 'rows' => array());
	}

	while ($obj = $db->fetch_object($resql)) {
		$rows[] = (array) $obj;
	}
	$db->free($resql);

	return array('ok' => true, 'error' => '', 'rows' => $rows);
}

/**
 * Read Dolibarr supplier payments that represent outgoing credit transfers.
 *
 * @param DoliDB $db Database handler.
 * @param int    $limit Optional limit.
 * @return array{ok:bool,error:string,rows:array<int,array<string,mixed>>}
 */
function libeufinconnectorReadPreparedOutgoingSupplierPayments($db, $limit = 0)
{
	global $conf;

	$rows = array();
	$sql = "SELECT p.rowid AS paiementfourn_id, p.entity, p.ref AS paiementfourn_ref, p.datep, p.amount,";
	$sql .= " p.multicurrency_amount, p.fk_bank, p.note AS paiementfourn_note, p.fk_paiement, p.num_paiement, p.statut AS paiementfourn_status,";
	$sql .= " pf.fk_facturefourn AS fk_facture_fourn, ff.ref AS supplier_invoice_ref, ff.fk_soc, ff.fk_mode_reglement,";
	$sql .= " s.nom AS supplier_name, cp.code AS payment_code, cp.libelle AS payment_label,";
	$sql .= " sr.rowid AS fk_societe_rib, sr.label AS rib_label, sr.proprio AS rib_owner, sr.bic AS rib_bic, sr.iban_prefix AS rib_iban, sr.number AS rib_number";
	$sql .= " FROM ".MAIN_DB_PREFIX."paiementfourn AS p";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."paiementfourn_facturefourn AS pf ON pf.fk_paiementfourn = p.rowid";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."facture_fourn AS ff ON ff.rowid = pf.fk_facturefourn";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."societe AS s ON s.rowid = ff.fk_soc";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."c_paiement AS cp ON cp.id = p.fk_paiement";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe_rib AS sr ON sr.fk_soc = ff.fk_soc AND sr.default_rib = 1 AND sr.type = 'ban'";
	$sql .= " WHERE p.entity = ".((int) $conf->entity);
	$sql .= " AND ff.entity = ".((int) $conf->entity);
	$sql .= " AND cp.code = 'VIR'";
	$sql .= " ORDER BY p.datep DESC, p.rowid DESC";
	if ($limit > 0) {
		$sql .= $db->plimit($limit, 0);
	}

	$resql = $db->query($sql);
	if (!$resql) {
		return array('ok' => false, 'error' => $db->lasterror(), 'rows' => array());
	}

	while ($obj = $db->fetch_object($resql)) {
		$rows[] = (array) $obj;
	}
	$db->free($resql);

	return array('ok' => true, 'error' => '', 'rows' => $rows);
}

/**
 * Convert a prepared Dolibarr outgoing row into staging data.
 *
 * @param array<string,mixed> $row Prepared payment row.
 * @return array<string,mixed>
 */
function libeufinconnectorBuildPreparedOutgoingStageData(array $row)
{
	if (!empty($row['rib_iban'])) {
		$row['rib_iban'] = libeufinconnectorDecryptBankField($row['rib_iban']);
	}
	$counterparty = libeufinconnectorResolvePreparedOutgoingCounterparty($row);
	$validation = libeufinconnectorValidatePreparedOutgoingCounterparty($row, $counterparty);
	$subject = libeufinconnectorBuildPreparedOutgoingSubject($row);
	$endToEndId = libeufinconnectorBuildPreparedOutgoingEndToEndId($row);
	$payload = array(
		'source' => 'dolibarr_prepared_outgoing',
		'subject' => $subject,
		'end_to_end_id' => $endToEndId,
		'payto' => $counterparty['payto'],
		'validation' => $validation,
		'dolibarr' => $row,
	);

	return array(
		'external_transaction_id' => $endToEndId,
		'external_message_id' => 'prelevement-demande:'.((int) $row['prelevement_demande_id']),
		'external_order_id' => (
			!empty($row['prelevement_bons_id'])
			? 'prelevement-bons:'.((int) $row['prelevement_bons_id']).':demande:'.((int) $row['prelevement_demande_id'])
			: ''
		),
		'direction' => LibeufinTransaction::DIRECTION_OUTGOING,
		'transaction_status' => LibeufinTransaction::STATUS_NEW,
		'amount' => isset($row['amount']) ? price2num((string) $row['amount'], 'MU') : 0,
		'currency' => libeufinconnectorGetImportedTransactionCurrency(),
		'transaction_date' => !empty($row['date_demande']) ? (string) $row['date_demande'] : null,
		'counterparty_iban' => $counterparty['iban'],
		'counterparty_bic' => $counterparty['bic'],
		'counterparty_name' => $counterparty['name'],
		'raw_payload' => $payload,
		'fk_facture' => !empty($row['fk_facture']) ? (int) $row['fk_facture'] : null,
		'fk_facture_fourn' => !empty($row['fk_facture_fourn']) ? (int) $row['fk_facture_fourn'] : null,
		'fk_prelevement_bons' => !empty($row['prelevement_bons_id']) ? (int) $row['prelevement_bons_id'] : null,
	);
}

/**
 * Build a deterministic end-to-end id for Dolibarr supplier payment rows.
 *
 * @param array<string,mixed> $row Supplier payment row.
 * @return string
 */
function libeufinconnectorBuildSupplierPaymentOutgoingEndToEndId(array $row)
{
	$entity = !empty($row['entity']) ? (int) $row['entity'] : 1;
	$paymentId = !empty($row['paiementfourn_id']) ? (int) $row['paiementfourn_id'] : 0;

	return 'LFC-SPAY-E'.$entity.'-P'.$paymentId;
}

/**
 * Build a subject for Dolibarr supplier payment outgoing rows.
 *
 * @param array<string,mixed> $row Supplier payment row.
 * @return string
 */
function libeufinconnectorBuildSupplierPaymentOutgoingSubject(array $row)
{
	if (!empty($row['supplier_invoice_ref'])) {
		return 'Payment '.$row['supplier_invoice_ref'];
	}
	if (!empty($row['paiementfourn_ref'])) {
		return 'Supplier payment '.$row['paiementfourn_ref'];
	}

	return 'Supplier payment #'.((int) (!empty($row['paiementfourn_id']) ? $row['paiementfourn_id'] : 0));
}

/**
 * Read Dolibarr customer refund payments linked to credit notes.
 *
 * @param DoliDB $db Database handler.
 * @param int    $limit Optional limit.
 * @return array{ok:bool,error:string,rows:array<int,array<string,mixed>>}
 */
function libeufinconnectorReadPreparedOutgoingCustomerRefundPayments($db, $limit = 0)
{
	global $conf;

	$rows = array();
	$sql = "SELECT p.rowid AS paiement_id, p.entity, p.ref AS paiement_ref, p.datep, p.amount,";
	$sql .= " p.fk_bank, p.note, p.fk_paiement, p.num_paiement,";
	$sql .= " pf.fk_facture, f.ref AS customer_credit_note_ref, f.fk_soc, f.fk_mode_reglement, f.fk_account, f.fk_facture_source,";
	$sql .= " fs.ref AS source_invoice_ref, s.nom AS customer_name, cp.code AS payment_code, cp.libelle AS payment_label,";
	$sql .= " sr.rowid AS fk_societe_rib, sr.label AS rib_label, sr.proprio AS rib_owner, sr.bic AS rib_bic, sr.iban_prefix AS rib_iban, sr.number AS rib_number";
	$sql .= " FROM ".MAIN_DB_PREFIX."paiement AS p";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."paiement_facture AS pf ON pf.fk_paiement = p.rowid";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."facture AS f ON f.rowid = pf.fk_facture";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."societe AS s ON s.rowid = f.fk_soc";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."facture AS fs ON fs.rowid = f.fk_facture_source";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."c_paiement AS cp ON cp.id = p.fk_paiement";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe_rib AS sr ON sr.fk_soc = f.fk_soc AND sr.default_rib = 1 AND sr.type = 'ban'";
	$sql .= " WHERE p.entity = ".((int) $conf->entity);
	$sql .= " AND f.entity = ".((int) $conf->entity);
	$sql .= " AND f.type = 2";
	$sql .= " AND p.amount < 0";
	$sql .= " AND cp.code = 'VIR'";
	$sql .= " ORDER BY p.datep DESC, p.rowid DESC";
	if ($limit > 0) {
		$sql .= $db->plimit($limit, 0);
	}

	$resql = $db->query($sql);
	if (!$resql) {
		return array('ok' => false, 'error' => $db->lasterror(), 'rows' => array());
	}

	while ($obj = $db->fetch_object($resql)) {
		$rows[] = (array) $obj;
	}
	$db->free($resql);

	return array('ok' => true, 'error' => '', 'rows' => $rows);
}

/**
 * Build a deterministic end-to-end id for Dolibarr customer refund payment rows.
 *
 * @param array<string,mixed> $row Customer refund payment row.
 * @return string
 */
function libeufinconnectorBuildCustomerRefundOutgoingEndToEndId(array $row)
{
	$entity = !empty($row['entity']) ? (int) $row['entity'] : 1;
	$paymentId = !empty($row['paiement_id']) ? (int) $row['paiement_id'] : 0;

	return 'LFC-CPAY-E'.$entity.'-P'.$paymentId;
}

/**
 * Build a subject for Dolibarr customer refund outgoing rows.
 *
 * @param array<string,mixed> $row Customer refund payment row.
 * @return string
 */
function libeufinconnectorBuildCustomerRefundOutgoingSubject(array $row)
{
	if (!empty($row['customer_credit_note_ref'])) {
		return 'Refund '.$row['customer_credit_note_ref'];
	}
	if (!empty($row['paiement_ref'])) {
		return 'Customer refund '.$row['paiement_ref'];
	}

	return 'Customer refund #'.((int) (!empty($row['paiement_id']) ? $row['paiement_id'] : 0));
}

/**
 * Convert a Dolibarr customer refund payment row into staging data.
 *
 * @param array<string,mixed> $row Customer refund payment row.
 * @return array<string,mixed>
 */
function libeufinconnectorBuildCustomerRefundOutgoingStageData(array $row)
{
	if (!empty($row['rib_iban'])) {
		$row['rib_iban'] = libeufinconnectorDecryptBankField($row['rib_iban']);
	}
	$counterparty = libeufinconnectorResolvePreparedOutgoingCounterparty($row);
	$validation = libeufinconnectorValidatePreparedOutgoingCounterparty($row, $counterparty);
	$subject = libeufinconnectorBuildCustomerRefundOutgoingSubject($row);
	$endToEndId = libeufinconnectorBuildCustomerRefundOutgoingEndToEndId($row);
	$payload = array(
		'source' => 'dolibarr_customer_refund',
		'subject' => $subject,
		'end_to_end_id' => $endToEndId,
		'payto' => $counterparty['payto'],
		'validation' => $validation,
		'dolibarr' => $row,
	);

	return array(
		'external_transaction_id' => $endToEndId,
		'external_message_id' => 'paiement:'.((int) $row['paiement_id']),
		'external_order_id' => !empty($row['customer_credit_note_ref']) ? 'credit-note:'.$row['customer_credit_note_ref'] : '',
		'direction' => LibeufinTransaction::DIRECTION_OUTGOING,
		'transaction_status' => (!empty($row['fk_bank']) ? LibeufinTransaction::STATUS_BOOKED : LibeufinTransaction::STATUS_NEW),
		'amount' => isset($row['amount']) ? price2num(abs((float) $row['amount']), 'MU') : 0,
		'currency' => libeufinconnectorGetImportedTransactionCurrency(),
		'transaction_date' => !empty($row['datep']) ? (string) $row['datep'] : null,
		'counterparty_iban' => $counterparty['iban'],
		'counterparty_bic' => $counterparty['bic'],
		'counterparty_name' => $counterparty['name'],
		'raw_payload' => $payload,
		'fk_paiement' => !empty($row['paiement_id']) ? (int) $row['paiement_id'] : null,
		'fk_facture' => !empty($row['fk_facture']) ? (int) $row['fk_facture'] : null,
		'fk_bank' => !empty($row['fk_bank']) ? (int) $row['fk_bank'] : null,
	);
}

/**
 * Stage current Dolibarr customer refund payment rows into the staging table.
 *
 * @param DoliDB      $db Database handler.
 * @param object|null $user Acting user.
 * @param int         $limit Optional limit.
 * @return array{ok:bool,error:string,total:int,created:int,updated:int,errors:int,rows:array<int,array<string,mixed>>}
 */
function libeufinconnectorStagePreparedOutgoingCustomerRefundPayments($db, $user = null, $limit = 0)
{
	$result = array(
		'ok' => false,
		'error' => '',
		'total' => 0,
		'created' => 0,
		'updated' => 0,
		'errors' => 0,
		'rows' => array(),
	);

	if (!libeufinconnectorHasTransactionTable($db)) {
		$result['error'] = 'missing_staging_table';
		return $result;
	}

	$payments = libeufinconnectorReadPreparedOutgoingCustomerRefundPayments($db, $limit);
	if (empty($payments['ok'])) {
		$result['error'] = $payments['error'];
		return $result;
	}

	foreach ($payments['rows'] as $row) {
		$data = libeufinconnectorBuildCustomerRefundOutgoingStageData($row);
		$data = libeufinconnectorMergeOutgoingBeneficiarySelectionWithExisting($db, $data);
		$upsert = libeufinconnectorStageTransaction($db, $data, $user, 1);
		$result['rows'][] = $upsert;
		$result['total']++;

		if (empty($upsert['ok'])) {
			$result['errors']++;
			continue;
		}

		if ($upsert['action'] === 'created') {
			$result['created']++;
		} elseif ($upsert['action'] === 'updated') {
			$result['updated']++;
		}
		if (!empty($upsert['rowid'])) {
			$transaction = new LibeufinTransaction($db);
			if ($transaction->fetch((int) $upsert['rowid']) > 0) {
				libeufinconnectorEnsureTransactionObjectLinks($db, $transaction, $user);
			}
		}
	}

	$result['ok'] = ($result['errors'] === 0);

	return $result;
}

/**
 * Convert a Dolibarr supplier payment row into staging data.
 *
 * @param array<string,mixed> $row Supplier payment row.
 * @return array<string,mixed>
 */
function libeufinconnectorBuildSupplierPaymentOutgoingStageData(array $row)
{
	if (!empty($row['rib_iban'])) {
		$row['rib_iban'] = libeufinconnectorDecryptBankField($row['rib_iban']);
	}
	$counterparty = libeufinconnectorResolvePreparedOutgoingCounterparty($row);
	$validation = libeufinconnectorValidatePreparedOutgoingCounterparty($row, $counterparty);
	$subject = libeufinconnectorBuildSupplierPaymentOutgoingSubject($row);
	$endToEndId = libeufinconnectorBuildSupplierPaymentOutgoingEndToEndId($row);
	$payload = array(
		'source' => 'dolibarr_supplier_payment',
		'subject' => $subject,
		'end_to_end_id' => $endToEndId,
		'payto' => $counterparty['payto'],
		'validation' => $validation,
		'dolibarr' => $row,
	);

	return array(
		'external_transaction_id' => $endToEndId,
		'external_message_id' => 'paiementfourn:'.((int) $row['paiementfourn_id']),
		'external_order_id' => !empty($row['supplier_invoice_ref']) ? 'supplier-invoice:'.$row['supplier_invoice_ref'] : '',
		'direction' => LibeufinTransaction::DIRECTION_OUTGOING,
		'transaction_status' => LibeufinTransaction::STATUS_NEW,
		'amount' => isset($row['amount']) ? price2num((string) $row['amount'], 'MU') : 0,
		'currency' => libeufinconnectorGetImportedTransactionCurrency(),
		'transaction_date' => !empty($row['datep']) ? (string) $row['datep'] : null,
		'counterparty_iban' => $counterparty['iban'],
		'counterparty_bic' => $counterparty['bic'],
		'counterparty_name' => $counterparty['name'],
		'raw_payload' => $payload,
		'fk_paiementfourn' => !empty($row['paiementfourn_id']) ? (int) $row['paiementfourn_id'] : null,
		'fk_facture_fourn' => !empty($row['fk_facture_fourn']) ? (int) $row['fk_facture_fourn'] : null,
	);
}

/**
 * Stage current Dolibarr supplier payment rows into the staging table.
 *
 * @param DoliDB      $db Database handler.
 * @param object|null $user Acting user.
 * @param int         $limit Optional limit.
 * @return array{ok:bool,error:string,total:int,created:int,updated:int,errors:int,rows:array<int,array<string,mixed>>}
 */
function libeufinconnectorStagePreparedOutgoingSupplierPayments($db, $user = null, $limit = 0)
{
	$result = array(
		'ok' => false,
		'error' => '',
		'total' => 0,
		'created' => 0,
		'updated' => 0,
		'errors' => 0,
		'rows' => array(),
	);

	if (!libeufinconnectorHasTransactionTable($db)) {
		$result['error'] = 'missing_staging_table';
		return $result;
	}

	$payments = libeufinconnectorReadPreparedOutgoingSupplierPayments($db, $limit);
	if (empty($payments['ok'])) {
		$result['error'] = $payments['error'];
		return $result;
	}

	foreach ($payments['rows'] as $row) {
		$data = libeufinconnectorBuildSupplierPaymentOutgoingStageData($row);
		$data = libeufinconnectorMergeOutgoingBeneficiarySelectionWithExisting($db, $data);
		$upsert = libeufinconnectorStageTransaction($db, $data, $user, 1);
		$result['rows'][] = $upsert;
		$result['total']++;

		if (empty($upsert['ok'])) {
			$result['errors']++;
			continue;
		}

		if ($upsert['action'] === 'created') {
			$result['created']++;
		} elseif ($upsert['action'] === 'updated') {
			$result['updated']++;
		}
		if (!empty($upsert['rowid'])) {
			$transaction = new LibeufinTransaction($db);
			if ($transaction->fetch((int) $upsert['rowid']) > 0) {
				libeufinconnectorEnsureTransactionObjectLinks($db, $transaction, $user);
			}
		}
	}

	$result['ok'] = ($result['errors'] === 0);

	return $result;
}

/**
 * Merge an updated staging payload with any existing payload for the same transaction.
 *
 * @param DoliDB              $db Database handler.
 * @param array<string,mixed> $data New staging data.
 * @return array<string,mixed>
 */
function libeufinconnectorMergeStagePayloadWithExisting($db, array $data)
{
	$transaction = new LibeufinTransaction($db);
	$entity = isset($data['entity']) ? (int) $data['entity'] : (int) getEntity($transaction->element, 1);
	$fetch = $transaction->fetchByExternalIdentifiers($data, $entity);
	if ($fetch <= 0) {
		return $data;
	}

	$existingPayload = libeufinconnectorGetTransactionPayloadArray($transaction);
	$newPayload = isset($data['raw_payload']) && is_array($data['raw_payload']) ? $data['raw_payload'] : array();
	if (empty($existingPayload)) {
		return $data;
	}

	$data['raw_payload'] = array_replace_recursive($existingPayload, $newPayload);

	return $data;
}

/**
 * Stage current Dolibarr-prepared outgoing rows into the staging table.
 *
 * @param DoliDB      $db Database handler.
 * @param object|null $user Acting user.
 * @param int         $limit Optional limit.
 * @return array{ok:bool,error:string,total:int,created:int,updated:int,errors:int,rows:array<int,array<string,mixed>>}
 */
function libeufinconnectorStagePreparedOutgoingTransactions($db, $user = null, $limit = 0)
{
	$result = array(
		'ok' => false,
		'error' => '',
		'total' => 0,
		'created' => 0,
		'updated' => 0,
		'errors' => 0,
		'rows' => array(),
	);

	if (!libeufinconnectorHasTransactionTable($db)) {
		$result['error'] = 'missing_staging_table';
		return $result;
	}

	$prepared = libeufinconnectorReadPreparedOutgoingTransactions($db, $limit);
	if (empty($prepared['ok'])) {
		$result['error'] = $prepared['error'];
		return $result;
	}

	foreach ($prepared['rows'] as $row) {
		$data = libeufinconnectorBuildPreparedOutgoingStageData($row);
		$data = libeufinconnectorMergeOutgoingBeneficiarySelectionWithExisting($db, $data);
		$upsert = libeufinconnectorStageTransaction($db, $data, $user, 1);
		$result['rows'][] = $upsert;
		$result['total']++;

		if (empty($upsert['ok'])) {
			$result['errors']++;
			continue;
		}

		if ($upsert['action'] === 'created') {
			$result['created']++;
		} elseif ($upsert['action'] === 'updated') {
			$result['updated']++;
		}
		if (!empty($upsert['rowid'])) {
			$transaction = new LibeufinTransaction($db);
			if ($transaction->fetch((int) $upsert['rowid']) > 0) {
				libeufinconnectorEnsureTransactionObjectLinks($db, $transaction, $user);
			}
		}
	}

	$result['ok'] = ($result['errors'] === 0);

	return $result;
}

/**
 * Read current Nexus incoming transactions as raw rows.
 *
 * @param int $limit Optional limit.
 * @return array{ok:bool,error:string,rows:array<int,array<string,mixed>>,output:string}
 */
function libeufinconnectorReadNexusIncomingTransactions($limit = 0)
{
	$sql = "SELECT json_build_object("
		."'incoming_transaction_id', i.incoming_transaction_id,"
		."'amount_val', (i.amount).val,"
		."'amount_frac', (i.amount).frac,"
		."'subject', i.subject,"
		."'execution_time', i.execution_time,"
		."'debit_payto', i.debit_payto,"
		."'credit_fee_val', (i.credit_fee).val,"
		."'credit_fee_frac', (i.credit_fee).frac,"
		."'uetr', i.uetr::text,"
		."'tx_id', i.tx_id,"
		."'acct_svcr_ref', i.acct_svcr_ref,"
		."'taler_type', ti.type::text,"
		."'taler_metadata_base64', CASE WHEN ti.metadata IS NULL THEN NULL ELSE encode(ti.metadata, 'base64') END,"
		."'authorization_pub_base64', CASE WHEN ti.authorization_pub IS NULL THEN NULL ELSE encode(ti.authorization_pub, 'base64') END,"
		."'authorization_sig_base64', CASE WHEN ti.authorization_sig IS NULL THEN NULL ELSE encode(ti.authorization_sig, 'base64') END"
		.")::text"
		." FROM libeufin_nexus.incoming_transactions AS i"
		." LEFT JOIN libeufin_nexus.talerable_incoming_transactions AS ti ON ti.incoming_transaction_id = i.incoming_transaction_id"
		." ORDER BY i.incoming_transaction_id DESC";

	if ($limit > 0) {
		$sql .= " LIMIT ".((int) $limit);
	}

	return libeufinconnectorRunNexusJsonQuery($sql);
}

/**
 * Read current Nexus initiated outgoing transactions as raw rows.
 *
 * @param int $limit Optional limit.
 * @return array{ok:bool,error:string,rows:array<int,array<string,mixed>>,output:string}
 */
function libeufinconnectorReadNexusInitiatedOutgoingTransactions($limit = 0)
{
	$sql = "SELECT json_build_object("
		."'initiated_outgoing_transaction_id', i.initiated_outgoing_transaction_id,"
		."'amount_val', (i.amount).val,"
		."'amount_frac', (i.amount).frac,"
		."'subject', i.subject,"
		."'initiation_time', i.initiation_time,"
		."'credit_payto', i.credit_payto,"
		."'outgoing_transaction_id', i.outgoing_transaction_id,"
		."'status', i.status::text,"
		."'end_to_end_id', i.end_to_end_id,"
		."'status_msg', i.status_msg,"
		."'initiated_outgoing_batch_id', i.initiated_outgoing_batch_id,"
		."'awaiting_ack', i.awaiting_ack"
		.")::text"
		." FROM libeufin_nexus.initiated_outgoing_transactions AS i"
		." ORDER BY i.initiation_time DESC, i.initiated_outgoing_transaction_id DESC";

	if ($limit > 0) {
		$sql .= " LIMIT ".((int) $limit);
	}

	return libeufinconnectorRunNexusJsonQuery($sql);
}

/**
 * Map initiated Nexus outgoing status to staging status.
 *
 * @param string $status Nexus status.
 * @return string
 */
function libeufinconnectorMapInitiatedOutgoingStatus($status)
{
	$status = strtolower(trim((string) $status));

	if ($status === 'success') {
		return LibeufinTransaction::STATUS_SUBMITTED;
	}
	if ($status === 'permanent_failure' || $status === 'transient_failure' || $status === 'failed') {
		return LibeufinTransaction::STATUS_FAILED;
	}

	return LibeufinTransaction::STATUS_NEW;
}

/**
 * Convert a raw initiated outgoing Nexus row into staging data.
 *
 * @param array<string,mixed> $row Raw Nexus row.
 * @return array<string,mixed>
 */
function libeufinconnectorBuildInitiatedOutgoingStageData(array $row)
{
	$payto = libeufinconnectorParsePaytoUri(isset($row['credit_payto']) ? (string) $row['credit_payto'] : '');
	$payload = array(
		'source' => 'libeufin_nexus.initiated_outgoing_transactions',
		'direction' => 'outgoing',
		'nexus' => $row,
	);

	return array(
		'external_transaction_id' => !empty($row['end_to_end_id']) ? (string) $row['end_to_end_id'] : 'initiated:'.((int) $row['initiated_outgoing_transaction_id']),
		'external_message_id' => 'initiated:'.((int) $row['initiated_outgoing_transaction_id']),
		'external_order_id' => (
			!empty($row['initiated_outgoing_batch_id'])
			? 'batch:'.$row['initiated_outgoing_batch_id'].':initiated:'.((int) $row['initiated_outgoing_transaction_id'])
			: ''
		),
		'direction' => LibeufinTransaction::DIRECTION_OUTGOING,
		'transaction_status' => libeufinconnectorMapInitiatedOutgoingStatus(isset($row['status']) ? (string) $row['status'] : ''),
		'amount' => libeufinconnectorNormalizeNexusAmount(
			isset($row['amount_val']) ? $row['amount_val'] : 0,
			isset($row['amount_frac']) ? $row['amount_frac'] : 0
		),
		'currency' => libeufinconnectorGetImportedTransactionCurrency(),
		'transaction_date' => libeufinconnectorNormalizeNexusEpoch(isset($row['initiation_time']) ? $row['initiation_time'] : null),
		'counterparty_iban' => $payto['iban'],
		'counterparty_bic' => $payto['bic'],
		'counterparty_name' => $payto['name'],
		'raw_payload' => $payload,
	);
}

/**
 * Read current Nexus booked outgoing transactions as raw rows.
 *
 * @param int $limit Optional limit.
 * @return array{ok:bool,error:string,rows:array<int,array<string,mixed>>,output:string}
 */
function libeufinconnectorReadNexusOutgoingTransactions($limit = 0)
{
	$sql = "SELECT json_build_object("
		."'outgoing_transaction_id', o.outgoing_transaction_id,"
		."'amount_val', (o.amount).val,"
		."'amount_frac', (o.amount).frac,"
		."'subject', o.subject,"
		."'execution_time', o.execution_time,"
		."'credit_payto', o.credit_payto,"
		."'end_to_end_id', o.end_to_end_id,"
		."'acct_svcr_ref', o.acct_svcr_ref,"
		."'debit_fee_val', (o.debit_fee).val,"
		."'debit_fee_frac', (o.debit_fee).frac"
		.")::text"
		." FROM libeufin_nexus.outgoing_transactions AS o"
		." ORDER BY o.execution_time DESC, o.outgoing_transaction_id DESC";

	if ($limit > 0) {
		$sql .= " LIMIT ".((int) $limit);
	}

	return libeufinconnectorRunNexusJsonQuery($sql);
}

/**
 * Convert a raw booked Nexus outgoing row into staging data.
 *
 * @param array<string,mixed> $row Raw Nexus row.
 * @return array<string,mixed>
 */
function libeufinconnectorBuildOutgoingStageData(array $row)
{
	$payto = libeufinconnectorParsePaytoUri(isset($row['credit_payto']) ? (string) $row['credit_payto'] : '');
	$payload = array(
		'source' => 'libeufin_nexus.outgoing_transactions',
		'direction' => 'outgoing',
		'nexus' => $row,
	);

	return array(
		'external_transaction_id' => !empty($row['end_to_end_id']) ? (string) $row['end_to_end_id'] : 'outgoing:'.((int) $row['outgoing_transaction_id']),
		'external_message_id' => 'outgoing:'.((int) $row['outgoing_transaction_id']),
		'external_order_id' => !empty($row['acct_svcr_ref']) ? (string) $row['acct_svcr_ref'] : '',
		'direction' => LibeufinTransaction::DIRECTION_OUTGOING,
		'transaction_status' => LibeufinTransaction::STATUS_BOOKED,
		'amount' => libeufinconnectorNormalizeNexusAmount(
			isset($row['amount_val']) ? $row['amount_val'] : 0,
			isset($row['amount_frac']) ? $row['amount_frac'] : 0
		),
		'currency' => libeufinconnectorGetImportedTransactionCurrency(),
		'transaction_date' => libeufinconnectorNormalizeNexusEpoch(isset($row['execution_time']) ? $row['execution_time'] : null),
		'counterparty_iban' => $payto['iban'],
		'counterparty_bic' => $payto['bic'],
		'counterparty_name' => $payto['name'],
		'raw_payload' => $payload,
	);
}

/**
 * Return outgoing validation metadata from a staged transaction payload.
 *
 * @param array<string,mixed> $payload Payload array.
 * @return array{ready:bool,issues:array<int,string>}
 */
function libeufinconnectorGetOutgoingValidationFromPayloadArray(array $payload)
{
	if (isset($payload['validation']) && is_array($payload['validation'])) {
		$issues = array();
		if (!empty($payload['validation']['issues']) && is_array($payload['validation']['issues'])) {
			foreach ($payload['validation']['issues'] as $issue) {
				$issue = trim((string) $issue);
				if ($issue !== '') {
					$issues[] = $issue;
				}
			}
		}

		return array(
			'ready' => empty($payload['validation']['ready']) ? empty($issues) : true,
			'issues' => array_values(array_unique($issues)),
		);
	}

	return array('ready' => true, 'issues' => array());
}

/**
 * Return outgoing validation metadata for a staged transaction.
 *
 * @param LibeufinTransaction $transaction Staged transaction.
 * @return array{ready:bool,issues:array<int,string>}
 */
function libeufinconnectorGetOutgoingTransactionValidation(LibeufinTransaction $transaction)
{
	$payload = libeufinconnectorGetTransactionPayloadArray($transaction);

	return libeufinconnectorGetOutgoingValidationFromPayloadArray($payload);
}

/**
 * Tell whether a staged outgoing row is ready to be sent.
 *
 * @param LibeufinTransaction $transaction Staged transaction.
 * @return bool
 */
function libeufinconnectorIsOutgoingTransactionReadyForSend(LibeufinTransaction $transaction)
{
	$payload = libeufinconnectorGetTransactionPayloadArray($transaction);
	$validation = libeufinconnectorGetOutgoingValidationFromPayloadArray($payload);

	return (
		libeufinconnectorIsOutgoingTransactionOpen($transaction)
		&& !empty($payload['source'])
		&& in_array($payload['source'], array('dolibarr_prepared_outgoing', 'dolibarr_supplier_payment', 'dolibarr_customer_refund'), true)
		&& !empty($validation['ready'])
	);
}

/**
 * Tell whether the beneficiary details of an outgoing transaction can still be edited.
 *
 * @param LibeufinTransaction $transaction Staged transaction.
 * @return bool
 */
function libeufinconnectorCanEditOutgoingTransactionBeneficiary(LibeufinTransaction $transaction)
{
	if ($transaction->direction !== LibeufinTransaction::DIRECTION_OUTGOING) {
		return false;
	}

	$payload = libeufinconnectorGetTransactionPayloadArray($transaction);
	$source = !empty($payload['source']) ? (string) $payload['source'] : '';
	if (!in_array($source, array('dolibarr_prepared_outgoing', 'dolibarr_supplier_payment', 'dolibarr_customer_refund'), true)) {
		return false;
	}
	if (!libeufinconnectorIsOutgoingTransactionOpen($transaction)) {
		return false;
	}

	$nexus = (isset($payload['nexus']) && is_array($payload['nexus'])) ? $payload['nexus'] : array();
	if (!empty($nexus['initiated_outgoing_transaction_id']) || !empty($nexus['outgoing_transaction_id'])) {
		return false;
	}

	return true;
}

/**
 * Stage current Nexus outgoing rows into the Dolibarr staging table.
 *
 * @param DoliDB      $db Database handler.
 * @param object|null $user Acting user.
 * @param int         $limit Optional limit.
 * @return array{ok:bool,error:string,total:int,created:int,updated:int,errors:int,rows:array<int,array<string,mixed>>,initiated_total:int,booked_total:int}
 */
function libeufinconnectorStageNexusOutgoingTransactions($db, $user = null, $limit = 0)
{
	$result = array(
		'ok' => false,
		'error' => '',
		'total' => 0,
		'created' => 0,
		'updated' => 0,
		'errors' => 0,
		'rows' => array(),
		'initiated_total' => 0,
		'booked_total' => 0,
	);

	if (!libeufinconnectorHasTransactionTable($db)) {
		$result['error'] = 'missing_staging_table';
		return $result;
	}

	$initiated = libeufinconnectorReadNexusInitiatedOutgoingTransactions($limit);
	if (empty($initiated['ok'])) {
		$result['error'] = libeufinconnectorCompactCommandMessage($initiated['error'].($initiated['output'] !== '' ? "\n".$initiated['output'] : ''));
		return $result;
	}

	foreach ($initiated['rows'] as $row) {
		$data = libeufinconnectorBuildInitiatedOutgoingStageData($row);
		$data = libeufinconnectorMergeStagePayloadWithExisting($db, $data);
		$upsert = libeufinconnectorStageTransaction($db, $data, $user, 1);
		$result['rows'][] = $upsert;
		$result['total']++;
		$result['initiated_total']++;

		if (empty($upsert['ok'])) {
			$result['errors']++;
			continue;
		}

		if ($upsert['action'] === 'created') {
			$result['created']++;
		} elseif ($upsert['action'] === 'updated') {
			$result['updated']++;
		}
	}

	$outgoing = libeufinconnectorReadNexusOutgoingTransactions($limit);
	if (empty($outgoing['ok'])) {
		$result['error'] = libeufinconnectorCompactCommandMessage($outgoing['error'].($outgoing['output'] !== '' ? "\n".$outgoing['output'] : ''));
		return $result;
	}

	foreach ($outgoing['rows'] as $row) {
		$data = libeufinconnectorBuildOutgoingStageData($row);
		$data = libeufinconnectorMergeStagePayloadWithExisting($db, $data);
		$upsert = libeufinconnectorStageTransaction($db, $data, $user, 1);
		$result['rows'][] = $upsert;
		$result['total']++;
		$result['booked_total']++;

		if (empty($upsert['ok'])) {
			$result['errors']++;
			continue;
		}

		if ($upsert['action'] === 'created') {
			$result['created']++;
		} elseif ($upsert['action'] === 'updated') {
			$result['updated']++;
		}
	}

	$result['ok'] = ($result['errors'] === 0);

	return $result;
}

/**
 * Collect outgoing transactions from Dolibarr prepared orders and Nexus status tables.
 *
 * @param DoliDB      $db Database handler.
 * @param object|null $user Acting user.
 * @param int         $limit Optional limit.
 * @return array{ok:bool,error:string,total:int,created:int,updated:int,errors:int,prepared_total:int,initiated_total:int,booked_total:int}
 */
function libeufinconnectorCollectOutgoingTransactions($db, $user = null, $limit = 0)
{
	$result = array(
		'ok' => false,
		'error' => '',
		'total' => 0,
		'created' => 0,
		'updated' => 0,
		'errors' => 0,
		'prepared_total' => 0,
		'initiated_total' => 0,
		'booked_total' => 0,
	);

	$prepared = libeufinconnectorStagePreparedOutgoingTransactions($db, $user, $limit);
	if (empty($prepared['ok']) && !empty($prepared['error'])) {
		$result['error'] = $prepared['error'];
		return $result;
	}

	$supplierPayments = libeufinconnectorStagePreparedOutgoingSupplierPayments($db, $user, $limit);
	if (empty($supplierPayments['ok']) && !empty($supplierPayments['error'])) {
		$result['error'] = $supplierPayments['error'];
		return $result;
	}

	$customerRefunds = libeufinconnectorStagePreparedOutgoingCustomerRefundPayments($db, $user, $limit);
	if (empty($customerRefunds['ok']) && !empty($customerRefunds['error'])) {
		$result['error'] = $customerRefunds['error'];
		return $result;
	}

	$nexus = libeufinconnectorStageNexusOutgoingTransactions($db, $user, $limit);
	if (empty($nexus['ok']) && !empty($nexus['error'])) {
		$result['error'] = $nexus['error'];
		return $result;
	}

	$result['total'] = (int) $prepared['total'] + (int) $supplierPayments['total'] + (int) $customerRefunds['total'] + (int) $nexus['total'];
	$result['created'] = (int) $prepared['created'] + (int) $supplierPayments['created'] + (int) $customerRefunds['created'] + (int) $nexus['created'];
	$result['updated'] = (int) $prepared['updated'] + (int) $supplierPayments['updated'] + (int) $customerRefunds['updated'] + (int) $nexus['updated'];
	$result['errors'] = (int) $prepared['errors'] + (int) $supplierPayments['errors'] + (int) $customerRefunds['errors'] + (int) $nexus['errors'];
	$result['prepared_total'] = (int) $prepared['total'] + (int) $supplierPayments['total'] + (int) $customerRefunds['total'];
	$result['initiated_total'] = (int) $nexus['initiated_total'];
	$result['booked_total'] = (int) $nexus['booked_total'];
	$result['ok'] = ($result['errors'] === 0);

	return $result;
}

/**
 * Build a generic libeufin-nexus command.
 *
 * @param array<int,string> $args Command arguments after the binary name.
 * @param string            $logLevel Log level.
 * @return array{ok:bool,error:string,command:string,preview:string}
 */
function libeufinconnectorBuildNexusCommandWithArgs(array $args, $logLevel = 'INFO')
{
	$configPath = libeufinconnectorGetEffectiveNexusConfigPath();
	if ($configPath === '' || !is_readable($configPath)) {
		return array('ok' => false, 'error' => 'config_not_readable', 'command' => '', 'preview' => '');
	}

	$tempDirResult = libeufinconnectorEnsureTempDir();
	if (empty($tempDirResult['ok'])) {
		return array('ok' => false, 'error' => $tempDirResult['error'], 'command' => '', 'preview' => '');
	}
	$keyDirResult = libeufinconnectorEnsureLocalNexusKeysDir();
	if (empty($keyDirResult['ok'])) {
		return array('ok' => false, 'error' => $keyDirResult['error'], 'command' => '', 'preview' => '');
	}

	$binary = trim((string) getDolGlobalString('LIBEUFINCONNECTOR_NEXUS_BINARY'));
	$prefix = trim((string) getDolGlobalString('LIBEUFINCONNECTOR_NEXUS_COMMAND_PREFIX'));
	if ($binary === '') {
		return array('ok' => false, 'error' => 'missing_binary', 'command' => '', 'preview' => '');
	}

	$fullArgs = array_merge($args, array('-c', $configPath, '-L', strtoupper(trim((string) $logLevel))));
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
 * Execute one shell command and capture its output.
 *
 * @param string $command Full shell command.
 * @return array{ok:bool,output:string,code:int}
 */
function libeufinconnectorExecShellCommand($command)
{
	$output = array();
	$returnCode = 1;
	@exec($command.' 2>&1', $output, $returnCode);

	return array(
		'ok' => ($returnCode === 0),
		'output' => libeufinconnectorCompactCommandMessage(implode("\n", $output)),
		'code' => $returnCode,
	);
}

/**
 * Tell whether a staged outgoing row is eligible for sending.
 *
 * @param LibeufinTransaction $transaction Staged transaction.
 * @return bool
 */
function libeufinconnectorIsOutgoingTransactionOpen(LibeufinTransaction $transaction)
{
	if ($transaction->direction !== LibeufinTransaction::DIRECTION_OUTGOING) {
		return false;
	}

	return in_array($transaction->transaction_status, array(
		LibeufinTransaction::STATUS_NEW,
		LibeufinTransaction::STATUS_FAILED,
	), true);
}

/**
 * Extract the outgoing subject from a staged transaction payload.
 *
 * @param LibeufinTransaction $transaction Staged transaction.
 * @return string
 */
function libeufinconnectorGetOutgoingTransactionSubject(LibeufinTransaction $transaction)
{
	$payload = libeufinconnectorGetTransactionPayloadArray($transaction);

	if (!empty($payload['subject'])) {
		return trim((string) $payload['subject']);
	}
	if (isset($payload['nexus']) && is_array($payload['nexus']) && !empty($payload['nexus']['subject'])) {
		return trim((string) $payload['nexus']['subject']);
	}

	return '';
}

/**
 * Extract or build the outgoing payto URI from a staged transaction.
 *
 * @param LibeufinTransaction $transaction Staged transaction.
 * @return string
 */
function libeufinconnectorGetOutgoingTransactionPayto(LibeufinTransaction $transaction)
{
	$payload = libeufinconnectorGetTransactionPayloadArray($transaction);

	if (!empty($payload['payto'])) {
		return trim((string) $payload['payto']);
	}
	if (isset($payload['nexus']) && is_array($payload['nexus']) && !empty($payload['nexus']['credit_payto'])) {
		return trim((string) $payload['nexus']['credit_payto']);
	}

	return libeufinconnectorBuildIbanPaytoUri($transaction->counterparty_iban, $transaction->counterparty_name, $transaction->counterparty_bic);
}

/**
 * Load staged outgoing transactions by row id.
 *
 * @param DoliDB           $db Database handler.
 * @param array<int,mixed> $ids Row ids.
 * @return array<int,LibeufinTransaction>
 */
function libeufinconnectorLoadTransactionsByIds($db, array $ids)
{
	$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
	$transactions = array();

	foreach ($ids as $id) {
		$transaction = new LibeufinTransaction($db);
		if ($transaction->fetch($id) > 0) {
			$transactions[] = $transaction;
		}
	}

	return $transactions;
}

/**
 * Return the row ids of all open staged outgoing transactions.
 *
 * @param DoliDB $db Database handler.
 * @return array<int,int>
 */
function libeufinconnectorGetOpenOutgoingTransactionIds($db)
{
	global $conf;

	$ids = array();
	$sql = "SELECT rowid";
	$sql .= " FROM ".MAIN_DB_PREFIX."libeufinconnector_transaction";
	$sql .= " WHERE entity = ".((int) $conf->entity);
	$sql .= " AND direction = '".$db->escape(LibeufinTransaction::DIRECTION_OUTGOING)."'";
	$sql .= " AND transaction_status IN ('".$db->escape(LibeufinTransaction::STATUS_NEW)."', '".$db->escape(LibeufinTransaction::STATUS_FAILED)."')";
	$sql .= " AND (fk_prelevement_bons IS NOT NULL OR fk_paiementfourn IS NOT NULL OR fk_paiement IS NOT NULL)";
	$sql .= " ORDER BY transaction_date ASC, rowid ASC";

	$resql = $db->query($sql);
	if (!$resql) {
		return $ids;
	}

	while ($obj = $db->fetch_object($resql)) {
		$transaction = new LibeufinTransaction($db);
		if ($transaction->fetch((int) $obj->rowid) > 0 && libeufinconnectorIsOutgoingTransactionReadyForSend($transaction)) {
			$ids[] = (int) $obj->rowid;
		}
	}
	$db->free($resql);

	return $ids;
}

/**
 * Send selected staged outgoing transactions to LibEuFin Nexus.
 *
 * @param DoliDB           $db Database handler.
 * @param object|null      $user Acting user.
 * @param array<int,mixed> $ids Selected row ids.
 * @return array{ok:bool,error:string,total:int,initiated:int,skipped:int,failed:int,submit_ok:bool,submit_error:string,refreshed:int}
 */
function libeufinconnectorSendOutgoingTransactions($db, $user, array $ids)
{
	$transactions = libeufinconnectorLoadTransactionsByIds($db, $ids);
	$result = array(
		'ok' => false,
		'error' => '',
		'total' => count($transactions),
		'initiated' => 0,
		'skipped' => 0,
		'failed' => 0,
		'submit_ok' => false,
		'submit_error' => '',
		'refreshed' => 0,
	);

	foreach ($transactions as $transaction) {
		if (!libeufinconnectorIsOutgoingTransactionOpen($transaction)) {
			$result['skipped']++;
			continue;
		}

		$payload = libeufinconnectorGetTransactionPayloadArray($transaction);
		$validation = libeufinconnectorGetOutgoingValidationFromPayloadArray($payload);
		if (empty($payload['source']) || !in_array($payload['source'], array('dolibarr_prepared_outgoing', 'dolibarr_supplier_payment', 'dolibarr_customer_refund'), true)) {
			$result['skipped']++;
			continue;
		}
		if (empty($validation['ready'])) {
			$payload['send_result'] = array(
				'status' => 'skipped',
				'validation_issues' => $validation['issues'],
				'at' => dol_print_date(dol_now(), '%Y-%m-%d %H:%M:%S'),
			);
			$transaction->raw_payload = $payload;
			$transaction->update($user, 1);
			$result['skipped']++;
			continue;
		}

		$payto = libeufinconnectorGetOutgoingTransactionPayto($transaction);
		$subject = libeufinconnectorGetOutgoingTransactionSubject($transaction);
		$amount = strtoupper($transaction->currency).':'.rtrim(rtrim(number_format((float) $transaction->amount, 8, '.', ''), '0'), '.');

		if ($transaction->external_transaction_id === '' || $payto === '') {
			$payload['submit_result'] = array(
				'status' => 'failed',
				'error' => 'Missing end-to-end id or payto URI.',
				'at' => dol_print_date(dol_now(), '%Y-%m-%d %H:%M:%S'),
			);
			$transaction->transaction_status = LibeufinTransaction::STATUS_FAILED;
			$transaction->raw_payload = $payload;
			$transaction->update($user, 1);
			$result['failed']++;
			continue;
		}

		$args = array(
			'initiate-payment',
			$payto,
			'--amount='.$amount,
			'--end-to-end-id='.$transaction->external_transaction_id,
		);
		if ($subject !== '') {
			$args[] = '--subject='.$subject;
		}

		$build = libeufinconnectorBuildNexusCommandWithArgs($args, 'INFO');
		if (empty($build['ok'])) {
			$result['error'] = $build['error'];
			$result['failed']++;
			continue;
		}

		$exec = libeufinconnectorExecShellCommand($build['command']);
		$payload['submit_result'] = array(
			'status' => ($exec['ok'] ? 'ok' : 'failed'),
			'command_preview' => $build['preview'],
			'output' => $exec['output'],
			'at' => dol_print_date(dol_now(), '%Y-%m-%d %H:%M:%S'),
		);
		$transaction->raw_payload = $payload;

		if ($exec['ok']) {
			$transaction->transaction_status = LibeufinTransaction::STATUS_SUBMITTED;
			$transaction->update($user, 1);
			$result['initiated']++;
		} else {
			$transaction->transaction_status = LibeufinTransaction::STATUS_FAILED;
			$transaction->update($user, 1);
			$result['failed']++;
		}
	}

	if ($result['initiated'] > 0) {
		$submit = libeufinconnectorRunNexusOperationNow('ebics_submit');
		$result['submit_ok'] = !empty($submit['ok']);
		$result['submit_error'] = !empty($submit['ok']) ? '' : $submit['error'];

		$refresh = libeufinconnectorStageNexusOutgoingTransactions($db, $user);
		if (!empty($refresh['ok'])) {
			$result['refreshed'] = (int) $refresh['total'];
		}
	}

	$result['ok'] = ($result['failed'] === 0 && $result['error'] === '' && ($result['initiated'] === 0 || $result['submit_ok']));

	return $result;
}

/**
 * Convert a raw Nexus row into a staging payload.
 *
 * @param array<string,mixed> $row Raw Nexus row.
 * @return array<string,mixed>
 */
function libeufinconnectorBuildIncomingStageData(array $row)
{
	$payto = libeufinconnectorParsePaytoUri(isset($row['debit_payto']) ? (string) $row['debit_payto'] : '');
	$payload = array(
		'source' => 'libeufin_nexus.incoming_transactions',
		'direction' => 'incoming',
		'nexus' => $row,
	);

	return array(
		'external_transaction_id' => !empty($row['tx_id']) ? (string) $row['tx_id'] : (!empty($row['incoming_transaction_id']) ? 'incoming:'.$row['incoming_transaction_id'] : ''),
		'external_message_id' => !empty($row['uetr']) ? (string) $row['uetr'] : '',
		'external_order_id' => !empty($row['acct_svcr_ref']) ? (string) $row['acct_svcr_ref'] : '',
		'direction' => LibeufinTransaction::DIRECTION_INCOMING,
		'amount' => libeufinconnectorNormalizeNexusAmount(
			isset($row['amount_val']) ? $row['amount_val'] : 0,
			isset($row['amount_frac']) ? $row['amount_frac'] : 0
		),
		'currency' => libeufinconnectorGetImportedTransactionCurrency(),
		'transaction_date' => libeufinconnectorNormalizeNexusEpoch(isset($row['execution_time']) ? $row['execution_time'] : null),
		'counterparty_iban' => $payto['iban'],
		'counterparty_bic' => $payto['bic'],
		'counterparty_name' => $payto['name'],
		'raw_payload' => $payload,
	);
}

/**
 * Stage current Nexus incoming transactions into the Dolibarr staging table.
 *
 * @param DoliDB      $db Database handler.
 * @param object|null $user Acting user.
 * @param int         $limit Optional limit.
 * @return array{ok:bool,error:string,total:int,created:int,updated:int,errors:int,rows:array<int,array<string,mixed>>}
 */
function libeufinconnectorStageIncomingTransactions($db, $user = null, $limit = 0)
{
	$result = array(
		'ok' => false,
		'error' => '',
		'total' => 0,
		'created' => 0,
		'updated' => 0,
		'errors' => 0,
		'rows' => array(),
	);

	if (!libeufinconnectorHasTransactionTable($db)) {
		$result['error'] = 'missing_staging_table';
		return $result;
	}

	$incoming = libeufinconnectorReadNexusIncomingTransactions($limit);
	if (empty($incoming['ok'])) {
		$result['error'] = libeufinconnectorCompactCommandMessage($incoming['error'].($incoming['output'] !== '' ? "\n".$incoming['output'] : ''));
		return $result;
	}

	foreach ($incoming['rows'] as $row) {
		$data = libeufinconnectorBuildIncomingStageData($row);
		$upsert = libeufinconnectorStageTransaction($db, $data, $user, 1);
		$result['rows'][] = $upsert;
		$result['total']++;

		if (empty($upsert['ok'])) {
			$result['errors']++;
			continue;
		}

		if ($upsert['action'] === 'created') {
			$result['created']++;
		} elseif ($upsert['action'] === 'updated') {
			$result['updated']++;
		}
	}

	$result['ok'] = ($result['errors'] === 0);

	return $result;
}

/**
 * Decode the raw payload JSON for a staged transaction.
 *
 * @param LibeufinTransaction $transaction Staged transaction.
 * @return array<string,mixed>
 */
function libeufinconnectorGetTransactionPayloadArray(LibeufinTransaction $transaction)
{
	$payload = json_decode((string) $transaction->raw_payload, true);
	return is_array($payload) ? $payload : array();
}

/**
 * Update one staged outgoing transaction with a selected beneficiary bank account.
 *
 * @param DoliDB              $db Database handler.
 * @param LibeufinTransaction $transaction Staged transaction.
 * @param int                 $bankAccountId Third-party bank account id.
 * @param object|null         $user Acting user.
 * @return array{ok:bool,error:string,status:string}
 */
function libeufinconnectorApplyOutgoingBeneficiaryBankAccount($db, LibeufinTransaction $transaction, $bankAccountId, $user = null)
{
	if ($transaction->direction !== LibeufinTransaction::DIRECTION_OUTGOING) {
		return array('ok' => false, 'error' => 'not_outgoing', 'status' => 'bad_direction');
	}

	$payload = libeufinconnectorGetTransactionPayloadArray($transaction);
	$socid = libeufinconnectorGetOutgoingPayloadSocid($payload);
	if ($socid <= 0) {
		return array('ok' => false, 'error' => 'missing_thirdparty', 'status' => 'missing_thirdparty');
	}

	$bankAccount = libeufinconnectorReadThirdpartyBankAccount($db, $bankAccountId, $socid);
	if (empty($bankAccount['ok'])) {
		return array('ok' => false, 'error' => $bankAccount['error'], 'status' => 'bank_account_not_found');
	}

	$data = array(
		'raw_payload' => $payload,
		'counterparty_iban' => $transaction->counterparty_iban,
		'counterparty_bic' => $transaction->counterparty_bic,
		'counterparty_name' => $transaction->counterparty_name,
	);
	$data = libeufinconnectorApplyOutgoingBeneficiaryBankAccountToData($data, $bankAccount['row']);

	$transaction->raw_payload = $data['raw_payload'];
	$transaction->payload_checksum = LibeufinTransaction::buildPayloadChecksum($transaction->raw_payload);
	$transaction->counterparty_iban = $data['counterparty_iban'];
	$transaction->counterparty_bic = $data['counterparty_bic'];
	$transaction->counterparty_name = $data['counterparty_name'];

	$result = $transaction->update($user, 1);
	if ($result <= 0) {
		return array('ok' => false, 'error' => $transaction->error, 'status' => 'update_failed');
	}

	return array('ok' => true, 'error' => '', 'status' => 'saved');
}

/**
 * Build a bank line label for an incoming staged transaction.
 *
 * @param LibeufinTransaction $transaction Staged transaction.
 * @return string
 */
function libeufinconnectorBuildIncomingBankLabel(LibeufinTransaction $transaction)
{
	$payload = libeufinconnectorGetTransactionPayloadArray($transaction);
	$subject = '';
	if (isset($payload['nexus']) && is_array($payload['nexus']) && !empty($payload['nexus']['subject'])) {
		$subject = trim((string) $payload['nexus']['subject']);
	}

	if ($subject !== '') {
		return $subject;
	}

	if (!empty($transaction->counterparty_name)) {
		return 'Incoming transfer '.$transaction->counterparty_name;
	}

	if (!empty($transaction->external_transaction_id)) {
		return 'Incoming transfer '.$transaction->external_transaction_id;
	}

	return 'Incoming transfer #'.((int) $transaction->id);
}

/**
 * Create a Dolibarr bank line for an incoming staged transaction.
 *
 * @param DoliDB              $db Database handler.
 * @param LibeufinTransaction $transaction Staged transaction.
 * @param object              $user Acting user.
 * @return array{ok:bool,status:string,error:string,bank_id:int}
 */
function libeufinconnectorCreateIncomingBankLine($db, LibeufinTransaction $transaction, $user)
{
	if ($transaction->direction !== LibeufinTransaction::DIRECTION_INCOMING) {
		return array('ok' => false, 'status' => 'bad_direction', 'error' => 'Only incoming transactions can create bank lines.', 'bank_id' => 0);
	}

	if ((int) $transaction->fk_bank > 0) {
		return array('ok' => true, 'status' => 'already_imported', 'error' => '', 'bank_id' => (int) $transaction->fk_bank);
	}

	$bankAccountId = (int) getDolGlobalInt('LIBEUFINCONNECTOR_BANK_ACCOUNT_ID');
	if ($bankAccountId <= 0) {
		return array('ok' => false, 'status' => 'missing_bank_account', 'error' => 'No mapped Dolibarr bank account is configured.', 'bank_id' => 0);
	}

	require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';

	$account = new Account($db);
	if ($account->fetch($bankAccountId) <= 0) {
		return array('ok' => false, 'status' => 'bank_account_not_found', 'error' => $account->error, 'bank_id' => 0);
	}

	$notePrivate = array();
	if (!empty($transaction->external_transaction_id)) {
		$notePrivate[] = 'External transaction: '.$transaction->external_transaction_id;
	}
	if (!empty($transaction->external_message_id)) {
		$notePrivate[] = 'Message id: '.$transaction->external_message_id;
	}
	if (!empty($transaction->external_order_id)) {
		$notePrivate[] = 'Order id: '.$transaction->external_order_id;
	}
	if (!empty($transaction->counterparty_iban)) {
		$notePrivate[] = 'Counterparty IBAN: '.$transaction->counterparty_iban;
	}

	$timestamp = libeufinconnectorResolveTransactionTimestamp($transaction->transaction_date);
	$bankLineId = $account->addline(
		$timestamp,
		'VIR',
		libeufinconnectorBuildIncomingBankLabel($transaction),
		abs((float) $transaction->amount),
		'',
		0,
		$user,
		(string) $transaction->counterparty_name,
		(!empty($transaction->counterparty_bic) ? (string) $transaction->counterparty_bic : (string) $transaction->counterparty_iban),
		'',
		0,
		'',
		null,
		implode("\n", $notePrivate)
	);

	if ($bankLineId <= 0) {
		return array('ok' => false, 'status' => 'bank_create_failed', 'error' => $account->error, 'bank_id' => 0);
	}

	$transaction->fk_bank = (int) $bankLineId;
	if (in_array($transaction->transaction_status, array(LibeufinTransaction::STATUS_NEW, LibeufinTransaction::STATUS_FAILED, LibeufinTransaction::STATUS_IGNORED), true)) {
		$transaction->transaction_status = LibeufinTransaction::STATUS_IMPORTED;
	}

	$update = $transaction->update($user, 1);
	if ($update <= 0) {
		return array('ok' => false, 'status' => 'transaction_update_failed', 'error' => $transaction->error, 'bank_id' => (int) $bankLineId);
	}

	return array('ok' => true, 'status' => 'created', 'error' => '', 'bank_id' => (int) $bankLineId);
}

/**
 * Return the payment message/reference text used for incoming invoice matching.
 *
 * @param LibeufinTransaction $transaction Staged transaction.
 * @return string
 */
function libeufinconnectorGetIncomingInvoiceReferenceText(LibeufinTransaction $transaction)
{
	$payload = libeufinconnectorGetTransactionPayloadArray($transaction);
	$text = '';
	if (isset($payload['nexus']) && is_array($payload['nexus']) && !empty($payload['nexus']['subject'])) {
		$text = trim((string) $payload['nexus']['subject']);
	}

	return strtoupper($text);
}

/**
 * Normalize an IBAN-like value for comparisons.
 *
 * @param string $iban IBAN value.
 * @return string
 */
function libeufinconnectorNormalizeComparableIban($iban)
{
	return strtoupper(str_replace(' ', '', trim((string) $iban)));
}

/**
 * Extract the credited account IBAN from an incoming staged transaction.
 *
 * @param LibeufinTransaction $transaction Staged transaction.
 * @return string
 */
function libeufinconnectorGetIncomingRecipientIban(LibeufinTransaction $transaction)
{
	$payload = libeufinconnectorGetTransactionPayloadArray($transaction);
	$creditPayto = '';
	if (isset($payload['nexus']) && is_array($payload['nexus']) && !empty($payload['nexus']['credit_payto'])) {
		$creditPayto = (string) $payload['nexus']['credit_payto'];
	}

	$payto = libeufinconnectorParsePaytoUri($creditPayto);
	return libeufinconnectorNormalizeComparableIban($payto['iban']);
}

/**
 * Return the normalized configured incoming receiving IBAN and related bank account id.
 *
 * @param DoliDB $db Database handler.
 * @return array{bank_account_id:int,iban:string}
 */
function libeufinconnectorGetConfiguredIncomingReceivingAccountData($db)
{
	require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';

	$bankAccountId = (int) getDolGlobalInt('LIBEUFINCONNECTOR_BANK_ACCOUNT_ID');
	if ($bankAccountId <= 0) {
		return array('bank_account_id' => 0, 'iban' => '');
	}

	$account = new Account($db);
	if ($account->fetch($bankAccountId) <= 0) {
		return array('bank_account_id' => 0, 'iban' => '');
	}

	$iban = libeufinconnectorDecryptBankField(!empty($account->iban) ? $account->iban : $account->iban_prefix);

	return array(
		'bank_account_id' => $bankAccountId,
		'iban' => libeufinconnectorNormalizeComparableIban($iban),
	);
}

/**
 * Ensure native Dolibarr object links exist for a staged transaction.
 *
 * @param DoliDB              $db Database handler.
 * @param LibeufinTransaction $transaction Staged transaction.
 * @param object|null         $user Acting user.
 * @return void
 */
function libeufinconnectorEnsureTransactionObjectLinks($db, LibeufinTransaction $transaction, $user = null)
{
	$linkTargets = array(
		'fk_bank' => 'bank',
		'fk_paiement' => 'payment',
		'fk_paiementfourn' => 'payment_supplier',
		'fk_facture' => 'facture',
		'fk_facture_fourn' => 'invoice_supplier',
		'fk_prelevement_bons' => 'widthdraw',
	);

	foreach ($linkTargets as $field => $sourceType) {
		$sourceId = (int) $transaction->{$field};
		if ($sourceId <= 0 || (int) $transaction->id <= 0) {
			continue;
		}

		$sql = "SELECT rowid";
		$sql .= " FROM ".MAIN_DB_PREFIX."element_element";
		$sql .= " WHERE fk_source = ".$sourceId;
		$sql .= " AND sourcetype = '".$db->escape($sourceType)."'";
		$sql .= " AND fk_target = ".((int) $transaction->id);
		$sql .= " AND targettype = '".$db->escape($transaction->getElementType())."'";

		$resql = $db->query($sql);
		if ($resql) {
			$obj = $db->fetch_object($resql);
			$db->free($resql);
			if ($obj) {
				continue;
			}
		}

		$transaction->add_object_linked($sourceType, $sourceId, $user, 1);
	}
}

/**
 * Tell whether a staged transaction should expose the manual-link UI.
 *
 * @param LibeufinTransaction $transaction Staged transaction.
 * @return bool
 */
function libeufinconnectorCanManuallyLinkTransaction(LibeufinTransaction $transaction)
{
	return ((int) $transaction->fk_paiement <= 0
		&& (int) $transaction->fk_paiementfourn <= 0
		&& (int) $transaction->fk_facture <= 0
		&& (int) $transaction->fk_facture_fourn <= 0);
}

/**
 * Read recent invoice and credit-note candidates for manual linking.
 *
 * @param DoliDB              $db Database handler.
 * @param LibeufinTransaction $transaction Staged transaction.
 * @param int                 $limit Max rows per family.
 * @return array<int,array<string,mixed>>
 */
function libeufinconnectorReadManualInvoiceCandidates($db, LibeufinTransaction $transaction, $limit = 15)
{
	global $conf;

	$rows = array();
	$limit = max(1, (int) $limit);

	$sql = "SELECT f.rowid, f.ref, f.type, f.total_ttc, f.fk_statut, f.datef, f.multicurrency_code, s.nom AS thirdparty_name";
	$sql .= " FROM ".MAIN_DB_PREFIX."facture AS f";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe AS s ON s.rowid = f.fk_soc";
	$sql .= " WHERE f.entity = ".((int) $conf->entity);
	$sql .= " ORDER BY f.datef DESC, f.rowid DESC";
	$sql .= $db->plimit($limit, 0);
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$rows[] = array(
				'object_type' => 'facture',
				'rowid' => (int) $obj->rowid,
				'ref' => (string) $obj->ref,
				'type' => (int) $obj->type,
				'amount' => (float) $obj->total_ttc,
				'currency' => (!empty($obj->multicurrency_code) ? (string) $obj->multicurrency_code : (string) $conf->currency),
				'status' => (int) $obj->fk_statut,
				'date' => (string) $obj->datef,
				'thirdparty_name' => (string) $obj->thirdparty_name,
			);
		}
		$db->free($resql);
	}

	$sql = "SELECT f.rowid, f.ref, f.type, f.total_ttc, f.fk_statut, f.datef, f.multicurrency_code, s.nom AS thirdparty_name";
	$sql .= " FROM ".MAIN_DB_PREFIX."facture_fourn AS f";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe AS s ON s.rowid = f.fk_soc";
	$sql .= " WHERE f.entity = ".((int) $conf->entity);
	$sql .= " ORDER BY f.datef DESC, f.rowid DESC";
	$sql .= $db->plimit($limit, 0);
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$rows[] = array(
				'object_type' => 'invoice_supplier',
				'rowid' => (int) $obj->rowid,
				'ref' => (string) $obj->ref,
				'type' => (int) $obj->type,
				'amount' => (float) $obj->total_ttc,
				'currency' => (!empty($obj->multicurrency_code) ? (string) $obj->multicurrency_code : (string) $conf->currency),
				'status' => (int) $obj->fk_statut,
				'date' => (string) $obj->datef,
				'thirdparty_name' => (string) $obj->thirdparty_name,
			);
		}
		$db->free($resql);
	}

	usort($rows, function ($a, $b) {
		$aDate = strtotime((string) $a['date']);
		$bDate = strtotime((string) $b['date']);
		if ($aDate === $bDate) {
			return ((int) $b['rowid'] <=> (int) $a['rowid']);
		}
		return ($bDate <=> $aDate);
	});

	return $rows;
}

/**
 * Read recent payment candidates for manual linking.
 *
 * @param DoliDB              $db Database handler.
 * @param LibeufinTransaction $transaction Staged transaction.
 * @param int                 $limit Max rows per family.
 * @return array<int,array<string,mixed>>
 */
function libeufinconnectorReadManualPaymentCandidates($db, LibeufinTransaction $transaction, $limit = 15)
{
	global $conf;

	$rows = array();
	$limit = max(1, (int) $limit);
	$absAmount = (float) price2num(abs((float) $transaction->amount), 'MT');

	$sql = "SELECT p.rowid, p.ref, p.amount, p.datep, p.fk_bank";
	$sql .= " FROM ".MAIN_DB_PREFIX."paiement AS p";
	$sql .= " WHERE p.entity = ".((int) $conf->entity);
	$sql .= " ORDER BY p.datep DESC, p.rowid DESC";
	$sql .= $db->plimit($limit * 3, 0);
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			if ((float) price2num(abs((float) $obj->amount), 'MT') != $absAmount) {
				continue;
			}
			$rows[] = array(
				'object_type' => 'payment',
				'rowid' => (int) $obj->rowid,
				'ref' => (string) $obj->ref,
				'amount' => (float) $obj->amount,
				'date' => (string) $obj->datep,
				'fk_bank' => (int) $obj->fk_bank,
			);
		}
		$db->free($resql);
	}

	$sql = "SELECT p.rowid, p.ref, p.amount, p.datep, p.fk_bank";
	$sql .= " FROM ".MAIN_DB_PREFIX."paiementfourn AS p";
	$sql .= " WHERE p.entity = ".((int) $conf->entity);
	$sql .= " ORDER BY p.datep DESC, p.rowid DESC";
	$sql .= $db->plimit($limit * 3, 0);
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			if ((float) price2num(abs((float) $obj->amount), 'MT') != $absAmount) {
				continue;
			}
			$rows[] = array(
				'object_type' => 'payment_supplier',
				'rowid' => (int) $obj->rowid,
				'ref' => (string) $obj->ref,
				'amount' => (float) $obj->amount,
				'date' => (string) $obj->datep,
				'fk_bank' => (int) $obj->fk_bank,
			);
		}
		$db->free($resql);
	}

	usort($rows, function ($a, $b) {
		$aDate = strtotime((string) $a['date']);
		$bDate = strtotime((string) $b['date']);
		if ($aDate === $bDate) {
			return ((int) $b['rowid'] <=> (int) $a['rowid']);
		}
		return ($bDate <=> $aDate);
	});

	return array_slice($rows, 0, $limit);
}

/**
 * Read recent bank-line candidates for manual linking.
 *
 * @param DoliDB              $db Database handler.
 * @param LibeufinTransaction $transaction Staged transaction.
 * @param int                 $limit Max rows.
 * @return array<int,array<string,mixed>>
 */
function libeufinconnectorReadManualBankCandidates($db, LibeufinTransaction $transaction, $limit = 20)
{
	global $conf;

	$rows = array();
	$limit = max(1, (int) $limit);
	$targetAmount = ($transaction->direction === LibeufinTransaction::DIRECTION_INCOMING ? abs((float) $transaction->amount) : -abs((float) $transaction->amount));
	$targetAmount = (float) price2num($targetAmount, 'MT');

	$sql = "SELECT b.rowid, b.label, b.amount, b.dateo, b.fk_account";
	$sql .= " FROM ".MAIN_DB_PREFIX."bank AS b";
	$sql .= " ORDER BY b.dateo DESC, b.rowid DESC";
	$sql .= $db->plimit($limit * 4, 0);
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			if ((float) price2num((float) $obj->amount, 'MT') != $targetAmount) {
				continue;
			}
			$rows[] = array(
				'object_type' => 'bank',
				'rowid' => (int) $obj->rowid,
				'label' => (string) $obj->label,
				'amount' => (float) $obj->amount,
				'date' => (string) $obj->dateo,
				'fk_account' => (int) $obj->fk_account,
			);
		}
		$db->free($resql);
	}

	return array_slice($rows, 0, $limit);
}

/**
 * Return a grouped candidate list for constrained manual linking.
 *
 * @param DoliDB              $db Database handler.
 * @param LibeufinTransaction $transaction Staged transaction.
 * @return array{invoices:array<int,array<string,mixed>>,payments:array<int,array<string,mixed>>,banks:array<int,array<string,mixed>>}
 */
function libeufinconnectorReadManualLinkCandidates($db, LibeufinTransaction $transaction)
{
	return array(
		'invoices' => libeufinconnectorReadManualInvoiceCandidates($db, $transaction),
		'payments' => libeufinconnectorReadManualPaymentCandidates($db, $transaction),
		'banks' => libeufinconnectorReadManualBankCandidates($db, $transaction),
	);
}

/**
 * Resolve customer payment related ids.
 *
 * @param DoliDB $db Database handler.
 * @param int    $paymentId Customer payment id.
 * @return array{fk_paiement:int,fk_bank:int,fk_facture:int}
 */
function libeufinconnectorResolveCustomerPaymentLinks($db, $paymentId)
{
	require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';

	$result = array('fk_paiement' => 0, 'fk_bank' => 0, 'fk_facture' => 0);
	$payment = new Paiement($db);
	if ($payment->fetch((int) $paymentId) <= 0) {
		return $result;
	}

	$result['fk_paiement'] = (int) $payment->id;
	$result['fk_bank'] = (int) $payment->bank_line;
	$amounts = $payment->getAmountsArray();
	if (is_array($amounts) && count($amounts) === 1) {
		$invoiceIds = array_keys($amounts);
		$result['fk_facture'] = (int) reset($invoiceIds);
	}

	return $result;
}

/**
 * Resolve supplier payment related ids.
 *
 * @param DoliDB $db Database handler.
 * @param int    $paymentId Supplier payment id.
 * @return array{fk_paiementfourn:int,fk_bank:int,fk_facture_fourn:int}
 */
function libeufinconnectorResolveSupplierPaymentLinks($db, $paymentId)
{
	require_once DOL_DOCUMENT_ROOT.'/fourn/class/paiementfourn.class.php';

	$result = array('fk_paiementfourn' => 0, 'fk_bank' => 0, 'fk_facture_fourn' => 0);
	$payment = new PaiementFourn($db);
	if ($payment->fetch((int) $paymentId) <= 0) {
		return $result;
	}

	$result['fk_paiementfourn'] = (int) $payment->id;
	$result['fk_bank'] = (int) $payment->bank_line;

	$sql = "SELECT fk_facturefourn";
	$sql .= " FROM ".MAIN_DB_PREFIX."paiementfourn_facturefourn";
	$sql .= " WHERE fk_paiementfourn = ".((int) $payment->id);
	$resql = $db->query($sql);
	if ($resql) {
		$invoiceIds = array();
		while ($obj = $db->fetch_object($resql)) {
			$invoiceIds[] = (int) $obj->fk_facturefourn;
		}
		$db->free($resql);
		if (count($invoiceIds) === 1) {
			$result['fk_facture_fourn'] = $invoiceIds[0];
		}
	}

	return $result;
}

/**
 * Resolve bank-line related ids.
 *
 * @param DoliDB $db Database handler.
 * @param int    $bankId Bank line id.
 * @return array{fk_bank:int,fk_paiement:int,fk_paiementfourn:int,fk_facture:int,fk_facture_fourn:int}
 */
function libeufinconnectorResolveBankLineLinks($db, $bankId)
{
	$result = array(
		'fk_bank' => (int) $bankId,
		'fk_paiement' => 0,
		'fk_paiementfourn' => 0,
		'fk_facture' => 0,
		'fk_facture_fourn' => 0,
	);

	$sql = "SELECT type, url_id";
	$sql .= " FROM ".MAIN_DB_PREFIX."bank_url";
	$sql .= " WHERE fk_bank = ".((int) $bankId);
	$sql .= " AND type IN ('payment', 'payment_supplier', 'facture', 'invoice_supplier')";
	$resql = $db->query($sql);
	if (!$resql) {
		return $result;
	}

	$customerPayments = array();
	$supplierPayments = array();
	$customerInvoices = array();
	$supplierInvoices = array();
	while ($obj = $db->fetch_object($resql)) {
		if ($obj->type === 'payment') {
			$customerPayments[] = (int) $obj->url_id;
		} elseif ($obj->type === 'payment_supplier') {
			$supplierPayments[] = (int) $obj->url_id;
		} elseif ($obj->type === 'facture') {
			$customerInvoices[] = (int) $obj->url_id;
		} elseif ($obj->type === 'invoice_supplier') {
			$supplierInvoices[] = (int) $obj->url_id;
		}
	}
	$db->free($resql);

	if (count($customerPayments) === 1) {
		$result = array_merge($result, libeufinconnectorResolveCustomerPaymentLinks($db, $customerPayments[0]));
		$result['fk_bank'] = (int) $bankId;
		return $result;
	}
	if (count($supplierPayments) === 1) {
		$result = array_merge($result, libeufinconnectorResolveSupplierPaymentLinks($db, $supplierPayments[0]));
		$result['fk_bank'] = (int) $bankId;
		return $result;
	}
	if (count($customerInvoices) === 1) {
		$result['fk_facture'] = $customerInvoices[0];
		return $result;
	}
	if (count($supplierInvoices) === 1) {
		$result['fk_facture_fourn'] = $supplierInvoices[0];
		return $result;
	}

	return $result;
}

/**
 * Apply a constrained manual object link onto one staged transaction.
 *
 * @param DoliDB              $db Database handler.
 * @param LibeufinTransaction $transaction Staged transaction.
 * @param string              $linkCase One of invoice, payment, bank.
 * @param string              $target Encoded object target.
 * @param object              $user Acting user.
 * @return array{ok:bool,status:string,error:string}
 */
function libeufinconnectorApplyManualTransactionLink($db, LibeufinTransaction $transaction, $linkCase, $target, $user)
{
	$linkCase = trim((string) $linkCase);
	$target = trim((string) $target);
	if ($target === '') {
		return array('ok' => false, 'status' => 'missing_target', 'error' => 'No object was selected.');
	}

	$parts = explode(':', $target, 2);
	$targetType = $parts[0];
	$targetId = isset($parts[1]) ? (int) $parts[1] : 0;
	if ($targetId <= 0) {
		return array('ok' => false, 'status' => 'bad_target', 'error' => 'The selected object identifier is invalid.');
	}

	$resolved = array(
		'fk_bank' => (int) $transaction->fk_bank,
		'fk_paiement' => 0,
		'fk_paiementfourn' => 0,
		'fk_facture' => 0,
		'fk_facture_fourn' => 0,
	);

	if ($linkCase === 'invoice') {
		if ($targetType === 'facture') {
			$resolved['fk_facture'] = $targetId;
			$paymentData = array('fk_paiement' => 0, 'fk_bank' => 0);
			$sql = "SELECT fk_paiement";
			$sql .= " FROM ".MAIN_DB_PREFIX."paiement_facture";
			$sql .= " WHERE fk_facture = ".$targetId;
			$resql = $db->query($sql);
			if ($resql) {
				$paymentIds = array();
				while ($obj = $db->fetch_object($resql)) {
					$paymentIds[] = (int) $obj->fk_paiement;
				}
				$db->free($resql);
				if (count($paymentIds) === 1) {
					$paymentData = libeufinconnectorResolveCustomerPaymentLinks($db, $paymentIds[0]);
				}
			}
			if ((int) $paymentData['fk_bank'] <= 0) {
				$paymentData['fk_bank'] = (int) $resolved['fk_bank'];
			}
			$resolved = array_merge($resolved, $paymentData);
		} elseif ($targetType === 'invoice_supplier') {
			$resolved['fk_facture_fourn'] = $targetId;
			$paymentData = array('fk_paiementfourn' => 0, 'fk_bank' => 0);
			$sql = "SELECT fk_paiementfourn";
			$sql .= " FROM ".MAIN_DB_PREFIX."paiementfourn_facturefourn";
			$sql .= " WHERE fk_facturefourn = ".$targetId;
			$resql = $db->query($sql);
			if ($resql) {
				$paymentIds = array();
				while ($obj = $db->fetch_object($resql)) {
					$paymentIds[] = (int) $obj->fk_paiementfourn;
				}
				$db->free($resql);
				if (count($paymentIds) === 1) {
					$paymentData = libeufinconnectorResolveSupplierPaymentLinks($db, $paymentIds[0]);
				}
			}
			if ((int) $paymentData['fk_bank'] <= 0) {
				$paymentData['fk_bank'] = (int) $resolved['fk_bank'];
			}
			$resolved = array_merge($resolved, $paymentData);
		} else {
			return array('ok' => false, 'status' => 'bad_target_type', 'error' => 'The selected object is not an invoice or credit note.');
		}
	} elseif ($linkCase === 'payment') {
		if ($targetType === 'payment') {
			$paymentData = libeufinconnectorResolveCustomerPaymentLinks($db, $targetId);
			if ((int) $paymentData['fk_bank'] <= 0) {
				$paymentData['fk_bank'] = (int) $resolved['fk_bank'];
			}
			$resolved = array_merge($resolved, $paymentData);
		} elseif ($targetType === 'payment_supplier') {
			$paymentData = libeufinconnectorResolveSupplierPaymentLinks($db, $targetId);
			if ((int) $paymentData['fk_bank'] <= 0) {
				$paymentData['fk_bank'] = (int) $resolved['fk_bank'];
			}
			$resolved = array_merge($resolved, $paymentData);
		} else {
			return array('ok' => false, 'status' => 'bad_target_type', 'error' => 'The selected object is not a payment.');
		}
	} elseif ($linkCase === 'bank') {
		if ($targetType !== 'bank') {
			return array('ok' => false, 'status' => 'bad_target_type', 'error' => 'The selected object is not a bank line.');
		}
		$resolved = array_merge($resolved, libeufinconnectorResolveBankLineLinks($db, $targetId));
	} else {
		return array('ok' => false, 'status' => 'bad_link_case', 'error' => 'The selected manual-link case is not supported.');
	}

	$transaction->fk_bank = (int) $resolved['fk_bank'];
	$transaction->fk_paiement = (int) $resolved['fk_paiement'];
	$transaction->fk_paiementfourn = (int) $resolved['fk_paiementfourn'];
	$transaction->fk_facture = (int) $resolved['fk_facture'];
	$transaction->fk_facture_fourn = (int) $resolved['fk_facture_fourn'];

	if ($transaction->fk_facture > 0 || $transaction->fk_facture_fourn > 0 || $transaction->fk_paiement > 0 || $transaction->fk_paiementfourn > 0) {
		$transaction->transaction_status = LibeufinTransaction::STATUS_MATCHED;
	} elseif ($transaction->fk_bank > 0 && in_array($transaction->transaction_status, array(LibeufinTransaction::STATUS_NEW, LibeufinTransaction::STATUS_FAILED, LibeufinTransaction::STATUS_IGNORED), true)) {
		$transaction->transaction_status = LibeufinTransaction::STATUS_IMPORTED;
	}

	$update = $transaction->update($user, 1);
	if ($update <= 0) {
		return array('ok' => false, 'status' => 'transaction_update_failed', 'error' => $transaction->error);
	}
	libeufinconnectorEnsureTransactionObjectLinks($db, $transaction, $user);

	return array('ok' => true, 'status' => 'linked', 'error' => '');
}

/**
 * Return the normalized invoice receiving IBAN and related bank account id.
 *
 * @param DoliDB  $db Database handler.
 * @param Facture $invoice Customer invoice.
 * @return array{bank_account_id:int,iban:string}
 */
function libeufinconnectorGetInvoiceReceivingAccountData($db, Facture $invoice)
{
	require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';

	$bankAccountId = (int) $invoice->fk_account;
	if ($bankAccountId <= 0) {
		return array('bank_account_id' => 0, 'iban' => '');
	}

	$account = new Account($db);
	if ($account->fetch($bankAccountId) <= 0) {
		return array('bank_account_id' => 0, 'iban' => '');
	}

	$iban = libeufinconnectorDecryptBankField(!empty($account->iban) ? $account->iban : $account->iban_prefix);

	return array(
		'bank_account_id' => $bankAccountId,
		'iban' => libeufinconnectorNormalizeComparableIban($iban),
	);
}

/**
 * Return the normalized supplier invoice receiving IBAN and related bank account id.
 *
 * @param DoliDB             $db Database handler.
 * @param FactureFournisseur $invoice Supplier invoice.
 * @return array{bank_account_id:int,iban:string}
 */
function libeufinconnectorGetSupplierInvoiceReceivingAccountData($db, $invoice)
{
	require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';

	$bankAccountId = (int) $invoice->fk_account;
	if ($bankAccountId <= 0) {
		return array('bank_account_id' => 0, 'iban' => '');
	}

	$account = new Account($db);
	if ($account->fetch($bankAccountId) <= 0) {
		return array('bank_account_id' => 0, 'iban' => '');
	}

	$iban = libeufinconnectorDecryptBankField(!empty($account->iban) ? $account->iban : $account->iban_prefix);

	return array(
		'bank_account_id' => $bankAccountId,
		'iban' => libeufinconnectorNormalizeComparableIban($iban),
	);
}

/**
 * Find a unique strict invoice match for an incoming staged transaction.
 *
 * @param DoliDB              $db Database handler.
 * @param LibeufinTransaction $transaction Staged transaction.
 * @return array{status:string,error:string,invoice_id:int,candidate_ids:array<int,int>}
 */
function libeufinconnectorFindExactIncomingInvoiceMatch($db, LibeufinTransaction $transaction)
{
	global $conf;

	if ($transaction->direction !== LibeufinTransaction::DIRECTION_INCOMING) {
		return array('status' => 'bad_direction', 'error' => 'Only incoming transactions can match customer invoices.', 'invoice_id' => 0, 'candidate_ids' => array());
	}

	$searchText = libeufinconnectorGetIncomingInvoiceReferenceText($transaction);
	if ($searchText === '') {
		return array('status' => 'missing_reference_text', 'error' => 'No payment message is available for invoice matching.', 'invoice_id' => 0, 'candidate_ids' => array());
	}

	require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

	$sql = "SELECT rowid";
	$sql .= " FROM ".MAIN_DB_PREFIX."facture";
	$sql .= " WHERE entity = ".((int) $conf->entity);
	$sql .= " AND fk_statut = ".((int) Facture::STATUS_VALIDATED);
	$sql .= " AND paye = 0";
	$sql .= " AND INSTR('".$db->escape($searchText)."', UPPER(ref)) > 0";
	$sql .= " ORDER BY rowid DESC";

	$resql = $db->query($sql);
	if (!$resql) {
		return array('status' => 'query_failed', 'error' => $db->lasterror(), 'invoice_id' => 0, 'candidate_ids' => array());
	}

	$candidateIds = array();
	$transactionCurrency = strtoupper((string) $transaction->currency);
	$companyCurrency = strtoupper((string) $conf->currency);
	$transactionAmount = (float) price2num($transaction->amount, 'MT');

	while ($obj = $db->fetch_object($resql)) {
		$invoice = new Facture($db);
		if ($invoice->fetch((int) $obj->rowid) <= 0) {
			continue;
		}

		$invoiceReceivingAccount = libeufinconnectorGetInvoiceReceivingAccountData($db, $invoice);
		$recipientIban = libeufinconnectorGetIncomingRecipientIban($transaction);
		if ($recipientIban === '') {
			$configuredIncomingAccount = libeufinconnectorGetConfiguredIncomingReceivingAccountData($db);
			$recipientIban = $configuredIncomingAccount['iban'];
		}
		if ($invoiceReceivingAccount['bank_account_id'] <= 0 || $invoiceReceivingAccount['iban'] === '' || $recipientIban === '') {
			continue;
		}
		if ($invoiceReceivingAccount['iban'] !== $recipientIban) {
			continue;
		}

		$useMulticurrency = (!empty($invoice->multicurrency_code) && strtoupper((string) $invoice->multicurrency_code) === $transactionCurrency && $transactionCurrency !== $companyCurrency);
		if (!$useMulticurrency && $transactionCurrency !== $companyCurrency) {
			continue;
		}

		$remainToPay = (float) price2num($invoice->getRemainToPay($useMulticurrency ? 1 : 0), 'MT');
		if ($transactionAmount - $remainToPay > 0.00001) {
			continue;
		}

		$candidateIds[] = (int) $invoice->id;
	}

	$db->free($resql);

	if (count($candidateIds) === 1) {
		return array('status' => 'matched', 'error' => '', 'invoice_id' => $candidateIds[0], 'candidate_ids' => $candidateIds);
	}
	if (count($candidateIds) > 1) {
		return array('status' => 'ambiguous', 'error' => 'More than one invoice matches the strict rules.', 'invoice_id' => 0, 'candidate_ids' => $candidateIds);
	}

	return array('status' => 'no_match', 'error' => '', 'invoice_id' => 0, 'candidate_ids' => array());
}

/**
 * Find a unique strict supplier credit-note refund match for an incoming staged transaction.
 *
 * @param DoliDB              $db Database handler.
 * @param LibeufinTransaction $transaction Staged transaction.
 * @return array{status:string,error:string,invoice_id:int,candidate_ids:array<int,int>}
 */
function libeufinconnectorFindExactIncomingSupplierRefundMatch($db, LibeufinTransaction $transaction)
{
	global $conf;

	if ($transaction->direction !== LibeufinTransaction::DIRECTION_INCOMING) {
		return array('status' => 'bad_direction', 'error' => 'Only incoming transactions can match supplier credit notes.', 'invoice_id' => 0, 'candidate_ids' => array());
	}

	$searchText = libeufinconnectorGetIncomingInvoiceReferenceText($transaction);
	if ($searchText === '') {
		return array('status' => 'missing_reference_text', 'error' => 'No payment message is available for supplier credit-note matching.', 'invoice_id' => 0, 'candidate_ids' => array());
	}

	require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';

	$sql = "SELECT rowid";
	$sql .= " FROM ".MAIN_DB_PREFIX."facture_fourn";
	$sql .= " WHERE entity = ".((int) $conf->entity);
	$sql .= " AND fk_statut = ".((int) FactureFournisseur::STATUS_VALIDATED);
	$sql .= " AND type = ".((int) FactureFournisseur::TYPE_CREDIT_NOTE);
	$sql .= " AND paye = 0";
	$sql .= " AND INSTR('".$db->escape($searchText)."', UPPER(ref)) > 0";
	$sql .= " ORDER BY rowid DESC";

	$resql = $db->query($sql);
	if (!$resql) {
		return array('status' => 'query_failed', 'error' => $db->lasterror(), 'invoice_id' => 0, 'candidate_ids' => array());
	}

	$candidateIds = array();
	$transactionCurrency = strtoupper((string) $transaction->currency);
	$companyCurrency = strtoupper((string) $conf->currency);
	$transactionAmount = (float) price2num($transaction->amount, 'MT');
	$recipientIban = libeufinconnectorGetIncomingRecipientIban($transaction);
	if ($recipientIban === '') {
		$configuredIncomingAccount = libeufinconnectorGetConfiguredIncomingReceivingAccountData($db);
		$recipientIban = $configuredIncomingAccount['iban'];
	}

	while ($obj = $db->fetch_object($resql)) {
		$invoice = new FactureFournisseur($db);
		if ($invoice->fetch((int) $obj->rowid) <= 0) {
			continue;
		}

		$invoiceReceivingAccount = libeufinconnectorGetSupplierInvoiceReceivingAccountData($db, $invoice);
		if ($invoiceReceivingAccount['bank_account_id'] <= 0 || $invoiceReceivingAccount['iban'] === '' || $recipientIban === '') {
			continue;
		}
		if ($invoiceReceivingAccount['iban'] !== $recipientIban) {
			continue;
		}

		$useMulticurrency = (!empty($invoice->multicurrency_code) && strtoupper((string) $invoice->multicurrency_code) === $transactionCurrency && $transactionCurrency !== $companyCurrency);
		if (!$useMulticurrency && $transactionCurrency !== $companyCurrency) {
			continue;
		}

		$remainToRefund = abs((float) price2num($invoice->getRemainToPay($useMulticurrency ? 1 : 0), 'MT'));
		if ($transactionAmount - $remainToRefund > 0.00001) {
			continue;
		}

		$candidateIds[] = (int) $invoice->id;
	}

	$db->free($resql);

	if (count($candidateIds) === 1) {
		return array('status' => 'matched', 'error' => '', 'invoice_id' => $candidateIds[0], 'candidate_ids' => $candidateIds);
	}
	if (count($candidateIds) > 1) {
		return array('status' => 'ambiguous', 'error' => 'More than one supplier credit note matches the strict rules.', 'invoice_id' => 0, 'candidate_ids' => $candidateIds);
	}

	return array('status' => 'no_match', 'error' => '', 'invoice_id' => 0, 'candidate_ids' => array());
}

/**
 * Create a Dolibarr customer payment and bank entry for a matched incoming transaction.
 *
 * @param DoliDB              $db Database handler.
 * @param LibeufinTransaction $transaction Staged transaction.
 * @param int                 $invoiceId Matched customer invoice id.
 * @param object              $user Acting user.
 * @return array{ok:bool,status:string,error:string,payment_id:int,bank_id:int,invoice_id:int}
 */
function libeufinconnectorCreateIncomingCustomerPayment($db, LibeufinTransaction $transaction, $invoiceId, $user)
{
	global $conf;

	if ($transaction->direction !== LibeufinTransaction::DIRECTION_INCOMING) {
		return array('ok' => false, 'status' => 'bad_direction', 'error' => 'Only incoming transactions can create customer payments.', 'payment_id' => 0, 'bank_id' => 0, 'invoice_id' => 0);
	}
	if ((int) $transaction->fk_paiement > 0) {
		return array('ok' => true, 'status' => 'already_imported', 'error' => '', 'payment_id' => (int) $transaction->fk_paiement, 'bank_id' => (int) $transaction->fk_bank, 'invoice_id' => (int) $transaction->fk_facture);
	}
	if ((int) $transaction->fk_bank > 0) {
		return array('ok' => false, 'status' => 'existing_bank_line', 'error' => 'A bank line already exists for this transaction. Match the invoice without auto-creating a customer payment.', 'payment_id' => 0, 'bank_id' => (int) $transaction->fk_bank, 'invoice_id' => 0);
	}

	require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
	require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';

	$invoice = new Facture($db);
	if ($invoice->fetch((int) $invoiceId) <= 0) {
		return array('ok' => false, 'status' => 'invoice_not_found', 'error' => $invoice->error, 'payment_id' => 0, 'bank_id' => 0, 'invoice_id' => 0);
	}

	$invoiceReceivingAccount = libeufinconnectorGetInvoiceReceivingAccountData($db, $invoice);
	if ($invoiceReceivingAccount['bank_account_id'] <= 0 || $invoiceReceivingAccount['iban'] === '') {
		return array('ok' => false, 'status' => 'invoice_bank_account_missing', 'error' => 'The matched invoice has no receiving bank account configured.', 'payment_id' => 0, 'bank_id' => 0, 'invoice_id' => 0);
	}

	$recipientIban = libeufinconnectorGetIncomingRecipientIban($transaction);
	if ($recipientIban === '') {
		$configuredIncomingAccount = libeufinconnectorGetConfiguredIncomingReceivingAccountData($db);
		$recipientIban = $configuredIncomingAccount['iban'];
	}
	if ($recipientIban === '' || $invoiceReceivingAccount['iban'] !== $recipientIban) {
		return array('ok' => false, 'status' => 'invoice_bank_account_mismatch', 'error' => 'The incoming transfer recipient account does not match the invoice receiving bank account.', 'payment_id' => 0, 'bank_id' => 0, 'invoice_id' => 0);
	}

	$transactionCurrency = strtoupper((string) $transaction->currency);
	$companyCurrency = strtoupper((string) $conf->currency);
	$transactionAmount = (float) price2num($transaction->amount, 'MT');
	$useMulticurrency = (!empty($invoice->multicurrency_code) && strtoupper((string) $invoice->multicurrency_code) === $transactionCurrency && $transactionCurrency !== $companyCurrency);
	if (!$useMulticurrency && $transactionCurrency !== $companyCurrency) {
		return array('ok' => false, 'status' => 'currency_mismatch', 'error' => 'The incoming payment currency does not match the invoice currency.', 'payment_id' => 0, 'bank_id' => 0, 'invoice_id' => 0);
	}

	$remainToPay = (float) price2num($invoice->getRemainToPay($useMulticurrency ? 1 : 0), 'MT');
	if ($transactionAmount - $remainToPay > 0.00001) {
		return array('ok' => false, 'status' => 'amount_exceeds_open_amount', 'error' => 'The incoming amount exceeds the invoice open amount.', 'payment_id' => 0, 'bank_id' => 0, 'invoice_id' => 0);
	}

	$paymentModeId = (int) dol_getIdFromCode($db, 'VIR', 'c_paiement', 'code', 'id', 1);
	if ($paymentModeId <= 0) {
		return array('ok' => false, 'status' => 'payment_mode_missing', 'error' => 'Dolibarr payment mode VIR was not found.', 'payment_id' => 0, 'bank_id' => 0, 'invoice_id' => 0);
	}

	$referenceText = trim(libeufinconnectorGetIncomingInvoiceReferenceText($transaction));
	$payment = new Paiement($db);
	$payment->datepaye = libeufinconnectorResolveTransactionTimestamp($transaction->transaction_date);
	$payment->amounts = array((int) $invoice->id => $transactionAmount);
	if ($useMulticurrency) {
		$payment->multicurrency_amounts = array((int) $invoice->id => $transactionAmount);
		$payment->multicurrency_code = array((int) $invoice->id => $transactionCurrency);
	}
	$payment->paiementid = $paymentModeId;
	$payment->paiementcode = 'VIR';
	$payment->num_payment = !empty($transaction->external_order_id) ? (string) $transaction->external_order_id : (string) $transaction->external_transaction_id;
	$payment->note_private = trim(implode("\n", array_filter(array(
		$referenceText,
		(!empty($transaction->external_transaction_id) ? 'External transaction: '.$transaction->external_transaction_id : ''),
		(!empty($transaction->external_message_id) ? 'Message id: '.$transaction->external_message_id : ''),
		(!empty($transaction->external_order_id) ? 'Order id: '.$transaction->external_order_id : ''),
	))));
	$payment->ref_ext = (string) $transaction->external_transaction_id;

	$paymentId = $payment->create($user, 0);
	if ($paymentId <= 0) {
		return array('ok' => false, 'status' => 'payment_create_failed', 'error' => $payment->error, 'payment_id' => 0, 'bank_id' => 0, 'invoice_id' => 0);
	}

	$bankId = $payment->addPaymentToBank($user, 'payment', libeufinconnectorBuildIncomingBankLabel($transaction), $invoiceReceivingAccount['bank_account_id'], '', '');
	if ($bankId <= 0) {
		return array('ok' => false, 'status' => 'payment_bank_create_failed', 'error' => $payment->error, 'payment_id' => (int) $paymentId, 'bank_id' => 0, 'invoice_id' => (int) $invoice->id);
	}

	$transaction->fk_paiement = (int) $paymentId;
	$transaction->fk_bank = (int) $bankId;
	$transaction->fk_facture = (int) $invoice->id;
	$transaction->transaction_status = LibeufinTransaction::STATUS_MATCHED;
	$update = $transaction->update($user, 1);
	if ($update <= 0) {
		return array('ok' => false, 'status' => 'transaction_update_failed', 'error' => $transaction->error, 'payment_id' => (int) $paymentId, 'bank_id' => (int) $bankId, 'invoice_id' => (int) $invoice->id);
	}
	libeufinconnectorEnsureTransactionObjectLinks($db, $transaction, $user);

	return array('ok' => true, 'status' => 'created', 'error' => '', 'payment_id' => (int) $paymentId, 'bank_id' => (int) $bankId, 'invoice_id' => (int) $invoice->id);
}

/**
 * Create a native Dolibarr supplier refund payment and bank entry for a matched incoming supplier credit note.
 *
 * @param DoliDB              $db Database handler.
 * @param LibeufinTransaction $transaction Staged transaction.
 * @param int                 $invoiceId Matched supplier credit note id.
 * @param object              $user Acting user.
 * @return array{ok:bool,status:string,error:string,payment_id:int,bank_id:int,invoice_id:int}
 */
function libeufinconnectorCreateIncomingSupplierRefundPayment($db, LibeufinTransaction $transaction, $invoiceId, $user)
{
	global $conf;
	$existingBankId = (int) $transaction->fk_bank;

	if ($transaction->direction !== LibeufinTransaction::DIRECTION_INCOMING) {
		return array('ok' => false, 'status' => 'bad_direction', 'error' => 'Only incoming transactions can create supplier refund payments.', 'payment_id' => 0, 'bank_id' => 0, 'invoice_id' => 0);
	}
	if ((int) $transaction->fk_paiementfourn > 0) {
		return array('ok' => true, 'status' => 'already_imported', 'error' => '', 'payment_id' => (int) $transaction->fk_paiementfourn, 'bank_id' => (int) $transaction->fk_bank, 'invoice_id' => (int) $transaction->fk_facture_fourn);
	}

	require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
	require_once DOL_DOCUMENT_ROOT.'/fourn/class/paiementfourn.class.php';
	require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
	require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';

	$invoice = new FactureFournisseur($db);
	if ($invoice->fetch((int) $invoiceId) <= 0) {
		return array('ok' => false, 'status' => 'invoice_not_found', 'error' => $invoice->error, 'payment_id' => 0, 'bank_id' => 0, 'invoice_id' => 0);
	}

	$invoiceReceivingAccount = libeufinconnectorGetSupplierInvoiceReceivingAccountData($db, $invoice);
	if ($invoiceReceivingAccount['bank_account_id'] <= 0 || $invoiceReceivingAccount['iban'] === '') {
		return array('ok' => false, 'status' => 'invoice_bank_account_missing', 'error' => 'The matched supplier credit note has no receiving bank account configured.', 'payment_id' => 0, 'bank_id' => 0, 'invoice_id' => 0);
	}

	$recipientIban = libeufinconnectorGetIncomingRecipientIban($transaction);
	if ($recipientIban === '') {
		$configuredIncomingAccount = libeufinconnectorGetConfiguredIncomingReceivingAccountData($db);
		$recipientIban = $configuredIncomingAccount['iban'];
	}
	if ($recipientIban === '' || $invoiceReceivingAccount['iban'] !== $recipientIban) {
		return array('ok' => false, 'status' => 'invoice_bank_account_mismatch', 'error' => 'The incoming transfer recipient account does not match the supplier credit note receiving bank account.', 'payment_id' => 0, 'bank_id' => 0, 'invoice_id' => 0);
	}

	$transactionCurrency = strtoupper((string) $transaction->currency);
	$companyCurrency = strtoupper((string) $conf->currency);
	$transactionAmount = (float) price2num($transaction->amount, 'MT');
	$useMulticurrency = (!empty($invoice->multicurrency_code) && strtoupper((string) $invoice->multicurrency_code) === $transactionCurrency && $transactionCurrency !== $companyCurrency);
	if (!$useMulticurrency && $transactionCurrency !== $companyCurrency) {
		return array('ok' => false, 'status' => 'currency_mismatch', 'error' => 'The incoming payment currency does not match the supplier credit note currency.', 'payment_id' => 0, 'bank_id' => 0, 'invoice_id' => 0);
	}

	$remainToRefund = abs((float) price2num($invoice->getRemainToPay($useMulticurrency ? 1 : 0), 'MT'));
	if ($transactionAmount - $remainToRefund > 0.00001) {
		return array('ok' => false, 'status' => 'amount_exceeds_open_amount', 'error' => 'The incoming amount exceeds the supplier credit note remaining refundable amount.', 'payment_id' => 0, 'bank_id' => 0, 'invoice_id' => 0);
	}

	$paymentModeId = (int) dol_getIdFromCode($db, 'VIR', 'c_paiement', 'code', 'id', 1);
	if ($paymentModeId <= 0) {
		return array('ok' => false, 'status' => 'payment_mode_missing', 'error' => 'Dolibarr payment mode VIR was not found.', 'payment_id' => 0, 'bank_id' => 0, 'invoice_id' => 0);
	}

	$thirdparty = new Societe($db);
	$thirdparty->fetch((int) $invoice->socid);

	$referenceText = trim(libeufinconnectorGetIncomingInvoiceReferenceText($transaction));
	$payment = new PaiementFourn($db);
	$payment->datepaye = libeufinconnectorResolveTransactionTimestamp($transaction->transaction_date);
	$payment->amounts = array((int) $invoice->id => -$transactionAmount);
	$paymentCurrencyCode = $useMulticurrency && !empty($invoice->multicurrency_code)
		? (string) $invoice->multicurrency_code
		: (string) $companyCurrency;
	$paymentCurrencyTx = $useMulticurrency && !empty($invoice->multicurrency_tx)
		? (float) $invoice->multicurrency_tx
		: 1.0;
	$payment->multicurrency_amounts = array((int) $invoice->id => -$transactionAmount);
	$payment->multicurrency_code = array((int) $invoice->id => $paymentCurrencyCode);
	$payment->multicurrency_tx = array((int) $invoice->id => $paymentCurrencyTx);
	$payment->paiementid = $paymentModeId;
	$payment->paiementcode = 'VIR';
	$payment->num_payment = !empty($transaction->external_order_id) ? (string) $transaction->external_order_id : (string) $transaction->external_transaction_id;
	$payment->note_private = trim(implode("\n", array_filter(array(
		$referenceText,
		(!empty($transaction->external_transaction_id) ? 'External transaction: '.$transaction->external_transaction_id : ''),
		(!empty($transaction->external_message_id) ? 'Message id: '.$transaction->external_message_id : ''),
		(!empty($transaction->external_order_id) ? 'Order id: '.$transaction->external_order_id : ''),
	))));
	$payment->fk_account = (int) $invoiceReceivingAccount['bank_account_id'];

	$paymentId = $payment->create($user, 1, $thirdparty);
	if ($paymentId <= 0) {
		return array('ok' => false, 'status' => 'payment_create_failed', 'error' => $payment->error, 'payment_id' => 0, 'bank_id' => 0, 'invoice_id' => 0);
	}

	$bankId = 0;
	if ($existingBankId > 0) {
		if ($payment->update_fk_bank($existingBankId) <= 0) {
			return array('ok' => false, 'status' => 'payment_bank_link_failed', 'error' => $payment->error, 'payment_id' => (int) $paymentId, 'bank_id' => $existingBankId, 'invoice_id' => (int) $invoice->id);
		}

		$bankAccount = new Account($db);
		if ($bankAccount->add_url_line($existingBankId, (int) $paymentId, DOL_URL_ROOT.'/fourn/paiement/card.php?id=', '(paiement)', 'payment_supplier') <= 0) {
			return array('ok' => false, 'status' => 'payment_bank_url_link_failed', 'error' => $bankAccount->error, 'payment_id' => (int) $paymentId, 'bank_id' => $existingBankId, 'invoice_id' => (int) $invoice->id);
		}

		$bankId = $existingBankId;
	} else {
		$bankId = $payment->addPaymentToBank($user, 'payment_supplier', '(SupplierInvoicePayment)', (int) $invoiceReceivingAccount['bank_account_id'], '', '');
		if ($bankId <= 0) {
			return array('ok' => false, 'status' => 'payment_bank_create_failed', 'error' => $payment->error, 'payment_id' => (int) $paymentId, 'bank_id' => 0, 'invoice_id' => (int) $invoice->id);
		}
	}

	$transaction->fk_paiementfourn = (int) $paymentId;
	$transaction->fk_bank = (int) $bankId;
	$transaction->fk_facture_fourn = (int) $invoice->id;
	$transaction->transaction_status = LibeufinTransaction::STATUS_MATCHED;
	$update = $transaction->update($user, 1);
	if ($update <= 0) {
		return array('ok' => false, 'status' => 'transaction_update_failed', 'error' => $transaction->error, 'payment_id' => (int) $paymentId, 'bank_id' => (int) $bankId, 'invoice_id' => (int) $invoice->id);
	}
	libeufinconnectorEnsureTransactionObjectLinks($db, $transaction, $user);

	return array('ok' => true, 'status' => 'created', 'error' => '', 'payment_id' => (int) $paymentId, 'bank_id' => (int) $bankId, 'invoice_id' => (int) $invoice->id);
}

/**
 * Persist a strict incoming invoice match onto the staged transaction.
 *
 * @param DoliDB              $db Database handler.
 * @param LibeufinTransaction $transaction Staged transaction.
 * @param object              $user Acting user.
 * @return array{ok:bool,status:string,error:string,invoice_id:int,payment_id:int,bank_id:int,candidate_ids:array<int,int>}
 */
function libeufinconnectorApplyIncomingInvoiceMatch($db, LibeufinTransaction $transaction, $user)
{
	if ((int) $transaction->fk_paiement > 0 && (int) $transaction->fk_facture > 0) {
		return array('ok' => true, 'status' => 'already_imported', 'error' => '', 'invoice_id' => (int) $transaction->fk_facture, 'supplier_invoice_id' => 0, 'payment_id' => (int) $transaction->fk_paiement, 'bank_id' => (int) $transaction->fk_bank, 'candidate_ids' => array((int) $transaction->fk_facture));
	}
	if ((int) $transaction->fk_facture > 0) {
		return array('ok' => true, 'status' => 'already_matched', 'error' => '', 'invoice_id' => (int) $transaction->fk_facture, 'supplier_invoice_id' => 0, 'payment_id' => (int) $transaction->fk_paiement, 'bank_id' => (int) $transaction->fk_bank, 'candidate_ids' => array((int) $transaction->fk_facture));
	}
	if ((int) $transaction->fk_facture_fourn > 0) {
		return array('ok' => true, 'status' => 'already_matched', 'error' => '', 'invoice_id' => 0, 'supplier_invoice_id' => (int) $transaction->fk_facture_fourn, 'payment_id' => 0, 'bank_id' => (int) $transaction->fk_bank, 'candidate_ids' => array((int) $transaction->fk_facture_fourn));
	}

	$match = libeufinconnectorFindExactIncomingInvoiceMatch($db, $transaction);
	if ($match['status'] === 'matched') {
		if ((int) $transaction->fk_bank <= 0) {
			$import = libeufinconnectorCreateIncomingCustomerPayment($db, $transaction, (int) $match['invoice_id'], $user);
			return array(
				'ok' => !empty($import['ok']),
				'status' => $import['status'],
				'error' => $import['error'],
				'invoice_id' => $import['invoice_id'],
				'supplier_invoice_id' => 0,
				'payment_id' => $import['payment_id'],
				'bank_id' => $import['bank_id'],
				'candidate_ids' => array((int) $match['invoice_id']),
			);
		}

		$transaction->fk_facture = (int) $match['invoice_id'];
		$transaction->transaction_status = LibeufinTransaction::STATUS_MATCHED;
		$update = $transaction->update($user, 1);
		if ($update <= 0) {
			return array('ok' => false, 'status' => 'transaction_update_failed', 'error' => $transaction->error, 'invoice_id' => 0, 'supplier_invoice_id' => 0, 'payment_id' => 0, 'bank_id' => 0, 'candidate_ids' => array());
		}
		libeufinconnectorEnsureTransactionObjectLinks($db, $transaction, $user);

		return array('ok' => true, 'status' => 'matched', 'error' => '', 'invoice_id' => (int) $match['invoice_id'], 'supplier_invoice_id' => 0, 'payment_id' => (int) $transaction->fk_paiement, 'bank_id' => (int) $transaction->fk_bank, 'candidate_ids' => array((int) $match['invoice_id']));
	}
	if (!in_array($match['status'], array('no_match', 'missing_reference_text'), true)) {
		return array(
			'ok' => false,
			'status' => $match['status'],
			'error' => $match['error'],
			'invoice_id' => $match['invoice_id'],
			'supplier_invoice_id' => 0,
			'payment_id' => 0,
			'bank_id' => 0,
			'candidate_ids' => $match['candidate_ids'],
		);
	}

	$supplierMatch = libeufinconnectorFindExactIncomingSupplierRefundMatch($db, $transaction);
	if ($supplierMatch['status'] !== 'matched') {
		return array(
			'ok' => false,
			'status' => $supplierMatch['status'],
			'error' => $supplierMatch['error'],
			'invoice_id' => 0,
			'supplier_invoice_id' => $supplierMatch['invoice_id'],
			'payment_id' => 0,
			'bank_id' => 0,
			'candidate_ids' => $supplierMatch['candidate_ids'],
		);
	}

	$import = libeufinconnectorCreateIncomingSupplierRefundPayment($db, $transaction, (int) $supplierMatch['invoice_id'], $user);
	return array(
		'ok' => !empty($import['ok']),
		'status' => $import['status'],
		'error' => $import['error'],
		'invoice_id' => 0,
		'supplier_invoice_id' => $import['invoice_id'],
		'payment_id' => $import['payment_id'],
		'bank_id' => $import['bank_id'],
		'candidate_ids' => array((int) $supplierMatch['invoice_id']),
	);
}

/**
 * Load incoming staged transactions that can still be auto-matched/imported.
 *
 * @param DoliDB $db Database handler.
 * @param int    $limit Optional limit.
 * @return array<int,LibeufinTransaction>
 */
function libeufinconnectorLoadAutoMatchableIncomingTransactions($db, $limit = 0)
{
	global $conf;

	$transactions = array();
	$sql = "SELECT rowid";
	$sql .= " FROM ".MAIN_DB_PREFIX."libeufinconnector_transaction";
	$sql .= " WHERE entity = ".((int) $conf->entity);
	$sql .= " AND direction = '".$db->escape(LibeufinTransaction::DIRECTION_INCOMING)."'";
	$sql .= " AND fk_paiement IS NULL";
	$sql .= " AND fk_facture IS NULL";
	$sql .= " AND fk_facture_fourn IS NULL";
	$sql .= " AND transaction_status IN ('".$db->escape(LibeufinTransaction::STATUS_NEW)."', '".$db->escape(LibeufinTransaction::STATUS_IMPORTED)."', '".$db->escape(LibeufinTransaction::STATUS_FAILED)."')";
	$sql .= " ORDER BY transaction_date DESC, rowid DESC";
	if ($limit > 0) {
		$sql .= $db->plimit($limit, 0);
	}

	$resql = $db->query($sql);
	if (!$resql) {
		return $transactions;
	}

	while ($obj = $db->fetch_object($resql)) {
		$transaction = new LibeufinTransaction($db);
		if ($transaction->fetch((int) $obj->rowid) > 0) {
			$transactions[] = $transaction;
		}
	}
	$db->free($resql);

	return $transactions;
}

/**
 * Automatically import incoming transactions that match exactly one invoice.
 *
 * @param DoliDB      $db Database handler.
 * @param object|null $user Acting user.
 * @param int         $limit Optional limit.
 * @return array{ok:bool,total:int,imported_payments:int,matched_existing_bank:int,no_match:int,ambiguous:int,errors:int}
 */
function libeufinconnectorAutoImportMatchedIncomingTransactions($db, $user = null, $limit = 0)
{
	$result = array(
		'ok' => true,
		'total' => 0,
		'imported_payments' => 0,
		'matched_existing_bank' => 0,
		'no_match' => 0,
		'ambiguous' => 0,
		'errors' => 0,
	);

	foreach (libeufinconnectorLoadAutoMatchableIncomingTransactions($db, $limit) as $transaction) {
		$apply = libeufinconnectorApplyIncomingInvoiceMatch($db, $transaction, $user);
		$result['total']++;

		if (!empty($apply['ok'])) {
			if (!empty($apply['payment_id'])) {
				$result['imported_payments']++;
			} else {
				$result['matched_existing_bank']++;
			}
			continue;
		}

		if ($apply['status'] === 'no_match' || $apply['status'] === 'missing_reference_text') {
			$result['no_match']++;
		} elseif ($apply['status'] === 'ambiguous') {
			$result['ambiguous']++;
		} else {
			$result['errors']++;
			$result['ok'] = false;
		}
	}

	return $result;
}
