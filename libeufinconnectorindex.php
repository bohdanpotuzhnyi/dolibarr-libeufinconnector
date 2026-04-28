<?php
/* Copyright (C) 2001-2005  Rodolphe Quiedeville    <rodolphe@quiedeville.org>
 * Copyright (C) 2004-2015  Laurent Destailleur     <eldy@users.sourceforge.net>
 * Copyright (C) 2005-2012  Regis Houssin           <regis.houssin@inodbox.com>
 * Copyright (C) 2015       Jean-François Ferry     <jfefe@aternatik.fr>
 * Copyright (C) 2024       Frédéric France         <frederic.france@free.fr>
 * Copyright (C) 2026		SuperAdmin
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *	\file       libeufinconnector/libeufinconnectorindex.php
 *	\ingroup    libeufinconnector
 *	\brief      Home page of libeufinconnector top menu
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
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
// Try main.inc.php using relative path
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

require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once __DIR__.'/lib/libeufinconnector.lib.php';
require_once __DIR__.'/lib/nexusconfig.lib.php';
require_once __DIR__.'/lib/transactionstaging.lib.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

// Load translation files required by the page
$langs->loadLangs(array("libeufinconnector@libeufinconnector"));

$action = GETPOST('action', 'aZ09');

// Security check - Protection if external user
$socid = GETPOSTINT('socid');
if (!empty($user->socid) && $user->socid > 0) {
	accessforbidden();
}

// Initialize a technical object to manage hooks. Note that conf->hooks_modules contains array
//$hookmanager->initHooks(array($object->element.'index'));

// Security check (enable the most restrictive one)
//if ($user->socid > 0) accessforbidden();
//if ($user->socid > 0) $socid = $user->socid;
//if (!isModEnabled('libeufinconnector')) {
//	accessforbidden('Module not enabled');
//}
//if (! $user->hasRight('libeufinconnector', 'myobject', 'read')) {
//	accessforbidden();
//}
//restrictedArea($user, 'libeufinconnector', 0, 'libeufinconnector_myobject', 'myobject', '', 'rowid');
//if (empty($user->admin)) {
//	accessforbidden('Must be admin');
//}


/*
 * Actions
 */

// None


/*
 * View
 */
$stats = libeufinconnectorGetTransactionStats($db);
$effectiveConfigPath = libeufinconnectorGetEffectiveNexusConfigPath();
$fetchEnabled = ((int) getDolGlobalInt('LIBEUFINCONNECTOR_ENABLE_FETCH') === 1);
$submitEnabled = ((int) getDolGlobalInt('LIBEUFINCONNECTOR_ENABLE_SUBMIT') === 1);
$configuredBankAccountId = (int) getDolGlobalInt('LIBEUFINCONNECTOR_BANK_ACCOUNT_ID');
$bankAccountLabel = $langs->trans('None');

if ($configuredBankAccountId > 0) {
	$bankAccount = new Account($db);
	if ($bankAccount->fetch($configuredBankAccountId) > 0) {
		$parts = array();
		if (!empty($bankAccount->ref)) {
			$parts[] = $bankAccount->ref;
		}
		if (!empty($bankAccount->label)) {
			$parts[] = $bankAccount->label;
		}
		if (!empty($bankAccount->iban)) {
			$parts[] = $bankAccount->iban;
		}
		$bankAccountLabel = implode(' - ', $parts);
	}
}

$recentRows = array();
if (!empty($stats['installed'])) {
	$sql = "SELECT rowid, transaction_date, datec, direction, transaction_status, amount, currency,";
	$sql .= " counterparty_name, external_transaction_id";
	$sql .= " FROM ".MAIN_DB_PREFIX."libeufinconnector_transaction";
	$sql .= " WHERE entity = ".((int) $conf->entity);
	$sql .= " ORDER BY transaction_date DESC, rowid DESC";
	$sql .= $db->plimit(10, 0);

	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$recentRows[] = $obj;
		}
		$db->free($resql);
	}
}

llxHeader("", $langs->trans("LibeufinConnectorHome"), '', '', 0, 0, '', '', '', 'mod-libeufinconnector page-index');

print load_fiche_titre($langs->trans("LibeufinConnectorHome"), '', 'home');
print '<span class="opacitymedium">'.$langs->trans("LibeufinConnectorHomePage").'</span><br><br>';

