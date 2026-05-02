<?php
/* Copyright (C) 2026		SuperAdmin
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
 * \file    libeufinconnector/lib/libeufinconnector.lib.php
 * \ingroup libeufinconnector
 * \brief   Library files with common functions for LibEuFinConnector
 */

/**
 * Prepare admin pages header
 *
 * @return array<array{string,string,string}>
 */
function libeufinconnectorAdminPrepareHead()
{
	global $langs, $conf;

	// global $db;
	// $extrafields = new ExtraFields($db);
	// $extrafields->fetch_name_optionals_label('myobject');

	$langs->load("libeufinconnector@libeufinconnector");

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath("/libeufinconnector/admin/setup.php", 1);
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath("/libeufinconnector/admin/nexusconfig.php", 1);
	$head[$h][1] = $langs->trans("LibeufinConnectorNexusConfig");
	$head[$h][2] = 'nexusconfig';
	$h++;

	$head[$h][0] = dol_buildpath("/libeufinconnector/admin/nexusoperations.php", 1);
	$head[$h][1] = $langs->trans("LibeufinConnectorNexusOperations");
	$head[$h][2] = 'nexusoperations';
	$h++;

	/*
	$head[$h][0] = dol_buildpath("/libeufinconnector/admin/myobject_extrafields.php", 1);
	$head[$h][1] = $langs->trans("ExtraFields");
	$nbExtrafields = (isset($extrafields->attributes['myobject']['label']) && is_countable($extrafields->attributes['myobject']['label'])) ? count($extrafields->attributes['myobject']['label']) : 0;
	if ($nbExtrafields > 0) {
		$head[$h][1] .= '<span class="badge marginleftonlyshort">' . $nbExtrafields . '</span>';
	}
	$head[$h][2] = 'myobject_extrafields';
	$h++;

	$head[$h][0] = dol_buildpath("/libeufinconnector/admin/myobjectline_extrafields.php", 1);
	$head[$h][1] = $langs->trans("ExtraFieldsLines");
	$nbExtrafields = (isset($extrafields->attributes['myobjectline']['label']) && is_countable($extrafields->attributes['myobjectline']['label'])) ? count($extrafields->attributes['myobject']['label']) : 0;
	if ($nbExtrafields > 0) {
		$head[$h][1] .= '<span class="badge marginleftonlyshort">' . $nbExtrafields . '</span>';
	}
	$head[$h][2] = 'myobject_extrafieldsline';
	$h++;
	*/

	$head[$h][0] = dol_buildpath("/libeufinconnector/admin/about.php", 1);
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	// Show more tabs from modules
	// Entries must be declared in modules descriptor with line
	//$this->tabs = array(
	//	'entity:+tabname:Title:@libeufinconnector:/libeufinconnector/mypage.php?id=__ID__'
	//); // to add new tab
	//$this->tabs = array(
	//	'entity:-tabname:Title:@libeufinconnector:/libeufinconnector/mypage.php?id=__ID__'
	//); // to remove a tab
	complete_head_from_modules($conf, $langs, null, $head, $h, 'libeufinconnector@libeufinconnector');

	complete_head_from_modules($conf, $langs, null, $head, $h, 'libeufinconnector@libeufinconnector', 'remove');

	return $head;
}

/**
 * Check whether tutorial/demo tools are globally enabled.
 *
 * @return bool
 */
function libeufinconnectorTutorialEnabled()
{
	return (bool) getDolGlobalInt('LIBEUFINCONNECTOR_TUTORIAL_ENABLE');
}

/**
 * Check whether a user may access tutorial/demo tools.
 *
 * @param User $user User to check
 * @return bool
 */
function libeufinconnectorCanUseTutorial($user)
{
	return libeufinconnectorTutorialEnabled()
		&& is_object($user)
		&& method_exists($user, 'hasRight')
		&& $user->hasRight('libeufinconnector', 'tutorial', 'use');
}

/**
 * Return a standard Dolibarr object link using the object's getNomUrl method.
 *
 * @param DoliDB $db Database handler
 * @param string $type Object type
 * @param int $id Object id
 * @param int $withpicto 0=No picto, 1=Include picto, 2=Only picto
 * @return string
 */
function libeufinconnectorGetDolibarrObjectNomUrl($db, $type, $id, $withpicto = 1)
{
	$id = (int) $id;
	if ($id <= 0) {
		return '';
	}

	if ($type === 'bank') {
		require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
		$object = new AccountLine($db);
	} elseif ($type === 'payment') {
		require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
		$object = new Paiement($db);
	} elseif ($type === 'supplier_payment') {
		require_once DOL_DOCUMENT_ROOT.'/fourn/class/paiementfourn.class.php';
		$object = new PaiementFourn($db);
	} elseif ($type === 'invoice') {
		require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
		$object = new Facture($db);
	} elseif ($type === 'supplier_invoice') {
		require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
		$object = new FactureFournisseur($db);
	} elseif ($type === 'transfer_order') {
		require_once DOL_DOCUMENT_ROOT.'/compta/prelevement/class/bonprelevement.class.php';
		$object = new BonPrelevement($db);
	} else {
		return '';
	}

	if ($object->fetch($id) <= 0) {
		return '';
	}

	return $object->getNomUrl($withpicto);
}
