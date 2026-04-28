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

// Protection to avoid direct call of template
if (empty($conf) || !is_object($conf)) {
	print "Error, template page can't be called as URL";
	exit(1);
}

print "<!-- BEGIN PHP TEMPLATE libeufinconnector/libeufintransaction/tpl/linkedobjectblock.tpl.php -->\n";

global $noMoreLinkedObjectBlockAfter;

$langs = $GLOBALS['langs'];
'@phan-var-force Translate $langs';
$linkedObjectBlock = $GLOBALS['linkedObjectBlock'];
'@phan-var-force array<string,CommonObject> $linkedObjectBlock';

$langs->load("libeufinconnector@libeufinconnector");

$linkedObjectBlock = dol_sort_array($linkedObjectBlock, 'transaction_date', 'desc', 0, 0, 1);

$statusLabels = array(
	'new' => $langs->trans('LibeufinConnectorTransactionStatusNew'),
	'imported' => $langs->trans('LibeufinConnectorTransactionStatusImported'),
	'matched' => $langs->trans('LibeufinConnectorTransactionStatusMatched'),
	'submitted' => $langs->trans('LibeufinConnectorTransactionStatusSubmitted'),
	'booked' => $langs->trans('LibeufinConnectorTransactionStatusBooked'),
	'failed' => $langs->trans('LibeufinConnectorTransactionStatusFailed'),
	'ignored' => $langs->trans('LibeufinConnectorTransactionStatusIgnored'),
);

$ilink = 0;
foreach ($linkedObjectBlock as $key => $objectlink) {
	$ilink++;
	$trclass = 'oddeven';
	if ($ilink == count($linkedObjectBlock) && empty($noMoreLinkedObjectBlockAfter) && count($linkedObjectBlock) <= 1) {
		$trclass .= ' liste_sub_total';
	}

	$transactionId = (int) (!empty($objectlink->id) ? $objectlink->id : $objectlink->rowid);
	$detailUrl = dol_buildpath('/libeufinconnector/transaction_card.php?id='.$transactionId.'&mainmenu=libeufinconnector&leftmenu=libeufinconnector_transactions', 1);
	$displayRef = '#'.$transactionId;
	$transactionTimestamp = 0;
	if (!empty($objectlink->transaction_date)) {
		$transactionTimestamp = strtotime((string) $objectlink->transaction_date);
	}
	if (empty($transactionTimestamp) && !empty($objectlink->datec)) {
		$transactionTimestamp = strtotime((string) $objectlink->datec);
	}
	$statusKey = strtolower(trim((string) $objectlink->transaction_status));
	$statusLabel = isset($statusLabels[$statusKey]) ? $statusLabels[$statusKey] : dol_escape_htmltag((string) $objectlink->transaction_status);
	$amountOutput = '';
	if ($objectlink->amount !== null && $objectlink->amount !== '') {
		$amountOutput = price((float) $objectlink->amount);
		if (!empty($objectlink->currency)) {
			$amountOutput .= ' '.dol_escape_htmltag((string) $objectlink->currency);
		}
	}

	print '<tr class="'.$trclass.'" data-element="libeufintransaction" data-id="'.$transactionId.'">';
	print '<td class="linkedcol-element tdoverflowmax100">'.$langs->trans('LibeufinConnectorTransaction').'</td>';
	print '<td class="linkedcol-name tdoverflowmax150"><a href="'.$detailUrl.'">'.dol_escape_htmltag($displayRef).'</a></td>';
	print '<td class="linkedcol-ref tdoverflowmax150" title="'.dol_escape_htmltag((string) $objectlink->external_transaction_id).'">'.dol_escape_htmltag((string) $objectlink->external_transaction_id).'</td>';
	print '<td class="linkedcol-date center">'.($transactionTimestamp > 0 ? dol_print_date($transactionTimestamp, 'day') : '').'</td>';
	print '<td class="linkedcol-amount right nowraponall">'.$amountOutput.'</td>';
	print '<td class="linkedcol-statut right">'.$statusLabel.'</td>';
	print '<td class="linkedcol-action right"><a class="reposition" href="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'&action=dellink&token='.newToken().'&dellinkid='.$key.'">'.img_picto($langs->transnoentitiesnoconv("RemoveLink"), 'unlink').'</a></td>';
	print "</tr>\n";
}

print "<!-- END PHP TEMPLATE -->\n";

return 1;
