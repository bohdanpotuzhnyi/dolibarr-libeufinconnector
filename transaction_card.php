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
 * \file    libeufinconnector/transaction_card.php
 * \ingroup libeufinconnector
 * \brief   Detail page for one staged LibEuFin transaction
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

require_once __DIR__.'/class/libeufintransaction.class.php';
require_once __DIR__.'/lib/libeufinconnector.lib.php';
require_once __DIR__.'/lib/transactionstaging.lib.php';
require_once __DIR__.'/lib/transactionworkflow.lib.php';

if (!empty($user->socid) && $user->socid > 0) {
	accessforbidden();
}

$langs->loadLangs(array("libeufinconnector@libeufinconnector", "bills", "banks", "suppliers"));

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');

if ($id <= 0) {
	accessforbidden();
}

$object = new LibeufinTransaction($db);
if ($object->fetch($id) <= 0) {
	accessforbidden();
}

$baseUrl = dol_buildpath('/libeufinconnector/transaction_card.php?id='.$object->id.'&mainmenu=libeufinconnector&leftmenu=libeufinconnector_transactions', 1);

if ($action === 'create_bank_line') {
	$result = libeufinconnectorCreateIncomingBankLine($db, $object, $user);
	if (!empty($result['ok'])) {
		setEventMessages($langs->trans('LibeufinConnectorTransactionBankLineCreated', $result['bank_id']), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('LibeufinConnectorTransactionActionFailed', $result['error']), null, 'errors');
	}

	header('Location: '.$baseUrl);
	exit;
}

if ($action === 'match_invoice') {
	$result = libeufinconnectorApplyIncomingInvoiceMatch($db, $object, $user);
	if (!empty($result['ok'])) {
		if (!empty($result['supplier_invoice_id'])) {
			if (!empty($result['payment_id'])) {
				setEventMessages($langs->trans('LibeufinConnectorTransactionSupplierPaymentImported', $result['payment_id'], $result['supplier_invoice_id']), null, 'mesgs');
			} else {
				setEventMessages($langs->trans('LibeufinConnectorTransactionSupplierInvoiceMatched', $result['supplier_invoice_id']), null, 'mesgs');
			}
		} elseif (!empty($result['payment_id'])) {
			setEventMessages($langs->trans('LibeufinConnectorTransactionPaymentImported', $result['payment_id'], $result['invoice_id']), null, 'mesgs');
		} else {
			setEventMessages($langs->trans('LibeufinConnectorTransactionInvoiceMatched', $result['invoice_id']), null, 'mesgs');
		}
	} else {
		if ($result['status'] === 'no_match') {
			setEventMessages($langs->trans('LibeufinConnectorTransactionNoExactMatch'), null, 'warnings');
		} elseif ($result['status'] === 'ambiguous') {
			setEventMessages($langs->trans('LibeufinConnectorTransactionAmbiguousExactMatch'), null, 'warnings');
		} else {
			setEventMessages($langs->trans('LibeufinConnectorTransactionActionFailed', $result['error']), null, 'errors');
		}
	}

	header('Location: '.$baseUrl);
	exit;
}

if ($action === 'import_linked_invoice_payment') {
	if ($object->direction !== LibeufinTransaction::DIRECTION_INCOMING) {
		$result = array('ok' => false, 'error' => 'Only incoming transactions can import linked invoice payments.');
	} elseif ((int) $object->fk_facture > 0) {
		$result = libeufinconnectorCreateIncomingCustomerPayment($db, $object, (int) $object->fk_facture, $user);
	} elseif ((int) $object->fk_facture_fourn > 0) {
		$result = libeufinconnectorCreateIncomingSupplierRefundPayment($db, $object, (int) $object->fk_facture_fourn, $user);
	} else {
		$result = array('ok' => false, 'error' => 'This transaction is not linked to an invoice or supplier credit note.');
	}

	if (!empty($result['ok'])) {
		if ((int) $object->fk_facture_fourn > 0) {
			setEventMessages($langs->trans('LibeufinConnectorTransactionSupplierPaymentImported', $result['payment_id'], $result['invoice_id']), null, 'mesgs');
		} else {
			setEventMessages($langs->trans('LibeufinConnectorTransactionPaymentImported', $result['payment_id'], $result['invoice_id']), null, 'mesgs');
		}
	} else {
		setEventMessages($langs->trans('LibeufinConnectorTransactionActionFailed', $result['error']), null, 'errors');
	}

	header('Location: '.$baseUrl);
	exit;
}

