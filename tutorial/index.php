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
 * \file    libeufinconnector/tutorial/index.php
 * \ingroup libeufinconnector
 * \brief   Tutorial/demo workbench for LibEuFin Connector
 */

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once __DIR__.'/../class/libeufintransaction.class.php';
require_once __DIR__.'/../lib/libeufinconnector.lib.php';
require_once __DIR__.'/../lib/transactionworkflow.lib.php';

/**
 * Render Nexus transactions as CLI-style output.
 *
 * @return string
 */
function libeufinconnectorTutorialCliTransactions()
{
	$incoming = libeufinconnectorReadNexusIncomingTransactions(100);
	$initiated = libeufinconnectorReadNexusInitiatedOutgoingTransactions(100);
	$booked = libeufinconnectorReadNexusOutgoingTransactions(100);
	$command = 'libeufin-nexus transactions:list --direction=all --format=cli';

	if (empty($incoming['ok']) || empty($initiated['ok']) || empty($booked['ok'])) {
		$errors = array();
		foreach (array($incoming, $initiated, $booked) as $result) {
			if (empty($result['ok']) && !empty($result['error'])) {
				$errors[] = $result['error'];
			}
		}
		return '$ '.$command."\n".'ERROR: '.implode('; ', array_unique($errors));
	}

	$rows = array();
	foreach ($incoming['rows'] as $row) {
		$payto = libeufinconnectorParsePaytoUri(isset($row['debit_payto']) ? (string) $row['debit_payto'] : '');
		$rows[] = array(
			'id' => (int) (isset($row['incoming_transaction_id']) ? $row['incoming_transaction_id'] : 0),
			'direction' => LibeufinTransaction::DIRECTION_INCOMING,
			'status' => 'booked',
			'amount' => libeufinconnectorNormalizeNexusAmount(isset($row['amount_val']) ? $row['amount_val'] : 0, isset($row['amount_frac']) ? $row['amount_frac'] : 0),
			'date' => libeufinconnectorNormalizeNexusEpoch(isset($row['execution_time']) ? $row['execution_time'] : null),
			'counterparty' => $payto['name'] ?: $payto['iban'],
			'external_id' => isset($row['acct_svcr_ref']) ? (string) $row['acct_svcr_ref'] : '',
		);
	}
	foreach ($initiated['rows'] as $row) {
		$payto = libeufinconnectorParsePaytoUri(isset($row['credit_payto']) ? (string) $row['credit_payto'] : '');
		$rows[] = array(
			'id' => (int) (isset($row['initiated_outgoing_transaction_id']) ? $row['initiated_outgoing_transaction_id'] : 0),
			'direction' => LibeufinTransaction::DIRECTION_OUTGOING,
			'status' => isset($row['status']) ? (string) $row['status'] : '',
			'amount' => libeufinconnectorNormalizeNexusAmount(isset($row['amount_val']) ? $row['amount_val'] : 0, isset($row['amount_frac']) ? $row['amount_frac'] : 0),
			'date' => libeufinconnectorNormalizeNexusEpoch(isset($row['initiation_time']) ? $row['initiation_time'] : null),
			'counterparty' => $payto['name'] ?: $payto['iban'],
			'external_id' => isset($row['end_to_end_id']) ? (string) $row['end_to_end_id'] : '',
		);
	}
	foreach ($booked['rows'] as $row) {
		$payto = libeufinconnectorParsePaytoUri(isset($row['credit_payto']) ? (string) $row['credit_payto'] : '');
		$rows[] = array(
			'id' => (int) (isset($row['outgoing_transaction_id']) ? $row['outgoing_transaction_id'] : 0),
			'direction' => LibeufinTransaction::DIRECTION_OUTGOING,
			'status' => 'booked',
			'amount' => libeufinconnectorNormalizeNexusAmount(isset($row['amount_val']) ? $row['amount_val'] : 0, isset($row['amount_frac']) ? $row['amount_frac'] : 0),
			'date' => libeufinconnectorNormalizeNexusEpoch(isset($row['execution_time']) ? $row['execution_time'] : null),
			'counterparty' => $payto['name'] ?: $payto['iban'],
			'external_id' => isset($row['end_to_end_id']) ? (string) $row['end_to_end_id'] : '',
		);
	}

	usort($rows, function ($a, $b) {
		return strcmp((string) $b['date'], (string) $a['date']);
	});

	$output = array();
	$output[] = '$ '.$command;
	$output[] = 'ROWID  DIR       STATUS     AMOUNT            DATE                 COUNTERPARTY                     EXTERNAL-ID';
	$output[] = '-----  --------  ---------  ----------------  -------------------  -------------------------------  ------------------------------';
	$count = 0;
	foreach (array_slice($rows, 0, 100) as $row) {
		$count++;
		$amount = number_format((float) $row['amount'], 2, '.', '').' '.libeufinconnectorGetImportedTransactionCurrency();
		$date = !empty($row['date']) ? dol_print_date(strtotime((string) $row['date']), '%Y-%m-%d %H:%M:%S') : '-';
		$output[] = sprintf(
			'%-5d  %-8s  %-9s  %-16s  %-19s  %-31s  %s',
			(int) $row['id'],
			(string) $row['direction'],
			substr((string) $row['status'], 0, 9),
			substr($amount, 0, 16),
			$date,
			substr((string) ($row['counterparty'] ?: '-'), 0, 31),
			(string) ($row['external_id'] ?: '-')
		);
	}
	if ($count === 0) {
		$output[] = 'No Nexus incoming or outgoing transactions found.';
	}
	$output[] = '';
	$output[] = 'Rows: '.$count;

	return implode("\n", $output);
}

