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
 * \file    libeufinconnector/transactions.php
 * \ingroup libeufinconnector
 * \brief   Transaction staging browser for LibEuFin Connector
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
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once __DIR__.'/lib/libeufinconnector.lib.php';
require_once __DIR__.'/lib/transactionstaging.lib.php';
require_once __DIR__.'/lib/transactionworkflow.lib.php';

if (!empty($user->socid) && $user->socid > 0) {
	accessforbidden();
}

$langs->loadLangs(array("libeufinconnector@libeufinconnector", "bills", "banks", "suppliers"));

$action = GETPOST('action', 'aZ09');
$page = max(0, GETPOSTINT('page'));
$limit = max(10, min(100, getDolGlobalInt('MAIN_SIZE_LIST_LIMIT', 25)));
$offset = $page * $limit;
$search = trim((string) GETPOST('search', 'alphanohtml'));
$searchDirection = trim((string) GETPOST('search_direction', 'aZ09'));
$searchStatus = trim((string) GETPOST('search_status', 'aZ09'));
$selectedTransactionIds = GETPOST('transaction_ids', 'array');
if (!is_array($selectedTransactionIds)) {
	$selectedTransactionIds = array();
}
$selectedTransactionIds = array_values(array_unique(array_filter(array_map('intval', $selectedTransactionIds))));

if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter', 'alpha') || GETPOST('button_removefilter.x', 'alpha')) {
	$search = '';
	$searchDirection = '';
	$searchStatus = '';
	$page = 0;
	$offset = 0;
}

$directions = LibeufinTransaction::getSupportedDirections();
$statuses = LibeufinTransaction::getSupportedStatuses();
if (!in_array($searchDirection, $directions, true)) {
	$searchDirection = '';
}
if (!in_array($searchStatus, $statuses, true)) {
	$searchStatus = '';
}

if ($action === 'import_incoming') {
	$fetchResult = libeufinconnectorRunNexusOperationNow('ebics_fetch');
	if (empty($fetchResult['ok'])) {
		$errorMessage = $fetchResult['error'] !== '' ? $fetchResult['error'] : 'ebics-fetch failed';
		setEventMessages($langs->trans('LibeufinConnectorImportIncomingFetchFailed', $errorMessage), null, 'errors');
		header('Location: '.dol_buildpath('/libeufinconnector/transactions.php?mainmenu=libeufinconnector&leftmenu=libeufinconnector_transactions', 1));
		exit;
	}

	$result = libeufinconnectorStageIncomingTransactions($db, $user);
	if (!empty($result['ok'])) {
		$autoImportResult = libeufinconnectorAutoImportMatchedIncomingTransactions($db, $user);
		if ((int) $result['total'] > 0) {
			setEventMessages($langs->trans('LibeufinConnectorImportIncomingDone', $result['total'], $result['created'], $result['updated']), null, 'mesgs');
		} else {
			setEventMessages($langs->trans('LibeufinConnectorImportIncomingEmpty'), null, 'warnings');
		}

		if ((int) $autoImportResult['total'] > 0) {
			if ((int) $autoImportResult['imported_payments'] > 0 || (int) $autoImportResult['matched_existing_bank'] > 0) {
				setEventMessages(
					$langs->trans(
						'LibeufinConnectorImportIncomingAutoImportDone',
						$autoImportResult['imported_payments'],
						$autoImportResult['matched_existing_bank']
					),
					null,
					'mesgs'
				);
			}

			if ((int) $autoImportResult['no_match'] > 0 || (int) $autoImportResult['ambiguous'] > 0) {
				setEventMessages(
					$langs->trans(
						'LibeufinConnectorImportIncomingAutoImportPending',
						$autoImportResult['no_match'],
						$autoImportResult['ambiguous']
					),
					null,
					'warnings'
				);
			}

			if ((int) $autoImportResult['errors'] > 0) {
				setEventMessages(
					$langs->trans('LibeufinConnectorImportIncomingAutoImportErrors', $autoImportResult['errors']),
					null,
					'errors'
				);
			}
		}
	} else {
		setEventMessages($langs->trans('LibeufinConnectorImportIncomingFailed', $result['error']), null, 'errors');
	}

	header('Location: '.dol_buildpath('/libeufinconnector/transactions.php?mainmenu=libeufinconnector&leftmenu=libeufinconnector_transactions', 1));
	exit;
}

