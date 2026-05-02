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
 * \file    libeufinconnector/admin/nexusoperations.php
 * \ingroup libeufinconnector
 * \brief   Run and monitor LibEuFin Nexus operations.
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
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php";
require_once '../lib/libeufinconnector.lib.php';
require_once '../lib/nexusconfig.lib.php';

if (!$user->admin) {
	accessforbidden();
}

$langs->loadLangs(array("admin", "libeufinconnector@libeufinconnector"));

/**
 * Render optional PostgreSQL probe details in a collapsible block.
 *
 * @param Translate $langs Translation handler.
 * @param array{command_preview:string,output:string,fix_hint:string} $postgresProbe Probe result.
 * @return void
 */
function libeufinconnectorPrintPostgresProbeDetails($langs, array $postgresProbe)
{
	if ($postgresProbe['command_preview'] === '' && $postgresProbe['output'] === '' && $postgresProbe['fix_hint'] === '') {
		return;
	}

	print '<details class="marginbottomonly">';
	print '<summary>'.$langs->trans('LibeufinConnectorPostgresProbeDetails').'</summary>';
	if ($postgresProbe['command_preview'] !== '') {
		print '<div class="opacitymedium">'.$langs->trans('LibeufinConnectorPostgresProbeCommand').'</div>';
		print '<pre class="small" style="white-space: pre-wrap;">'.dol_escape_htmltag($postgresProbe['command_preview'], 0, 1).'</pre>';
	}
	if ($postgresProbe['output'] !== '') {
		print '<div class="opacitymedium">'.$langs->trans('LibeufinConnectorPostgresProbeRawOutput').'</div>';
		print '<pre class="small" style="white-space: pre-wrap;">'.dol_escape_htmltag($postgresProbe['output'], 0, 1).'</pre>';
	}
	if ($postgresProbe['fix_hint'] !== '') {
		print '<div class="opacitymedium">'.$langs->trans('LibeufinConnectorPostgresProbeSuggestedCommands').'</div>';
		print '<pre class="small" style="white-space: pre-wrap;">'.dol_escape_htmltag($postgresProbe['fix_hint'], 0, 1).'</pre>';
	}
	print '</details>';
}

$action = GETPOST('action', 'aZ09');
$operation = GETPOST('operation', 'aZ09');
$operations = libeufinconnectorGetNexusOperations();

if ($action === 'start_operation') {
	if (!isset($operations[$operation])) {
		setEventMessages($langs->trans('LibeufinConnectorNexusOperationUnknown'), null, 'errors');
	} else {
		$result = libeufinconnectorStartNexusOperation($operation);
		if (!empty($result['ok'])) {
			setEventMessages($langs->trans('LibeufinConnectorNexusOperationStarted', $operations[$operation]['label'], $result['pid']), null, 'mesgs');
		} else {
			setEventMessages($langs->trans('LibeufinConnectorNexusOperationStartFailed', $operations[$operation]['label'], $result['error']), null, 'errors');
		}
	}
	header('Location: '.$_SERVER["PHP_SELF"]);
	exit;
}

$runtimeProbe = libeufinconnectorProbeNexusRuntime();
$postgresProbe = libeufinconnectorProbePostgresRuntime();
$effectiveConfigPath = libeufinconnectorGetEffectiveNexusConfigPath();
$configStatus = libeufinconnectorReadNexusConfig($effectiveConfigPath);

$help_url = '';
$title = "LibeufinConnectorNexusOperations";

llxHeader('', $langs->trans($title), $help_url, '', 0, 0, '', '', '', 'mod-libeufinconnector page-admin');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans($title), $linkback, 'title_setup');

$head = libeufinconnectorAdminPrepareHead();
print dol_get_fiche_head($head, 'nexusoperations', $langs->trans($title), -1, "libeufinconnector@libeufinconnector");

print '<span class="opacitymedium">'.$langs->trans("LibeufinConnectorNexusOperationsPage").'</span><br><br>';