if ($action === 'select_beneficiary_bank_account') {
	$result = libeufinconnectorApplyOutgoingBeneficiaryBankAccount($db, $object, GETPOSTINT('fk_societe_rib'), $user);
	if (!empty($result['ok'])) {
		setEventMessages($langs->trans('LibeufinConnectorTransactionBeneficiaryBankAccountSaved'), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('LibeufinConnectorTransactionBeneficiaryBankAccountSaveFailed', $result['error']), null, 'errors');
	}

	header('Location: '.$baseUrl);
	exit;
}

if ($action === 'manual_link') {
	$result = libeufinconnectorApplyManualTransactionLink($db, $object, GETPOST('manual_link_case', 'aZ09'), GETPOST('manual_link_target', 'restricthtml'), $user);
	if (!empty($result['ok'])) {
		if (!empty($result['fk_paiementfourn']) && !empty($result['fk_facture_fourn'])) {
			setEventMessages($langs->trans('LibeufinConnectorTransactionSupplierPaymentImported', $result['fk_paiementfourn'], $result['fk_facture_fourn']), null, 'mesgs');
		} elseif (!empty($result['fk_paiement']) && !empty($result['fk_facture'])) {
			setEventMessages($langs->trans('LibeufinConnectorTransactionPaymentImported', $result['fk_paiement'], $result['fk_facture']), null, 'mesgs');
		} elseif (!empty($result['fk_bank'])) {
			setEventMessages($langs->trans('LibeufinConnectorTransactionBankLineCreated', $result['fk_bank']), null, 'mesgs');
		} else {
			setEventMessages($langs->trans('LibeufinConnectorTransactionManualLinkSaved'), null, 'mesgs');
		}
	} else {
		setEventMessages($langs->trans('LibeufinConnectorTransactionManualLinkFailed', $result['error']), null, 'errors');
	}

	header('Location: '.$baseUrl);
	exit;
}

$payload = libeufinconnectorGetTransactionPayloadArray($object);
$nexusPayload = (isset($payload['nexus']) && is_array($payload['nexus'])) ? $payload['nexus'] : array();
$outgoingValidation = libeufinconnectorGetOutgoingTransactionValidation($object);
$outgoingValidationMessages = array();
foreach ($outgoingValidation['issues'] as $issue) {
	$key = '';
	if ($issue === 'missing_bank_account') {
		$key = 'LibeufinConnectorTransactionOutgoingIssueMissingBankAccount';
	} elseif ($issue === 'missing_counterparty_name') {
		$key = 'LibeufinConnectorTransactionOutgoingIssueMissingName';
	} elseif ($issue === 'missing_counterparty_iban') {
		$key = 'LibeufinConnectorTransactionOutgoingIssueMissingIban';
	}
	if ($key !== '') {
		$outgoingValidationMessages[] = $langs->trans($key);
	}
}
$displaySubject = '';
if (!empty($payload['subject'])) {
	$displaySubject = (string) $payload['subject'];
} elseif (!empty($nexusPayload['subject'])) {
	$displaySubject = (string) $nexusPayload['subject'];
}
$displayPayto = '';
if (!empty($payload['payto'])) {
	$displayPayto = (string) $payload['payto'];
} elseif (!empty($nexusPayload['debit_payto'])) {
	$displayPayto = (string) $nexusPayload['debit_payto'];
} elseif (!empty($nexusPayload['credit_payto'])) {
	$displayPayto = (string) $nexusPayload['credit_payto'];
}
$dolibarrPayload = (isset($payload['dolibarr']) && is_array($payload['dolibarr'])) ? $payload['dolibarr'] : array();
$outgoingSocid = ($object->direction === LibeufinTransaction::DIRECTION_OUTGOING) ? libeufinconnectorGetOutgoingPayloadSocid($payload) : 0;
$canEditOutgoingBeneficiary = ($object->direction === LibeufinTransaction::DIRECTION_OUTGOING) ? libeufinconnectorCanEditOutgoingTransactionBeneficiary($object) : false;
$beneficiaryBankAccounts = array('ok' => true, 'error' => '', 'rows' => array());
if ($outgoingSocid > 0) {
	$beneficiaryBankAccounts = libeufinconnectorReadThirdpartyBankAccounts($db, $outgoingSocid);
}
$currentBeneficiaryBankAccountId = 0;
if (!empty($payload['beneficiary_bank_account']) && is_array($payload['beneficiary_bank_account']) && !empty($payload['beneficiary_bank_account']['rowid'])) {
	$currentBeneficiaryBankAccountId = (int) $payload['beneficiary_bank_account']['rowid'];
} elseif (!empty($dolibarrPayload['fk_societe_rib'])) {
	$currentBeneficiaryBankAccountId = (int) $dolibarrPayload['fk_societe_rib'];
}
$bankLineLink = libeufinconnectorGetDolibarrObjectNomUrl($db, 'bank', (int) $object->fk_bank, 0);
$paymentLink = libeufinconnectorGetDolibarrObjectNomUrl($db, 'payment', (int) $object->fk_paiement, 0);
$invoiceLink = libeufinconnectorGetDolibarrObjectNomUrl($db, 'invoice', (int) $object->fk_facture, 0);
$supplierInvoiceLink = libeufinconnectorGetDolibarrObjectNomUrl($db, 'supplier_invoice', (int) $object->fk_facture_fourn, 0);
$supplierPaymentLink = libeufinconnectorGetDolibarrObjectNomUrl($db, 'supplier_payment', (int) $object->fk_paiementfourn, 0);
$backToList = dol_buildpath('/libeufinconnector/transactions.php?mainmenu=libeufinconnector&leftmenu=libeufinconnector_transactions', 1);
$canManuallyLink = libeufinconnectorCanManuallyLinkTransaction($object);
$manualLinkCandidates = ($canManuallyLink ? libeufinconnectorReadManualLinkCandidates($db, $object) : array('invoices' => array(), 'payments' => array(), 'banks' => array()));