if ($action === 'collect_outgoing') {
	$result = libeufinconnectorCollectOutgoingTransactions($db, $user);
	if (!empty($result['ok'])) {
		if ((int) $result['total'] > 0) {
			setEventMessages($langs->trans('LibeufinConnectorCollectOutgoingDone', $result['total'], $result['created'], $result['updated']), null, 'mesgs');
			setEventMessages($langs->trans('LibeufinConnectorCollectOutgoingBreakdown', $result['prepared_total'], $result['initiated_total'], $result['booked_total']), null, 'mesgs');
		} else {
			setEventMessages($langs->trans('LibeufinConnectorCollectOutgoingEmpty'), null, 'warnings');
		}
	} else {
		setEventMessages($langs->trans('LibeufinConnectorCollectOutgoingFailed', $result['error']), null, 'errors');
	}

	header('Location: '.dol_buildpath('/libeufinconnector/transactions.php?mainmenu=libeufinconnector&leftmenu=libeufinconnector_transactions', 1));
	exit;
}

if ($action === 'select_beneficiary_bank_account') {
	$transactionId = GETPOSTINT('select_beneficiary_transaction_id');
	$bankAccountIds = GETPOST('beneficiary_bank_account', 'array');
	$bankAccountId = 0;
	if (is_array($bankAccountIds) && !empty($bankAccountIds[$transactionId])) {
		$bankAccountId = (int) $bankAccountIds[$transactionId];
	}

	$transaction = new LibeufinTransaction($db);
	if ($transactionId <= 0 || $transaction->fetch($transactionId) <= 0) {
		setEventMessages($langs->trans('LibeufinConnectorTransactionBeneficiaryBankAccountSaveFailed', 'transaction_not_found'), null, 'errors');
	} else {
		$result = libeufinconnectorApplyOutgoingBeneficiaryBankAccount($db, $transaction, $bankAccountId, $user);
		if (!empty($result['ok'])) {
			setEventMessages($langs->trans('LibeufinConnectorTransactionBeneficiaryBankAccountSaved'), null, 'mesgs');
		} else {
			setEventMessages($langs->trans('LibeufinConnectorTransactionBeneficiaryBankAccountSaveFailed', $result['error']), null, 'errors');
		}
	}

	header('Location: '.dol_buildpath('/libeufinconnector/transactions.php?mainmenu=libeufinconnector&leftmenu=libeufinconnector_transactions', 1));
	exit;
}