print '<div class="underbanner clearboth marginbottomonly">';
print '<div><strong>'.$langs->trans('LibeufinConnectorNexusConfigEffectivePath').':</strong> '.dol_escape_htmltag($effectiveConfigPath !== '' ? $effectiveConfigPath : $langs->trans('None')).'</div>';
if ($configStatus['error'] !== '') {
	print '<div class="warning">'.$langs->trans('LibeufinConnectorNexusConfigReadError', $configStatus['error']).'</div>';
}
if ($runtimeProbe['status'] === 'ok') {
	print '<div class="ok">'.$langs->trans('LibeufinConnectorNexusRuntimeProbeOk', dol_escape_htmltag($runtimeProbe['output'])).'</div>';
} elseif ($runtimeProbe['status'] === 'missing_binary') {
	print '<div class="warning">'.$langs->trans('LibeufinConnectorNexusRuntimeProbeMissingBinary').'</div>';
} else {
	print '<div class="warning">'.$langs->trans('LibeufinConnectorNexusRuntimeProbeFailed', dol_escape_htmltag($runtimeProbe['command_preview'])).'</div>';
}
if ($postgresProbe['status'] === 'ok') {
	print '<div class="ok">'.$langs->trans('LibeufinConnectorPostgresProbeOk', dol_escape_htmltag($postgresProbe['runtime_user'])).'</div>';
} elseif ($postgresProbe['status'] === 'missing_config') {
	print '<div class="warning">'.$langs->trans('LibeufinConnectorPostgresProbeMissingConfig').'</div>';
} elseif ($postgresProbe['status'] === 'config_not_readable') {
	print '<div class="warning">'.$langs->trans('LibeufinConnectorPostgresProbeConfigNotReadable', dol_escape_htmltag($postgresProbe['output'])).'</div>';
} elseif ($postgresProbe['status'] === 'missing_psql') {
	print '<div class="warning">'.$langs->trans('LibeufinConnectorPostgresProbeMissingPsql').'</div>';
} elseif ($postgresProbe['status'] === 'postgres_unreachable') {
	print '<div class="warning">'.$langs->trans('LibeufinConnectorPostgresProbeUnreachable', dol_escape_htmltag($postgresProbe['config'])).'</div>';
	libeufinconnectorPrintPostgresProbeDetails($langs, $postgresProbe);
} elseif ($postgresProbe['status'] === 'missing_role') {
	print '<div class="warning">'.$langs->trans('LibeufinConnectorPostgresProbeMissingRole', dol_escape_htmltag($postgresProbe['role'])).'</div>';
	libeufinconnectorPrintPostgresProbeDetails($langs, $postgresProbe);
} elseif ($postgresProbe['status'] === 'missing_database') {
	print '<div class="warning">'.$langs->trans('LibeufinConnectorPostgresProbeMissingDatabase', dol_escape_htmltag($postgresProbe['database']), dol_escape_htmltag($postgresProbe['role'])).'</div>';
	libeufinconnectorPrintPostgresProbeDetails($langs, $postgresProbe);
} else {
	print '<div class="warning">'.$langs->trans('LibeufinConnectorPostgresProbeFailed', dol_escape_htmltag($postgresProbe['command_preview'])).'</div>';
	libeufinconnectorPrintPostgresProbeDetails($langs, $postgresProbe);
}
print '</div>';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('LibeufinConnectorNexusOperation').'</td>';
print '<td>'.$langs->trans('Status').'</td>';
print '<td>'.$langs->trans('LibeufinConnectorNexusOperationStartedAt').'</td>';
print '<td>'.$langs->trans('LibeufinConnectorNexusOperationExitCode').'</td>';
print '<td class="right">'.$langs->trans('Action').'</td>';
print '</tr>';

foreach ($operations as $operationCode => $definition) {
	$status = libeufinconnectorGetNexusOperationStatus($operationCode);
	$statusLabel = $langs->trans('LibeufinConnectorNexusOperationStatus'.ucfirst($status['status']));

	print '<tr class="oddeven">';
	print '<td><strong>'.dol_escape_htmltag($definition['label']).'</strong><br><span class="opacitymedium">'.dol_escape_htmltag($definition['description']).'</span></td>';
	print '<td>'.dol_escape_htmltag($statusLabel).($status['pid'] > 0 ? ' <span class="opacitymedium">PID '.((int) $status['pid']).'</span>' : '').'</td>';
	print '<td>'.dol_escape_htmltag($status['started_at']).'</td>';
	print '<td>'.dol_escape_htmltag($status['exit_code']).'</td>';
	print '<td class="right">';
	print '<form method="POST" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="start_operation">';
	print '<input type="hidden" name="operation" value="'.dol_escape_htmltag($operationCode).'">';
	print '<input type="submit" class="button small" value="'.$langs->trans('LibeufinConnectorNexusOperationRun').'">';
	print '</form>';
	print '</td>';
	print '</tr>';

	if ($status['command_preview'] !== '' || $status['log_tail'] !== '') {
		print '<tr class="oddeven">';
		print '<td colspan="5">';
		if ($status['status'] === 'failed' && $operationCode === 'ebics_setup' && strpos($status['log_tail'], "type 'yes, accept' to accept them") !== false && isset($operations['ebics_accept_bank_keys'])) {
			print '<div class="warning">'.$langs->trans('LibeufinConnectorNexusBankKeysNeedAcceptance').'</div>';
			print '<form method="POST" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'">';
			print '<input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="action" value="start_operation">';
			print '<input type="hidden" name="operation" value="ebics_accept_bank_keys">';
			print '<input type="submit" class="button small" value="'.$langs->trans('LibeufinConnectorNexusAcceptBankKeys').'">';
			print '</form>';
		}
		if ($status['status'] === 'failed' && strpos($status['log_tail'], 'relation "pending_ebics_transactions" does not exist') !== false && isset($operations['nexus_dbinit'])) {
			print '<div class="warning">'.$langs->trans('LibeufinConnectorNexusDatabaseNeedsInit').'</div>';
			print '<form method="POST" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'">';
			print '<input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="action" value="start_operation">';
			print '<input type="hidden" name="operation" value="nexus_dbinit">';
			print '<input type="submit" class="button small" value="'.$langs->trans('LibeufinConnectorNexusInitializeDatabase').'">';
			print '</form>';
		}
		print '<details>';
		print '<summary>'.$langs->trans('LibeufinConnectorNexusShowLog').'</summary>';
		if ($status['command_preview'] !== '') {
			print '<div><strong>'.$langs->trans('LibeufinConnectorNexusOperationCommand').':</strong> '.dol_escape_htmltag($status['command_preview']).'</div>';
		}
		print '<div><strong>'.$langs->trans('LibeufinConnectorNexusOperationLogFile').':</strong> '.dol_escape_htmltag($status['log_file']).'</div>';
		if ($status['log_tail'] !== '') {
			print '<pre class="small" style="max-height: 260px; overflow: auto;">'.dol_escape_htmltag($status['log_tail'], 0, 1).'</pre>';
		}
		print '</details>';
		print '</td>';
		print '</tr>';
	}
}

print '</table>';

print dol_get_fiche_end();
llxFooter();
$db->close();