/**
 * Convert visible escape sequences to console control characters.
 *
 * @param string $output Console output
 * @return string
 */
function libeufinconnectorTutorialRenderConsoleOutput($output)
{
	return str_replace(
		array('\\r\\n', '\\n', '\\t', '\\r'),
		array("\n", "\n", "\t", "\r"),
		(string) $output
	);
}

/**
 * Return the configured receiving account as a payto URI for fake incoming rows.
 *
 * @param DoliDB $db Database handler
 * @return array{ok:bool,error:string,payto:string,iban:string,bic:string,name:string}
 */
function libeufinconnectorTutorialReceivingAccount($db)
{
	$bankAccountId = (int) getDolGlobalInt('LIBEUFINCONNECTOR_BANK_ACCOUNT_ID');
	if ($bankAccountId <= 0) {
		return array('ok' => false, 'error' => 'missing_bank_account', 'payto' => '', 'iban' => '', 'bic' => '', 'name' => '');
	}

	$account = new Account($db);
	if ($account->fetch($bankAccountId) <= 0) {
		return array('ok' => false, 'error' => 'bank_account_not_found', 'payto' => '', 'iban' => '', 'bic' => '', 'name' => '');
	}

	$iban = libeufinconnectorDecryptBankField(!empty($account->iban) ? $account->iban : $account->iban_prefix);
	$bic = libeufinconnectorDecryptBankField((string) $account->bic);
	$name = trim((string) (!empty($account->owner_name) ? $account->owner_name : $account->proprio));
	if ($name === '') {
		$name = getDolGlobalString('MAIN_INFO_SOCIETE_NOM', 'Dolibarr');
	}

	$payto = libeufinconnectorBuildIbanPaytoUri($iban, $name, $bic);
	if ($payto === '') {
		return array('ok' => false, 'error' => 'bank_account_missing_iban_or_owner', 'payto' => '', 'iban' => '', 'bic' => '', 'name' => '');
	}

	return array('ok' => true, 'error' => '', 'payto' => $payto, 'iban' => $iban, 'bic' => $bic, 'name' => $name);
}

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("libeufinconnector@libeufinconnector", "banks"));