if ($action === 'send_selected_outgoing' || $action === 'send_all_open_outgoing') {
	$collectResult = array('ok' => true, 'total' => 0);
	if ($action === 'send_all_open_outgoing') {
		$collectResult = libeufinconnectorCollectOutgoingTransactions($db, $user);
		if (empty($collectResult['ok']) && !empty($collectResult['error'])) {
			setEventMessages($langs->trans('LibeufinConnectorCollectOutgoingFailed', $collectResult['error']), null, 'errors');
			header('Location: '.dol_buildpath('/libeufinconnector/transactions.php?mainmenu=libeufinconnector&leftmenu=libeufinconnector_transactions', 1));
			exit;
		}
		$selectedTransactionIds = libeufinconnectorGetOpenOutgoingTransactionIds($db);
	}

	if (empty($selectedTransactionIds)) {
		setEventMessages($langs->trans('LibeufinConnectorSendOutgoingNoSelection'), null, 'warnings');
		header('Location: '.dol_buildpath('/libeufinconnector/transactions.php?mainmenu=libeufinconnector&leftmenu=libeufinconnector_transactions', 1));
		exit;
	}

	$sendResult = libeufinconnectorSendOutgoingTransactions($db, $user, $selectedTransactionIds);

	if ($action === 'send_all_open_outgoing' && !empty($collectResult['ok']) && (int) $collectResult['total'] > 0) {
		setEventMessages($langs->trans('LibeufinConnectorCollectOutgoingDone', $collectResult['total'], $collectResult['created'], $collectResult['updated']), null, 'mesgs');
		setEventMessages($langs->trans('LibeufinConnectorCollectOutgoingBreakdown', $collectResult['prepared_total'], $collectResult['initiated_total'], $collectResult['booked_total']), null, 'mesgs');
	}

	if (!empty($sendResult['error'])) {
		setEventMessages($langs->trans('LibeufinConnectorSendOutgoingFailed', $sendResult['error']), null, 'errors');
	} else {
		setEventMessages(
			$langs->trans(
				'LibeufinConnectorSendOutgoingDone',
				$sendResult['total'],
				$sendResult['initiated'],
				$sendResult['skipped'],
				$sendResult['failed']
			),
			null,
			($sendResult['failed'] > 0 ? 'warnings' : 'mesgs')
		);

		if ($sendResult['initiated'] > 0 && !empty($sendResult['submit_ok'])) {
			setEventMessages($langs->trans('LibeufinConnectorSendOutgoingSubmitDone', $sendResult['refreshed']), null, 'mesgs');
		} elseif ($sendResult['initiated'] > 0 && !empty($sendResult['submit_error'])) {
			setEventMessages($langs->trans('LibeufinConnectorSendOutgoingSubmitFailed', $sendResult['submit_error']), null, 'errors');
		}
	}

	header('Location: '.dol_buildpath('/libeufinconnector/transactions.php?mainmenu=libeufinconnector&leftmenu=libeufinconnector_transactions', 1));
	exit;
}

llxHeader('', $langs->trans('LibeufinConnectorTransactions'), '', '', 0, 0, '', '', '', 'mod-libeufinconnector page-index');

print load_fiche_titre($langs->trans('LibeufinConnectorTransactions'), '', 'bank');

if (!libeufinconnectorHasTransactionTable($db)) {
	print '<div class="warning">'.$langs->trans('LibeufinConnectorTransactionTableMissing').'</div>';
	llxFooter();
	$db->close();
	return;
}

$stats = libeufinconnectorGetTransactionStats($db);
$openOutgoingTransactionIds = libeufinconnectorGetOpenOutgoingTransactionIds($db);
$openOutgoingTransactionCount = count($openOutgoingTransactionIds);

print '<form method="POST" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'" id="libeufinconnector-transactions-actions">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="mainmenu" value="libeufinconnector">';
print '<input type="hidden" name="leftmenu" value="libeufinconnector_transactions">';
print '<input type="hidden" name="select_beneficiary_transaction_id" id="select_beneficiary_transaction_id" value="">';
print '<div class="tabsAction">';
print '<button class="butAction" type="submit" name="action" value="import_incoming">'.$langs->trans('LibeufinConnectorImportIncoming').'</button>';
print '<button class="butAction" type="submit" name="action" value="collect_outgoing">'.$langs->trans('LibeufinConnectorCollectOutgoing').'</button>';
print '<button id="libeufinconnector-send-selected" class="butActionRefused" type="submit" name="action" value="send_selected_outgoing" disabled title="'.dol_escape_htmltag($langs->trans('LibeufinConnectorSendSelectedOutgoingDisabledHelp')).'">'.$langs->trans('LibeufinConnectorSendSelectedOutgoing').'</button>';
print '<button id="libeufinconnector-send-all" class="'.($openOutgoingTransactionCount > 0 ? 'butAction' : 'butActionRefused').'" type="submit" name="action" value="send_all_open_outgoing"'.($openOutgoingTransactionCount > 0 ? '' : ' disabled').' title="'.dol_escape_htmltag($openOutgoingTransactionCount > 0 ? $langs->trans('LibeufinConnectorSendAllOpenOutgoingHelp', $openOutgoingTransactionCount) : $langs->trans('LibeufinConnectorSendOutgoingDisabledNoOpen')).'">'.$langs->trans('LibeufinConnectorSendAllOpenOutgoing').'</button>';
print '</div>';
print '</form>';