llxHeader('', $langs->trans('LibeufinConnectorTransaction'), '', '', 0, 0, '', '', '', 'mod-libeufinconnector page-index');

print load_fiche_titre($langs->trans('LibeufinConnectorTransaction').' #'.((int) $object->id), '<a href="'.$backToList.'">'.$langs->trans('BackToList').'</a>', 'bank');
print '<span class="opacitymedium">'.$langs->trans('LibeufinConnectorTransactionDetailPage').'</span><br><br>';

print '<div class="tabsAction">';
if ($object->direction === LibeufinTransaction::DIRECTION_INCOMING && (int) $object->fk_bank <= 0) {
	print '<form class="inline-block" method="POST" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="id" value="'.((int) $object->id).'">';
	print '<input type="hidden" name="mainmenu" value="libeufinconnector">';
	print '<input type="hidden" name="leftmenu" value="libeufinconnector_transactions">';
	print '<input type="hidden" name="action" value="create_bank_line">';
	print '<input class="butAction" type="submit" value="'.$langs->trans('LibeufinConnectorTransactionCreateBankLine').'">';
	print '</form>';
	print '<form class="inline-block" method="POST" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="id" value="'.((int) $object->id).'">';
	print '<input type="hidden" name="mainmenu" value="libeufinconnector">';
	print '<input type="hidden" name="leftmenu" value="libeufinconnector_transactions">';
	print '<input type="hidden" name="action" value="match_invoice">';
	print '<input class="butAction" type="submit" value="'.$langs->trans('LibeufinConnectorTransactionImportMatchedPayment').'">';
	print '</form>';
}
if ($object->direction === LibeufinTransaction::DIRECTION_INCOMING && (int) $object->fk_bank > 0 && (int) $object->fk_facture <= 0 && (int) $object->fk_paiement <= 0) {
	print '<form class="inline-block" method="POST" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="id" value="'.((int) $object->id).'">';
	print '<input type="hidden" name="mainmenu" value="libeufinconnector">';
	print '<input type="hidden" name="leftmenu" value="libeufinconnector_transactions">';
	print '<input type="hidden" name="action" value="match_invoice">';
	print '<input class="butAction" type="submit" value="'.((int) $object->fk_bank > 0 ? $langs->trans('LibeufinConnectorTransactionRunExactMatch') : $langs->trans('LibeufinConnectorTransactionImportMatchedPayment')).'">';
	print '</form>';
}
if ($object->direction === LibeufinTransaction::DIRECTION_INCOMING && (int) $object->fk_bank <= 0 && (int) $object->fk_facture > 0 && (int) $object->fk_paiement <= 0) {
	print '<form class="inline-block" method="POST" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="id" value="'.((int) $object->id).'">';
	print '<input type="hidden" name="mainmenu" value="libeufinconnector">';
	print '<input type="hidden" name="leftmenu" value="libeufinconnector_transactions">';
	print '<input type="hidden" name="action" value="import_linked_invoice_payment">';
	print '<input class="butAction" type="submit" value="'.$langs->trans('LibeufinConnectorTransactionImportMatchedPayment').'">';
	print '</form>';
}
if ($object->direction === LibeufinTransaction::DIRECTION_INCOMING && (int) $object->fk_facture_fourn > 0 && (int) $object->fk_paiementfourn <= 0) {
	print '<form class="inline-block" method="POST" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="id" value="'.((int) $object->id).'">';
	print '<input type="hidden" name="mainmenu" value="libeufinconnector">';
	print '<input type="hidden" name="leftmenu" value="libeufinconnector_transactions">';
	print '<input type="hidden" name="action" value="import_linked_invoice_payment">';
	print '<input class="butAction" type="submit" value="'.$langs->trans('LibeufinConnectorTransactionImportMatchedPayment').'">';
	print '</form>';
}
print '</div>';