if (!empty($user->socid) && $user->socid > 0) {
	accessforbidden();
}
if (!libeufinconnectorCanUseTutorial($user)) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$commandOutput = '';
$defaultCurrency = getDolGlobalString('LIBEUFINCONNECTOR_EXPECTED_CURRENCY', getDolGlobalString('MAIN_MONNAIE', 'CHF'));
$amount = GETPOST('amount', 'alphanohtml');
$currency = strtoupper(trim((string) GETPOST('currency', 'alpha')));
$counterpartyName = trim((string) GETPOST('counterparty_name', 'alphanohtml'));
$counterpartyIban = strtoupper(str_replace(' ', '', trim((string) GETPOST('counterparty_iban', 'alphanohtml'))));
$counterpartyBic = strtoupper(str_replace(' ', '', trim((string) GETPOST('counterparty_bic', 'alphanohtml'))));
$reference = trim((string) GETPOST('reference', 'restricthtml'));

if ($amount === '') {
	$amount = '50.00';
}
if ($currency === '') {
	$currency = $defaultCurrency !== '' ? strtoupper($defaultCurrency) : 'CHF';
}
if ($counterpartyName === '') {
	$counterpartyName = 'Tutorial Customer';
}
if ($counterpartyIban === '') {
	$counterpartyIban = 'CH9300762011623852957';
}
if ($counterpartyBic === '') {
	$counterpartyBic = 'POFICHBEXXX';
}
if ($reference === '') {
	$reference = 'Tutorial fake incoming payment';
}