$where = array("t.entity = ".((int) $conf->entity));
if ($searchDirection !== '') {
	$where[] = "t.direction = '".$db->escape($searchDirection)."'";
}
if ($searchStatus !== '') {
	$where[] = "t.transaction_status = '".$db->escape($searchStatus)."'";
}
if ($search !== '') {
	$searchSql = '%'.$db->escape($db->escapeforlike($search)).'%';
	$where[] = "("
		."t.external_transaction_id LIKE '".$searchSql."'"
		." OR t.external_message_id LIKE '".$searchSql."'"
		." OR t.external_order_id LIKE '".$searchSql."'"
		." OR t.counterparty_name LIKE '".$searchSql."'"
		." OR t.counterparty_iban LIKE '".$searchSql."'"
		." OR t.counterparty_bic LIKE '".$searchSql."'"
		." OR t.dedupe_key LIKE '".$searchSql."'"
	.")";
}
$whereSql = implode(' AND ', $where);

$total = 0;
$sqlCount = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."libeufinconnector_transaction as t WHERE ".$whereSql;
$resqlCount = $db->query($sqlCount);
if ($resqlCount) {
	$objCount = $db->fetch_object($resqlCount);
	$total = ($objCount ? (int) $objCount->nb : 0);
	$db->free($resqlCount);
}

$rows = array();
$sql = "SELECT t.rowid, t.transaction_date, t.datec, t.direction, t.transaction_status, t.amount, t.currency,";
$sql .= " t.counterparty_name, t.counterparty_iban, t.counterparty_bic,";
$sql .= " t.external_transaction_id, t.external_message_id, t.external_order_id,";
$sql .= " t.fk_bank, t.fk_paiement, t.fk_paiementfourn, t.fk_facture, t.fk_facture_fourn, t.fk_prelevement_bons, t.raw_payload";
$sql .= " FROM ".MAIN_DB_PREFIX."libeufinconnector_transaction as t";
$sql .= " WHERE ".$whereSql;
$sql .= " ORDER BY t.transaction_date DESC, t.rowid DESC";
$sql .= $db->plimit($limit, $offset);
$resql = $db->query($sql);
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$rows[] = $obj;
	}
	$db->free($resql);
}

$start = ($total > 0 ? $offset + 1 : 0);
$end = min($offset + $limit, $total);
$baseUrl = $_SERVER["PHP_SELF"];
$queryParts = array();
if ($search !== '') {
	$queryParts[] = 'search='.urlencode($search);
}
if ($searchDirection !== '') {
	$queryParts[] = 'search_direction='.urlencode($searchDirection);
}
if ($searchStatus !== '') {
	$queryParts[] = 'search_status='.urlencode($searchStatus);
}
$baseQuery = ($queryParts ? '&'.implode('&', $queryParts) : '');