print '<table class="border centpercent">';
print '<tr><td class="titlefield">'.$langs->trans('Ref').'</td><td>#'.((int) $object->id).'</td></tr>';
print '<tr><td>'.$langs->trans('LibeufinConnectorTransactionFilterDirection').'</td><td>'.dol_escape_htmltag($langs->trans('LibeufinConnectorTransactionDirection'.ucfirst($object->direction))).'</td></tr>';
print '<tr><td>'.$langs->trans('Status').'</td><td>'.dol_escape_htmltag($langs->trans('LibeufinConnectorTransactionStatus'.ucfirst($object->transaction_status))).'</td></tr>';
print '<tr><td>'.$langs->trans('Amount').'</td><td>'.price((float) $object->amount).' '.dol_escape_htmltag($object->currency).'</td></tr>';
print '<tr><td>'.$langs->trans('Date').'</td><td>'.(!empty($object->transaction_date) ? dol_print_date($db->jdate($object->transaction_date), 'dayhour') : '').'</td></tr>';
print '<tr><td>'.$langs->trans('LibeufinConnectorTransactionCounterparty').'</td><td>'.dol_escape_htmltag(trim($object->counterparty_name.' '.$object->counterparty_iban.' '.$object->counterparty_bic)).'</td></tr>';
if ($object->direction === LibeufinTransaction::DIRECTION_OUTGOING) {
	print '<tr><td>'.$langs->trans('LibeufinConnectorTransactionOutgoingReadiness').'</td><td>';
	if (!empty($outgoingValidation['ready'])) {
		print $langs->trans('LibeufinConnectorTransactionOutgoingReady');
	} else {
		print $langs->trans('LibeufinConnectorTransactionOutgoingMissingDetailsSummary');
	}
	print '</td></tr>';
	print '<tr><td>'.$langs->trans('LibeufinConnectorTransactionOutgoingBankAccount').'</td><td>';
	if (empty($beneficiaryBankAccounts['ok'])) {
		print '<span class="error">'.dol_escape_htmltag($beneficiaryBankAccounts['error']).'</span>';
	} elseif (empty($beneficiaryBankAccounts['rows'])) {
		print '<select class="flat minwidth300" disabled><option>'.$langs->trans('LibeufinConnectorTransactionNoBeneficiaryBankAccountAvailable').'</option></select>';
		if ($outgoingSocid > 0) {
			$paymentModesUrl = dol_buildpath('/societe/paymentmodes.php?socid='.$outgoingSocid, 1);
			print ' <a class="button" href="'.$paymentModesUrl.'">'.$langs->trans('LibeufinConnectorTransactionOpenThirdPartyPaymentModes').'</a>';
		}
	} elseif (!$canEditOutgoingBeneficiary) {
		$currentAccountLabel = ($currentBeneficiaryBankAccountId > 0 ? '#'.$currentBeneficiaryBankAccountId : $langs->trans('LibeufinConnectorTransactionNotLinked'));
		foreach ($beneficiaryBankAccounts['rows'] as $bankAccount) {
			if ((int) $bankAccount['rowid'] !== $currentBeneficiaryBankAccountId) {
				continue;
			}
			$labelParts = array();
			$labelParts[] = !empty($bankAccount['label']) ? trim((string) $bankAccount['label']) : '#'.$currentBeneficiaryBankAccountId;
			if (!empty($bankAccount['iban_prefix'])) {
				$labelParts[] = strtoupper(str_replace(' ', '', trim((string) $bankAccount['iban_prefix'])));
			} elseif (!empty($bankAccount['number'])) {
				$labelParts[] = trim((string) $bankAccount['number']);
			}
			if (!empty($bankAccount['default_rib'])) {
				$labelParts[] = $langs->trans('Default');
			}
			$currentAccountLabel = implode(' - ', $labelParts);
			break;
		}
		print '<span class="nowraponall">'.dol_escape_htmltag($currentAccountLabel).'</span>';
	} else {
		print '<form class="inline-block" method="POST" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="id" value="'.((int) $object->id).'">';
		print '<input type="hidden" name="mainmenu" value="libeufinconnector">';
		print '<input type="hidden" name="leftmenu" value="libeufinconnector_transactions">';
		print '<input type="hidden" name="action" value="select_beneficiary_bank_account">';
		print '<select class="flat minwidth300" name="fk_societe_rib">';
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
		print '<input class="button" type="submit" value="'.$langs->trans('LibeufinConnectorTransactionSaveBeneficiaryBankAccount').'">';
		print '</form>';
	}
	print '</td></tr>';
}
print '<tr><td>'.$langs->trans('LibeufinConnectorTransactionExternalIds').'</td><td>';
print '<div>tx: '.dol_escape_htmltag((string) $object->external_transaction_id).'</div>';
print '<div>msg: '.dol_escape_htmltag((string) $object->external_message_id).'</div>';
print '<div>order: '.dol_escape_htmltag((string) $object->external_order_id).'</div>';
if ($displaySubject !== '') {
	print '<div>ref: '.dol_escape_htmltag($displaySubject).'</div>';
}
print '</td></tr>';
print '<tr><td>'.$langs->trans('LibeufinConnectorTransactionLinkedBankLine').'</td><td>';
if ($bankLineLink !== '') {
	print $bankLineLink;
} else {
	print '<span class="opacitymedium">'.$langs->trans('LibeufinConnectorTransactionNotLinked').'</span>';
}
print '</td></tr>';
print '<tr><td>'.$langs->trans('LibeufinConnectorTransactionLinkedCustomerPayment').'</td><td>';
if ($paymentLink !== '') {
	print $paymentLink;
} else {
	print '<span class="opacitymedium">'.$langs->trans('LibeufinConnectorTransactionNotLinked').'</span>';
}
print '</td></tr>';
print '<tr><td>'.$langs->trans('LibeufinConnectorTransactionLinkedInvoice').'</td><td>';
if ($invoiceLink !== '') {
	print $invoiceLink;
} else {
	print '<span class="opacitymedium">'.$langs->trans('LibeufinConnectorTransactionNotLinked').'</span>';
}
print '</td></tr>';
print '<tr><td>'.$langs->trans('LibeufinConnectorTransactionLinkedSupplierPayment').'</td><td>';
if ($supplierPaymentLink !== '') {
	print $supplierPaymentLink;
} else {
	print '<span class="opacitymedium">'.$langs->trans('LibeufinConnectorTransactionNotLinked').'</span>';
}
print '</td></tr>';
print '<tr><td>'.$langs->trans('LibeufinConnectorTransactionLinkedSupplierInvoice').'</td><td>';
if ($supplierInvoiceLink !== '') {
	print $supplierInvoiceLink;
} else {
	print '<span class="opacitymedium">'.$langs->trans('LibeufinConnectorTransactionNotLinked').'</span>';
}
print '</td></tr>';
print '<tr><td>'.$langs->trans('LibeufinConnectorTransactionDedupeKey').'</td><td><span class="wordbreak">'.$object->dedupe_key.'</span></td></tr>';
print '<tr><td>'.$langs->trans('LibeufinConnectorTransactionPayloadChecksum').'</td><td><span class="wordbreak">'.$object->payload_checksum.'</span></td></tr>';
print '</table><br>';

