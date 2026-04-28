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
 * \file    libeufinconnector/lib/transactionstaging.lib.php
 * \ingroup libeufinconnector
 * \brief   Helpers around the LibEuFin transaction staging model
 */

require_once __DIR__.'/../class/libeufintransaction.class.php';

/**
 * Tell whether the staging table is installed.
 *
 * @param DoliDB $db Database handler.
 * @return bool
 */
function libeufinconnectorHasTransactionTable($db)
{
	$result = $db->DDLDescTable(MAIN_DB_PREFIX.'libeufinconnector_transaction', 'rowid');
	return !empty($result);
}

/**
 * Return dashboard statistics for staged transactions in the current entity.
 *
 * @param DoliDB $db Database handler.
 * @return array<string,int|bool>
 */
function libeufinconnectorGetTransactionStats($db)
{
	global $conf;

	$stats = array(
		'installed' => false,
		'total' => 0,
		'incoming' => 0,
		'outgoing' => 0,
		'new' => 0,
		'imported' => 0,
		'matched' => 0,
		'submitted' => 0,
		'booked' => 0,
		'failed' => 0,
		'ignored' => 0,
	);

	if (!libeufinconnectorHasTransactionTable($db)) {
		return $stats;
	}

	$sql = "SELECT";
	$sql .= " COUNT(*) as total,";
	$sql .= " SUM(CASE WHEN direction = '".$db->escape(LibeufinTransaction::DIRECTION_INCOMING)."' THEN 1 ELSE 0 END) as incoming,";
	$sql .= " SUM(CASE WHEN direction = '".$db->escape(LibeufinTransaction::DIRECTION_OUTGOING)."' THEN 1 ELSE 0 END) as outgoing,";
	$sql .= " SUM(CASE WHEN transaction_status = '".$db->escape(LibeufinTransaction::STATUS_NEW)."' THEN 1 ELSE 0 END) as new_count,";
	$sql .= " SUM(CASE WHEN transaction_status = '".$db->escape(LibeufinTransaction::STATUS_IMPORTED)."' THEN 1 ELSE 0 END) as imported_count,";
	$sql .= " SUM(CASE WHEN transaction_status = '".$db->escape(LibeufinTransaction::STATUS_MATCHED)."' THEN 1 ELSE 0 END) as matched_count,";
	$sql .= " SUM(CASE WHEN transaction_status = '".$db->escape(LibeufinTransaction::STATUS_SUBMITTED)."' THEN 1 ELSE 0 END) as submitted_count,";
	$sql .= " SUM(CASE WHEN transaction_status = '".$db->escape(LibeufinTransaction::STATUS_BOOKED)."' THEN 1 ELSE 0 END) as booked_count,";
	$sql .= " SUM(CASE WHEN transaction_status = '".$db->escape(LibeufinTransaction::STATUS_FAILED)."' THEN 1 ELSE 0 END) as failed_count,";
	$sql .= " SUM(CASE WHEN transaction_status = '".$db->escape(LibeufinTransaction::STATUS_IGNORED)."' THEN 1 ELSE 0 END) as ignored_count";
	$sql .= " FROM ".MAIN_DB_PREFIX."libeufinconnector_transaction";
	$sql .= " WHERE entity = ".((int) $conf->entity);

	$resql = $db->query($sql);
	if (!$resql) {
		return $stats;
	}

	$obj = $db->fetch_object($resql);
	$db->free($resql);
	if (!$obj) {
		$stats['installed'] = true;
		return $stats;
	}

	$stats['installed'] = true;
	$stats['total'] = (int) $obj->total;
	$stats['incoming'] = (int) $obj->incoming;
	$stats['outgoing'] = (int) $obj->outgoing;
	$stats['new'] = (int) $obj->new_count;
	$stats['imported'] = (int) $obj->imported_count;
	$stats['matched'] = (int) $obj->matched_count;
	$stats['submitted'] = (int) $obj->submitted_count;
	$stats['booked'] = (int) $obj->booked_count;
	$stats['failed'] = (int) $obj->failed_count;
	$stats['ignored'] = (int) $obj->ignored_count;

	return $stats;
}

/**
 * Build the deterministic dedupe key for a staged transaction payload.
 *
 * @param array<string,mixed> $data Transaction payload.
 * @return string
 */
function libeufinconnectorBuildTransactionDedupeKey(array $data)
{
	return LibeufinTransaction::buildDedupeKey($data);
}

/**
 * Build a stable checksum for a raw payload snapshot.
 *
 * @param mixed $payload Raw payload.
 * @return string
 */
function libeufinconnectorBuildTransactionPayloadChecksum($payload)
{
	return LibeufinTransaction::buildPayloadChecksum($payload);
}

/**
 * Upsert a staged transaction row.
 *
 * @param DoliDB              $db Database handler.
 * @param array<string,mixed> $data Staging payload.
 * @param object|null         $user Acting user.
 * @param int                 $notrigger Disable triggers when set to 1.
 * @return array{ok:bool,action:string,rowid:int,error:string,dedupe_key:string}
 */
function libeufinconnectorStageTransaction($db, array $data, $user = null, $notrigger = 1)
{
	return LibeufinTransaction::upsertFromArray($db, $data, $user, $notrigger);
}