print '<form method="GET" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'">';
print '<input type="hidden" name="mainmenu" value="libeufinconnector">';
print '<input type="hidden" name="leftmenu" value="libeufinconnector_transactions">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('LibeufinConnectorTransactionFilterDirection').'</td>';
print '<td>'.$langs->trans('Status').'</td>';
print '<td>'.$langs->trans('LibeufinConnectorTransactionFilterSearch').'</td>';
print '<td class="right">'.$langs->trans('Action').'</td>';
print '</tr>';
print '<tr class="oddeven">';
print '<td><select class="flat minwidth150" name="search_direction">';
print '<option value=""></option>';
foreach ($directions as $direction) {
	print '<option value="'.dol_escape_htmltag($direction).'"'.($searchDirection === $direction ? ' selected' : '').'>'.$langs->trans('LibeufinConnectorTransactionDirection'.ucfirst($direction)).'</option>';
}
print '</select></td>';
print '<td><select class="flat minwidth150" name="search_status">';
print '<option value=""></option>';
foreach ($statuses as $status) {
	print '<option value="'.dol_escape_htmltag($status).'"'.($searchStatus === $status ? ' selected' : '').'>'.$langs->trans('LibeufinConnectorTransactionStatus'.ucfirst($status)).'</option>';
}
print '</select></td>';
print '<td><input type="text" class="flat minwidth300" name="search" value="'.dol_escape_htmltag($search).'" placeholder="'.dol_escape_htmltag($langs->trans('LibeufinConnectorTransactionFilterSearchHelp')).'"></td>';
print '<td class="right">';
print '<div class="nowraponall">';
print '<button type="submit" class="liste_titre button_search reposition" name="button_search_x" value="x"><span class="fas fa-search"></span></button>';
print '<button type="submit" class="liste_titre button_removefilter reposition" name="button_removefilter_x" value="x"><span class="fas fa-times"></span></button>';
print '</div>';
print '</td>';
print '</tr>';
print '</table>';
print '</form>';
print '<div class="opacitymedium">'.$langs->trans('LibeufinConnectorTransactionShowing', $start, $end, $total).'</div><br>';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td class="center" width="30"><input type="checkbox" id="checkalltransactions"></td>';
print '<td>'.$langs->trans('Date').'</td>';
print '<td>'.$langs->trans('LibeufinConnectorTransactionFilterDirection').'</td>';
print '<td>'.$langs->trans('Status').'</td>';
print '<td class="right">'.$langs->trans('Amount').'</td>';
print '<td>'.$langs->trans('LibeufinConnectorTransactionCounterparty').'</td>';
print '<td>'.$langs->trans('LibeufinConnectorTransactionExternalIds').'</td>';
print '<td>'.$langs->trans('LibeufinConnectorTransactionLinks').'</td>';
print '</tr>';