if ($canManuallyLink) {
	$manualLinkOptions = array(
		'invoice' => array(),
		'payment' => array(),
		'bank' => array(),
	);
	foreach ($manualLinkCandidates['invoices'] as $candidate) {
		$typeLabel = ($candidate['object_type'] === 'facture' ? $langs->trans('CustomerInvoice') : $langs->trans('SupplierInvoice'));
		if ((int) $candidate['type'] === 2) {
			$typeLabel .= ' / '.$langs->trans('CreditNote');
		}
		$label = $typeLabel.' | '.$candidate['ref'];
		if (!empty($candidate['thirdparty_name'])) {
			$label .= ' | '.$candidate['thirdparty_name'];
		}
		$label .= ' | '.price((float) $candidate['amount']).' '.$candidate['currency'];
		$manualLinkOptions['invoice'][] = array(
			'value' => $candidate['object_type'].':'.$candidate['rowid'],
			'label' => $label,
		);
	}
	foreach ($manualLinkCandidates['payments'] as $candidate) {
		$typeLabel = ($candidate['object_type'] === 'payment' ? $langs->trans('CustomerPayment') : $langs->trans('SupplierPayment'));
		$label = $typeLabel.' | '.$candidate['ref'].' | '.price((float) $candidate['amount']);
		if (!empty($object->currency)) {
			$label .= ' '.$object->currency;
		}
		if ((int) $candidate['fk_bank'] > 0) {
			$label .= ' | bank #'.((int) $candidate['fk_bank']);
		}
		$manualLinkOptions['payment'][] = array(
			'value' => $candidate['object_type'].':'.$candidate['rowid'],
			'label' => $label,
		);
	}
	foreach ($manualLinkCandidates['banks'] as $candidate) {
		$label = '#'.((int) $candidate['rowid']).' | '.trim((string) $candidate['label']);
		$label .= ' | '.price((float) $candidate['amount']);
		if (!empty($object->currency)) {
			$label .= ' '.$object->currency;
		}
		$manualLinkOptions['bank'][] = array(
			'value' => 'bank:'.((int) $candidate['rowid']),
			'label' => $label,
		);
	}
	$defaultManualLinkCase = 'invoice';
	if (empty($manualLinkOptions['invoice']) && !empty($manualLinkOptions['payment'])) {
		$defaultManualLinkCase = 'payment';
	} elseif (empty($manualLinkOptions['invoice']) && empty($manualLinkOptions['payment']) && !empty($manualLinkOptions['bank'])) {
		$defaultManualLinkCase = 'bank';
	}

	print '<div class="tagtable centpercent">';
	print '<form method="POST" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="id" value="'.((int) $object->id).'">';
	print '<input type="hidden" name="mainmenu" value="libeufinconnector">';
	print '<input type="hidden" name="leftmenu" value="libeufinconnector_transactions">';
	print '<input type="hidden" name="action" value="manual_link">';
	print '<table class="border centpercent">';
	print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('LibeufinConnectorTransactionManualLink').'</td></tr>';
	print '<tr><td class="titlefield">'.$langs->trans('Type').'</td><td>';
	print '<select class="flat minwidth200" id="manual_link_case" name="manual_link_case">';
	print '<option value="invoice"'.($defaultManualLinkCase === 'invoice' ? ' selected' : '').'>'.$langs->trans('LibeufinConnectorTransactionManualLinkInvoiceCase').'</option>';
	print '<option value="payment"'.($defaultManualLinkCase === 'payment' ? ' selected' : '').'>'.$langs->trans('LibeufinConnectorTransactionManualLinkPaymentCase').'</option>';
	print '<option value="bank"'.($defaultManualLinkCase === 'bank' ? ' selected' : '').'>'.$langs->trans('LibeufinConnectorTransactionManualLinkBankCase').'</option>';
	print '</select>';
	print '</td></tr>';
	print '<tr><td class="titlefield">'.$langs->trans('Object').'</td><td>';
	foreach ($manualLinkOptions as $case => $options) {
		print '<select class="flat minwidth500 manual-link-target" data-link-case="'.$case.'" name="manual_link_target_'.$case.'"'.($case === $defaultManualLinkCase ? '' : ' style="display:none"').'>';
		if (!empty($options)) {
			foreach ($options as $option) {
				print '<option value="'.dol_escape_htmltag($option['value']).'">'.dol_escape_htmltag($option['label']).'</option>';
			}
		} else {
			print '<option value="">'.$langs->trans('LibeufinConnectorTransactionManualLinkNoCandidates').'</option>';
		}
		print '</select>';
	}
	print '<input type="hidden" id="manual_link_target" name="manual_link_target" value="'.(!empty($manualLinkOptions[$defaultManualLinkCase][0]['value']) ? dol_escape_htmltag($manualLinkOptions[$defaultManualLinkCase][0]['value']) : '').'">';
	print '</td></tr>';
	print '<tr><td></td><td><input class="button" type="submit" value="'.$langs->trans('LibeufinConnectorTransactionManualLinkButton').'"></td></tr>';
	print '</table>';
	print '</form>';
	print '<script>
jQuery(function() {
	function updateManualLinkTarget() {
		var selectedCase = jQuery("#manual_link_case").val();
		var selectedTarget = "";
		jQuery(".manual-link-target").each(function() {
			var field = jQuery(this);
			if (field.data("link-case") === selectedCase) {
				field.show();
				selectedTarget = field.val() || "";
			} else {
				field.hide();
			}
		});
		jQuery("#manual_link_target").val(selectedTarget);
	}
	jQuery("#manual_link_case").on("change", updateManualLinkTarget);
	jQuery(".manual-link-target").on("change", updateManualLinkTarget);
	updateManualLinkTarget();
});
</script>';
	print '</div><br>';
}