if (empty($stats['installed'])) {
	print '<div class="warning">'.$langs->trans('LibeufinConnectorTransactionTableMissing').'</div>';
} else {
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('LibeufinConnectorTransactionStats').'</td></tr>';
	$statLabels = array(
		'total' => 'LibeufinConnectorTotalStaged',
		'incoming' => 'LibeufinConnectorTransactionDirectionIncoming',
		'outgoing' => 'LibeufinConnectorTransactionDirectionOutgoing',
		'new' => 'LibeufinConnectorTransactionStatusNew',
		'imported' => 'LibeufinConnectorTransactionStatusImported',
		'matched' => 'LibeufinConnectorTransactionStatusMatched',
		'submitted' => 'LibeufinConnectorTransactionStatusSubmitted',
		'booked' => 'LibeufinConnectorTransactionStatusBooked',
		'failed' => 'LibeufinConnectorTransactionStatusFailed',
		'ignored' => 'LibeufinConnectorTransactionStatusIgnored',
	);
	foreach ($statLabels as $key => $translationKey) {
		print '<tr class="oddeven"><td>'.$langs->trans($translationKey).'</td><td class="right">'.((int) $stats[$key]).'</td></tr>';
	}
	print '</table><br>';

	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><td colspan="5">'.$langs->trans('LibeufinConnectorRecentTransactions').'</td></tr>';

	if (empty($recentRows)) {
		print '<tr class="oddeven"><td colspan="5" class="opacitymedium">'.$langs->trans('LibeufinConnectorTransactionNoData').'</td></tr>';
	} else {
		print '<tr class="liste_titre">';
		print '<td>'.$langs->trans('Date').'</td>';
		print '<td>'.$langs->trans('LibeufinConnectorTransactionFilterDirection').'</td>';
		print '<td>'.$langs->trans('Status').'</td>';
		print '<td class="right">'.$langs->trans('Amount').'</td>';
		print '<td>'.$langs->trans('LibeufinConnectorTransactionCounterparty').'</td>';
		print '</tr>';
		foreach ($recentRows as $row) {
			$dateValue = !empty($row->transaction_date) ? $row->transaction_date : $row->datec;
			$detailUrl = dol_buildpath('/libeufinconnector/transaction_card.php?id='.((int) $row->rowid).'&mainmenu=libeufinconnector&leftmenu=libeufinconnector_transactions', 1);
			print '<tr class="oddeven">';
			print '<td><a href="'.$detailUrl.'">'.dol_print_date($db->jdate($dateValue), 'dayhour').'</a></td>';
			print '<td>'.dol_escape_htmltag($langs->trans('LibeufinConnectorTransactionDirection'.ucfirst($row->direction))).'</td>';
			print '<td>'.dol_escape_htmltag($langs->trans('LibeufinConnectorTransactionStatus'.ucfirst($row->transaction_status))).'</td>';
			print '<td class="right">'.price((float) $row->amount).' '.dol_escape_htmltag($row->currency).'</td>';
			print '<td><a href="'.$detailUrl.'">'.dol_escape_htmltag($row->counterparty_name !== '' ? $row->counterparty_name : $row->external_transaction_id).'</a></td>';
			print '</tr>';
		}
	}
	print '</table><br>';
	print '<div class="right"><a class="button" href="'.dol_buildpath('/libeufinconnector/transactions.php?mainmenu=libeufinconnector&leftmenu=libeufinconnector_transactions', 1).'">'.$langs->trans('LibeufinConnectorOpenTransactions').'</a></div>';
}

print '<br>';
print '<details class="underbanner clearboth marginbottomonly">';
print '<summary><strong>'.$langs->trans('LibeufinConnectorConfiguration').'</strong></summary>';
print '<div class="marginbottomonly margintoponly">';
print '<table class="noborder centpercent">';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('LibeufinConnectorMappedBankAccount').'</td><td>'.dol_escape_htmltag($bankAccountLabel).'</td></tr>';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('LibeufinConnectorConfigPathSummary').'</td><td>'.dol_escape_htmltag($effectiveConfigPath !== '' ? $effectiveConfigPath : $langs->trans('None')).'</td></tr>';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('LibeufinConnectorFetchSummary').'</td><td>'.dol_escape_htmltag($langs->trans($fetchEnabled ? 'LibeufinConnectorEnabledLabel' : 'LibeufinConnectorDisabledLabel')).'</td></tr>';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('LibeufinConnectorSubmitSummary').'</td><td>'.dol_escape_htmltag($langs->trans($submitEnabled ? 'LibeufinConnectorEnabledLabel' : 'LibeufinConnectorDisabledLabel')).'</td></tr>';
print '</table>';
print '</div>';
print '</details>';

llxFooter();
$db->close();
exit;