if (empty($rows)) {
	print '<tr class="oddeven"><td colspan="8" class="opacitymedium">'.$langs->trans('LibeufinConnectorTransactionNoData').'</td></tr>';
} else {
	foreach ($rows as $row) {
		$dateValue = !empty($row->transaction_date) ? $row->transaction_date : $row->datec;
		$links = array();

		$externalIds = array();
		if (!empty($row->external_transaction_id)) {
			$externalIds[] = 'tx: '.$row->external_transaction_id;
		}
		if (!empty($row->external_message_id)) {
			$externalIds[] = 'msg: '.$row->external_message_id;
		}
		if (!empty($row->external_order_id)) {
			$externalIds[] = 'order: '.$row->external_order_id;
		}

		$counterparty = array();
		if (!empty($row->counterparty_name)) {
			$counterparty[] = $row->counterparty_name;
		}
		if (!empty($row->counterparty_iban)) {
			$counterparty[] = $row->counterparty_iban;
		}
		if (!empty($row->counterparty_bic)) {
			$counterparty[] = $row->counterparty_bic;
		}

		$detailUrl = dol_buildpath('/libeufinconnector/transaction_card.php?id='.((int) $row->rowid).'&mainmenu=libeufinconnector&leftmenu=libeufinconnector_transactions', 1);
		$rowPayload = json_decode((string) $row->raw_payload, true);
		$rowPayload = is_array($rowPayload) ? $rowPayload : array();
		$rowNexusPayload = (isset($rowPayload['nexus']) && is_array($rowPayload['nexus'])) ? $rowPayload['nexus'] : array();
		$rowSubject = '';
		if (!empty($rowPayload['subject'])) {
			$rowSubject = trim((string) $rowPayload['subject']);
		} elseif (!empty($rowNexusPayload['subject'])) {
			$rowSubject = trim((string) $rowNexusPayload['subject']);
		}
		if ($rowSubject !== '') {
			$externalIds[] = 'ref: '.$rowSubject;
		}
		$outgoingValidation = libeufinconnectorGetOutgoingValidationFromPayloadArray($rowPayload);
		$outgoingSource = !empty($rowPayload['source']) ? (string) $rowPayload['source'] : '';
		$outgoingSocid = libeufinconnectorGetOutgoingPayloadSocid($rowPayload);
		$beneficiaryBankAccounts = array('ok' => true, 'error' => '', 'rows' => array());
		if ($row->direction === LibeufinTransaction::DIRECTION_OUTGOING && $outgoingSocid > 0 && in_array($outgoingSource, array('dolibarr_prepared_outgoing', 'dolibarr_supplier_payment', 'dolibarr_customer_refund'), true)) {
			$beneficiaryBankAccounts = libeufinconnectorReadThirdpartyBankAccounts($db, $outgoingSocid);
		}
		$dolibarrPayload = (isset($rowPayload['dolibarr']) && is_array($rowPayload['dolibarr'])) ? $rowPayload['dolibarr'] : array();
		$currentBeneficiaryBankAccountId = 0;
		if (!empty($rowPayload['beneficiary_bank_account']) && is_array($rowPayload['beneficiary_bank_account']) && !empty($rowPayload['beneficiary_bank_account']['rowid'])) {
			$currentBeneficiaryBankAccountId = (int) $rowPayload['beneficiary_bank_account']['rowid'];
		} elseif (!empty($dolibarrPayload['fk_societe_rib'])) {
			$currentBeneficiaryBankAccountId = (int) $dolibarrPayload['fk_societe_rib'];
		}
		if ((int) $row->fk_bank > 0) {
			$bankUrl = dol_buildpath('/compta/bank/line.php?rowid='.((int) $row->fk_bank), 1);
			$links[] = '<a class="nowraponall" href="'.$bankUrl.'">'.img_object($langs->trans('Bank'), 'payment').' '.$langs->trans('Bank').' #'.((int) $row->fk_bank).'</a>';
		}
		if ((int) $row->fk_paiement > 0) {
			$paymentUrl = dol_buildpath('/compta/paiement/card.php?id='.((int) $row->fk_paiement), 1);
			$links[] = '<a class="nowraponall" href="'.$paymentUrl.'">'.img_object($langs->trans('Payment'), 'payment').' '.$langs->trans('Payment').' #'.((int) $row->fk_paiement).'</a>';
		}
		if ((int) $row->fk_paiementfourn > 0) {
			$supplierPaymentUrl = dol_buildpath('/fourn/paiement/card.php?id='.((int) $row->fk_paiementfourn), 1);
			$links[] = '<a class="nowraponall" href="'.$supplierPaymentUrl.'">'.img_object($langs->trans('SupplierPayment'), 'payment').' '.$langs->trans('SupplierPayment').' #'.((int) $row->fk_paiementfourn).'</a>';
		}
		if ((int) $row->fk_facture > 0) {
			$invoiceUrl = dol_buildpath('/compta/facture/card.php?id='.((int) $row->fk_facture), 1);
			$links[] = '<a class="nowraponall" href="'.$invoiceUrl.'">'.img_object($langs->trans('Invoice'), 'bill').' '.$langs->trans('Invoice').' #'.((int) $row->fk_facture).'</a>';
		}
		if ((int) $row->fk_facture_fourn > 0) {
			$supplierInvoiceUrl = dol_buildpath('/fourn/facture/card.php?facid='.((int) $row->fk_facture_fourn), 1);
			$links[] = '<a class="nowraponall" href="'.$supplierInvoiceUrl.'">'.img_object($langs->trans('SupplierInvoice'), 'bill').' '.$langs->trans('SupplierInvoice').' #'.((int) $row->fk_facture_fourn).'</a>';
		}
		if ((int) $row->fk_prelevement_bons > 0) {
			$transferOrderUrl = dol_buildpath('/compta/prelevement/card.php?id='.((int) $row->fk_prelevement_bons), 1);
			$links[] = '<a class="nowraponall" href="'.$transferOrderUrl.'">'.img_object($langs->trans('Payment'), 'payment').' '.$langs->trans('LibeufinConnectorTransactionTransferOrder').' #'.((int) $row->fk_prelevement_bons).'</a>';
		}
		$selectable = (
			$row->direction === LibeufinTransaction::DIRECTION_OUTGOING
			&& in_array($row->transaction_status, array(LibeufinTransaction::STATUS_NEW, LibeufinTransaction::STATUS_FAILED), true)
			&& in_array($outgoingSource, array('dolibarr_prepared_outgoing', 'dolibarr_supplier_payment', 'dolibarr_customer_refund'), true)
			&& !empty($outgoingValidation['ready'])
		);

		print '<tr class="oddeven">';
		print '<td class="center">';
		if ($selectable) {
			print '<input class="flat checktransaction" type="checkbox" form="libeufinconnector-transactions-actions" name="transaction_ids[]" value="'.((int) $row->rowid).'">';
		} else {
			print '&nbsp;';
		}
		print '</td>';
		print '<td><a href="'.$detailUrl.'">'.dol_print_date($db->jdate($dateValue), 'dayhour').'</a><br><span class="opacitymedium">#'.((int) $row->rowid).'</span></td>';
		print '<td>'.dol_escape_htmltag($langs->trans('LibeufinConnectorTransactionDirection'.ucfirst($row->direction))).'</td>';
		print '<td>'.dol_escape_htmltag($langs->trans('LibeufinConnectorTransactionStatus'.ucfirst($row->transaction_status))).'</td>';
		print '<td class="right">'.price((float) $row->amount).' '.dol_escape_htmltag($row->currency).'</td>';
		print '<td>';
		print dol_escape_htmltag(!empty($counterparty) ? implode(' | ', $counterparty) : '-');
		if ($row->direction === LibeufinTransaction::DIRECTION_OUTGOING && in_array($outgoingSource, array('dolibarr_prepared_outgoing', 'dolibarr_supplier_payment', 'dolibarr_customer_refund'), true)) {
			print '<br>';
			if (empty($beneficiaryBankAccounts['ok'])) {
				print '<span class="error">'.dol_escape_htmltag($beneficiaryBankAccounts['error']).'</span>';
			} elseif (empty($beneficiaryBankAccounts['rows'])) {
				print '<select class="flat maxwidth200" disabled><option>'.$langs->trans('LibeufinConnectorTransactionNoBeneficiaryBankAccountAvailable').'</option></select>';
				if ($outgoingSocid > 0) {
					$paymentModesUrl = dol_buildpath('/societe/paymentmodes.php?socid='.$outgoingSocid, 1);
					print ' <a class="button small" href="'.$paymentModesUrl.'">'.$langs->trans('LibeufinConnectorTransactionOpenThirdPartyPaymentModes').'</a>';
				}
			} elseif ($currentBeneficiaryBankAccountId > 0) {
				$currentAccountLabel = '#'.$currentBeneficiaryBankAccountId;
				foreach ($beneficiaryBankAccounts['rows'] as $bankAccount) {
					if ((int) $bankAccount['rowid'] !== $currentBeneficiaryBankAccountId) {
						continue;
					}
					$labelParts = array();
					$labelParts[] = !empty($bankAccount['label']) ? trim((string) $bankAccount['label']) : '#'.$currentBeneficiaryBankAccountId;
					if (!empty($bankAccount['default_rib'])) {
						$labelParts[] = $langs->trans('Default');
					}
					$currentAccountLabel = implode(' - ', $labelParts);
					break;
				}
				print '<span class="nowraponall">'.dol_escape_htmltag($currentAccountLabel).'</span>';
			} else {
				print '<select class="flat maxwidth200" form="libeufinconnector-transactions-actions" name="beneficiary_bank_account['.((int) $row->rowid).']">';
				foreach ($beneficiaryBankAccounts['rows'] as $bankAccount) {
					$accountId = (int) $bankAccount['rowid'];
					$labelParts = array();
					$labelParts[] = !empty($bankAccount['label']) ? trim((string) $bankAccount['label']) : '#'.$accountId;
					if (!empty($bankAccount['iban_prefix'])) {
						$labelParts[] = strtoupper(str_replace(' ', '', trim((string) $bankAccount['iban_prefix'])));
					} elseif (!empty($bankAccount['number'])) {
						$labelParts[] = trim((string) $bankAccount['number']);
					}
					if (!empty($bankAccount['default_rib'])) {
						$labelParts[] = $langs->trans('Default');
					}
					print '<option value="'.$accountId.'"'.($accountId === $currentBeneficiaryBankAccountId ? ' selected' : '').'>'.dol_escape_htmltag(implode(' - ', $labelParts)).'</option>';
				}
				print '</select> ';
				print '<button class="button small" type="submit" form="libeufinconnector-transactions-actions" name="action" value="select_beneficiary_bank_account" onclick="document.getElementById(\'select_beneficiary_transaction_id\').value=\''.((int) $row->rowid).'\';">'.$langs->trans('LibeufinConnectorTransactionSaveBeneficiaryBankAccount').'</button>';
			}
		}
		if ($row->direction === LibeufinTransaction::DIRECTION_OUTGOING && empty($outgoingValidation['ready'])) {
			print '<br><span class="opacitymedium">'.$langs->trans('LibeufinConnectorTransactionOutgoingMissingDetailsSummary').'</span>';
		}
		print '</td>';
		print '<td>'.dol_escape_htmltag(!empty($externalIds) ? implode(' | ', $externalIds) : '-').'</td>';
		print '<td>'.(!empty($links) ? implode(' | ', $links) : '-').'</td>';
		print '</tr>';
	}
}
print '</table><br>';