if ($action === 'create_fake_incoming') {
	if (GETPOST('token', 'alphanohtml') !== $_SESSION['newtoken']) {
		accessforbidden('Bad token');
	}

	$amountValue = price2num($amount, 'MT');
	if ($amountValue <= 0) {
		setEventMessages($langs->trans('LibeufinConnectorTutorialFakeAmountInvalid'), null, 'errors');
	} elseif ($currency === '') {
		setEventMessages($langs->trans('LibeufinConnectorTutorialFakeCurrencyInvalid'), null, 'errors');
	} else {
		$now = dol_now();
		$externalId = 'tutorial-incoming-'.dol_print_date($now, '%Y%m%d%H%M%S').'-'.random_int(1000, 9999);
		$receivingAccount = libeufinconnectorTutorialReceivingAccount($db);
		if (empty($receivingAccount['ok'])) {
			setEventMessages($langs->trans('LibeufinConnectorTutorialReceivingAccountInvalid', $receivingAccount['error']), null, 'errors');
		} else {
			$debitPayto = libeufinconnectorBuildIbanPaytoUri($counterpartyIban, $counterpartyName, $counterpartyBic);
			$payload = array(
				'source' => 'libeufinconnector-tutorial',
				'type' => 'fake_incoming',
				'nexus' => array(
					'row_id' => $externalId,
					'direction' => 'incoming',
					'subject' => $reference,
					'acct_svcr_ref' => $externalId,
					'message_id' => 'tutorial-message-'.$externalId,
					'credit_payto' => $receivingAccount['payto'],
					'debit_payto' => $debitPayto,
					'amount' => array(
						'val' => (int) floor($amountValue),
						'frac' => (int) round(($amountValue - floor($amountValue)) * 100000000),
						'currency' => $currency,
					),
				),
				'external_transaction_id' => $externalId,
				'amount' => $amountValue,
				'currency' => $currency,
				'counterparty_name' => $counterpartyName,
				'counterparty_iban' => $counterpartyIban,
				'counterparty_bic' => $counterpartyBic,
				'reference' => $reference,
				'receiving_account_iban' => $receivingAccount['iban'],
				'receiving_account_bic' => $receivingAccount['bic'],
				'created_at' => dol_print_date($now, '%Y-%m-%d %H:%M:%S'),
			);

			$result = LibeufinTransaction::upsertFromArray($db, array(
				'external_transaction_id' => $externalId,
				'external_message_id' => 'tutorial-message-'.$externalId,
				'external_order_id' => $reference,
				'direction' => LibeufinTransaction::DIRECTION_INCOMING,
				'transaction_status' => LibeufinTransaction::STATUS_NEW,
				'amount' => $amountValue,
				'currency' => $currency,
				'transaction_date' => $now,
				'counterparty_iban' => $counterpartyIban,
				'counterparty_bic' => $counterpartyBic,
				'counterparty_name' => $counterpartyName,
				'raw_payload' => $payload,
			), $user);

			$commandOutput = json_encode($result + array('payload' => $payload), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
			if (!empty($result['ok'])) {
				setEventMessages($langs->trans('LibeufinConnectorTutorialFakeIncomingCreated', $result['rowid']), null, 'mesgs');
			} else {
				setEventMessages($langs->trans('LibeufinConnectorTutorialFakeIncomingFailed', $result['error']), null, 'errors');
			}
		}
	}
} elseif ($action === 'show_transactions_cli') {
	if (GETPOST('token', 'alphanohtml') !== $_SESSION['newtoken']) {
		accessforbidden('Bad token');
	}

	$commandOutput = libeufinconnectorTutorialCliTransactions();
}

$form = new Form($db);
$transactionsUrl = dol_buildpath('/libeufinconnector/transactions.php?mainmenu=libeufinconnector&leftmenu=libeufinconnector_transactions', 1);

llxHeader('', $langs->trans('LibeufinConnectorTutorial'), '', '', 0, 0, '', '', '', 'mod-libeufinconnector page-tutorial');

print load_fiche_titre($langs->trans('LibeufinConnectorTutorial'), '', 'account');
print '<div class="warning">'.$langs->trans('LibeufinConnectorTutorialWarning').'</div><br>';

print '<div class="fichecenter">';
print '<div class="fichethirdleft">';
print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="create_fake_incoming">';
print '<table class="border centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('LibeufinConnectorTutorialFakeIncoming').'</td></tr>';
print '<tr><td class="titlefield">'.$langs->trans('Amount').'</td><td><input class="maxwidth100" type="text" name="amount" value="'.dol_escape_htmltag($amount).'"> <input class="maxwidth50" type="text" name="currency" value="'.dol_escape_htmltag($currency).'"></td></tr>';
print '<tr><td>'.$langs->trans('LibeufinConnectorTransactionCounterparty').'</td><td><input class="minwidth300" type="text" name="counterparty_name" value="'.dol_escape_htmltag($counterpartyName).'"></td></tr>';
print '<tr><td>'.$langs->trans('IBAN').'</td><td><input class="minwidth300" type="text" name="counterparty_iban" value="'.dol_escape_htmltag($counterpartyIban).'"></td></tr>';
print '<tr><td>'.$langs->trans('BIC').'</td><td><input class="minwidth200" type="text" name="counterparty_bic" value="'.dol_escape_htmltag($counterpartyBic).'"></td></tr>';
print '<tr><td>'.$langs->trans('Ref').'</td><td><input class="minwidth300" type="text" name="reference" value="'.dol_escape_htmltag($reference).'"></td></tr>';
print '</table>';
print '<div class="tabsAction">';
print '<div class="inline-block divButAction"><input class="butAction" type="submit" value="'.$langs->trans('LibeufinConnectorTutorialCreateFakeIncoming').'"></div>';
print '<div class="inline-block divButAction"><a class="butAction" href="'.$transactionsUrl.'">'.$langs->trans('LibeufinConnectorTransactions').'</a></div>';
print '</div>';
print '</form>';
print '</div>';

print '<div class="fichetwothirdright">';
print '<table class="border centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('LibeufinConnectorTutorialCliCommands').'</td></tr>';
print '<tr><td class="titlefield">'.$langs->trans('Command').'</td><td><span class="wordbreak">libeufin-nexus transactions:list --direction=all --format=cli</span></td></tr>';
print '<tr><td></td><td>';
print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="show_transactions_cli">';
print '<input class="button" type="submit" value="'.$langs->trans('LibeufinConnectorTutorialShowTransactionsCli').'">';
print '</form>';
print '</td></tr>';
print '</table>';
print '</div>';
print '</div>';

print '<div class="clearboth"></div>';
print '<br>';
print '<table class="border centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('LibeufinConnectorTutorialConsoleOutput').'</td></tr>';
print '<tr><td>';
print '<textarea class="centpercent" rows="18" readonly wrap="off">';
print dol_escape_htmltag(libeufinconnectorTutorialRenderConsoleOutput($commandOutput !== '' ? $commandOutput : $langs->trans('LibeufinConnectorTutorialConsoleEmpty')), 0, 1);
print '</textarea>';
print '</td></tr>';
print '</table>';

llxFooter();