print '<details class="centpercent">';
print '<summary class="liste_titre" style="cursor: pointer; padding: 8px 12px;">'.$langs->trans('LibeufinConnectorTransactionInspection').'</summary>';
print '<table class="noborder centpercent">';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('Description').'</td><td>'.dol_escape_htmltag($displaySubject).'</td></tr>';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('LibeufinConnectorTransactionSourcePayto').'</td><td>'.dol_escape_htmltag($displayPayto).'</td></tr>';
if ($object->direction === LibeufinTransaction::DIRECTION_OUTGOING) {
	print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('LibeufinConnectorTransactionOutgoingValidation').'</td><td>';
	if (empty($outgoingValidationMessages)) {
		print '<span class="opacitymedium">'.$langs->trans('LibeufinConnectorTransactionOutgoingReady').'</span>';
	} else {
		print dol_escape_htmltag(implode(' | ', $outgoingValidationMessages));
	}
	print '</td></tr>';
}
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('LibeufinConnectorTransactionSourceReference').'</td><td>'.dol_escape_htmltag(isset($nexusPayload['acct_svcr_ref']) ? (string) $nexusPayload['acct_svcr_ref'] : '').'</td></tr>';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('LibeufinConnectorTransactionSourceUetr').'</td><td>'.dol_escape_htmltag(isset($nexusPayload['uetr']) ? (string) $nexusPayload['uetr'] : '').'</td></tr>';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('LibeufinConnectorTransactionSourceIncomingId').'</td><td>'.dol_escape_htmltag(isset($nexusPayload['incoming_transaction_id']) ? (string) $nexusPayload['incoming_transaction_id'] : '').'</td></tr>';
print '</table>';
print '</details><br>';

print '<details class="centpercent">';
print '<summary class="liste_titre" style="cursor: pointer; padding: 8px 12px;">'.$langs->trans('LibeufinConnectorTransactionRawPayload').'</summary>';
print '<table class="noborder centpercent">';
print '<tr class="oddeven"><td><pre class="small" style="max-height: 480px; overflow: auto;">'.dol_escape_htmltag(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 0, 1).'</pre></td></tr>';
print '</table>';
print '</details>';

llxFooter();
$db->close();
exit;