print '<div class="tabsAction">';
if ($page > 0) {
	print '<a class="butAction" href="'.$baseUrl.'?mainmenu=libeufinconnector&leftmenu=libeufinconnector_transactions&page='.($page - 1).$baseQuery.'">'.$langs->trans('LibeufinConnectorPrevious').'</a>';
}
if ($end < $total) {
	print '<a class="butAction" href="'.$baseUrl.'?mainmenu=libeufinconnector&leftmenu=libeufinconnector_transactions&page='.($page + 1).$baseQuery.'">'.$langs->trans('LibeufinConnectorNext').'</a>';
}
print '</div>';
print '<script>
document.addEventListener("DOMContentLoaded", function () {
	var checkAll = document.getElementById("checkalltransactions");
	var sendSelected = document.getElementById("libeufinconnector-send-selected");
	function updateSendSelectedState() {
		var checkboxes = document.querySelectorAll(".checktransaction");
		var checked = false;
		var checkedCount = 0;
		for (var i = 0; i < checkboxes.length; i++) {
			if (checkboxes[i].checked) {
				checked = true;
				checkedCount++;
			}
		}
		if (sendSelected) {
			sendSelected.disabled = !checked;
			sendSelected.className = checked ? "butAction" : "butActionRefused";
		}
		if (checkAll) {
			checkAll.checked = checkedCount > 0 && checkedCount === checkboxes.length;
			checkAll.indeterminate = checkedCount > 0 && checkedCount < checkboxes.length;
		}
	}
	if (checkAll) {
		checkAll.addEventListener("change", function () {
			var checkboxes = document.querySelectorAll(".checktransaction");
			for (var i = 0; i < checkboxes.length; i++) {
				checkboxes[i].checked = checkAll.checked;
			}
			updateSendSelectedState();
		});
	}
	var checkboxes = document.querySelectorAll(".checktransaction");
	for (var i = 0; i < checkboxes.length; i++) {
		checkboxes[i].addEventListener("change", updateSendSelectedState);
	}
	updateSendSelectedState();
});
</script>';

llxFooter();
$db->close();
