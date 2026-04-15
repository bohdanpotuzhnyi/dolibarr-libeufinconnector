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
 * \file    libeufinconnector/admin/nexusconfig.php
 * \ingroup libeufinconnector
 * \brief   Review and update the managed subset of libeufin-nexus.conf.
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

$action = GETPOST('action', 'aZ09');
$configuredConfigPath = libeufinconnectorGetConfiguredNexusConfigPath();
$localConfigPath = libeufinconnectorGetLocalNexusConfigPath();
$configPath = libeufinconnectorGetEffectiveNexusConfigPath();
$useLocalConfig = libeufinconnectorUseLocalNexusConfig();
$managedSchema = libeufinconnectorGetManagedNexusConfigSchema();
$managedLabels = libeufinconnectorGetManagedNexusConfigLabels();
$expectedConfig = libeufinconnectorGetExpectedNexusConfig();

if ($action === 'save_managed_config') {
	$values = array();
	foreach ($managedSchema as $section => $sectionMeta) {
		$values[$section] = array();
		foreach ($sectionMeta['fields'] as $key => $label) {
			$postKey = 'NEXUSCONFIG_'.strtoupper(str_replace('-', '_', $section)).'_'.$key;
			$values[$section][$key] = trim((string) GETPOST($postKey, 'restricthtml'));
		}
	}

	if ($useLocalConfig) {
		$dirResult = libeufinconnectorEnsureLocalNexusConfigDir();
		if (empty($dirResult['ok'])) {
			$writeResult = array('ok' => false, 'error' => $dirResult['error']);
		} else {
			$keyDirResult = libeufinconnectorEnsureLocalNexusKeysDir();
			if (empty($keyDirResult['ok'])) {
				$writeResult = array('ok' => false, 'error' => $keyDirResult['error']);
			} else {
				$tempDirResult = libeufinconnectorEnsureTempDir();
				if (empty($tempDirResult['ok'])) {
					$writeResult = array('ok' => false, 'error' => $tempDirResult['error']);
				} else {
					$bootstrapPath = ($configuredConfigPath !== '' && $configuredConfigPath !== $localConfigPath) ? $configuredConfigPath : '';
					$writeResult = libeufinconnectorWriteManagedNexusConfig($localConfigPath, $values, $bootstrapPath);
				}
			}
		}
	} else {
		$writeResult = libeufinconnectorWriteManagedNexusConfig($configPath, $values);
	}

	if (!empty($writeResult['ok'])) {
		setEventMessages($langs->trans('LibeufinConnectorNexusConfigSaved'), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('LibeufinConnectorNexusConfigSaveFailed', $writeResult['error']), null, 'errors');
	}
}

$actualConfig = libeufinconnectorReadNexusConfig($configPath);
$mismatches = libeufinconnectorCompareNexusConfig($actualConfig, $expectedConfig);
$runtimeProbe = libeufinconnectorProbeNexusRuntime();

$currentFormValues = array();
foreach ($managedSchema as $section => $sectionMeta) {
	$currentFormValues[$section] = array();
	foreach ($sectionMeta['fields'] as $key => $label) {
		$currentValue = isset($actualConfig['sections'][$section][$key]) ? (string) $actualConfig['sections'][$section][$key] : '';
		$expectedValue = isset($expectedConfig[$section][$key]) ? (string) $expectedConfig[$section][$key] : '';
		$currentFormValues[$section][$key] = ($currentValue !== '' ? $currentValue : $expectedValue);
	}
}

$form = new Form($db);
$help_url = '';
$title = "LibeufinConnectorNexusConfig";

llxHeader('', $langs->trans($title), $help_url, '', 0, 0, '', '', '', 'mod-libeufinconnector page-admin');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans($title), $linkback, 'title_setup');

$head = libeufinconnectorAdminPrepareHead();
print dol_get_fiche_head($head, 'nexusconfig', $langs->trans($title), -1, "libeufinconnector@libeufinconnector");

print '<span class="opacitymedium">'.$langs->trans("LibeufinConnectorNexusConfigPage").'</span><br><br>';

print '<div class="fichecenter">';
print '<div class="underbanner clearboth marginbottomonly">';
print '<div><strong>'.$langs->trans('LibeufinConnectorNexusConfigMode').':</strong> '.($useLocalConfig ? $langs->trans('LibeufinConnectorNexusConfigModeLocalLabel') : $langs->trans('LibeufinConnectorNexusConfigModeExternalLabel')).'</div>';
print '<div><strong>'.$langs->trans('LibeufinConnectorNexusConfigEffectivePath').':</strong> '.dol_escape_htmltag($configPath !== '' ? $configPath : $langs->trans('None')).'</div>';
print '<div><strong>'.$langs->trans('LibeufinConnectorNexusConfigPath').':</strong> '.dol_escape_htmltag($configuredConfigPath !== '' ? $configuredConfigPath : $langs->trans('None')).'</div>';
if ($useLocalConfig) {
	print '<div><strong>'.$langs->trans('LibeufinConnectorNexusConfigSourcePath').':</strong> '.dol_escape_htmltag($configuredConfigPath !== '' ? $configuredConfigPath : $langs->trans('None')).'</div>';
	print '<div><strong>'.$langs->trans('LibeufinConnectorNexusConfigLocalPath').':</strong> '.dol_escape_htmltag($localConfigPath).'</div>';
}
if ($actualConfig['error'] !== '') {
	print '<div class="warning">'.$langs->trans('LibeufinConnectorNexusConfigReadError', $actualConfig['error']).'</div>';
} elseif (!empty($mismatches)) {
	print '<div class="warning">'.$langs->trans('LibeufinConnectorNexusConfigStatusMismatch').'</div>';
} else {
	print '<div class="ok">'.$langs->trans('LibeufinConnectorNexusConfigStatusMatch').'</div>';
}
if ($runtimeProbe['status'] === 'ok') {
	print '<div class="ok">'.$langs->trans('LibeufinConnectorNexusRuntimeProbeOk', dol_escape_htmltag($runtimeProbe['output'])).'</div>';
} elseif ($runtimeProbe['status'] === 'missing_binary') {
	print '<div class="warning">'.$langs->trans('LibeufinConnectorNexusRuntimeProbeMissingBinary').'</div>';
} else {
	print '<div class="warning">'.$langs->trans('LibeufinConnectorNexusRuntimeProbeFailed', dol_escape_htmltag($runtimeProbe['command_preview'])).'</div>';
}
print '</div>';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('LibeufinConnectorNexusConfigSection').'</td>';
print '<td>'.$langs->trans('LibeufinConnectorNexusConfigOption').'</td>';
print '<td>'.$langs->trans('LibeufinConnectorNexusConfigCurrent').'</td>';
print '<td>'.$langs->trans('LibeufinConnectorNexusConfigExpected').'</td>';
print '<td>'.$langs->trans('Status').'</td>';
print '</tr>';

foreach ($managedSchema as $section => $sectionMeta) {
	foreach ($sectionMeta['fields'] as $key => $label) {
		$currentValue = isset($actualConfig['sections'][$section][$key]) ? (string) $actualConfig['sections'][$section][$key] : '';
		$expectedValue = isset($expectedConfig[$section][$key]) ? (string) $expectedConfig[$section][$key] : '';
		$isMismatch = isset($mismatches[$section][$key]);

		print '<tr class="oddeven">';
		print '<td><strong>['.dol_escape_htmltag($section).']</strong></td>';
		print '<td>'.dol_escape_htmltag($label).'</td>';
		print '<td>'.dol_escape_htmltag($currentValue).'</td>';
		print '<td>'.dol_escape_htmltag($expectedValue).'</td>';
		if ($expectedValue === '') {
			print '<td><span class="opacitymedium">-</span></td>';
		} else {
			print '<td>'.($isMismatch ? img_warning($langs->trans('Error')) : img_picto($langs->trans('OK'), 'tick')).'</td>';
		}
		print '</tr>';
	}
}
print '</table>';

print '<br>';
print '<form method="POST" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save_managed_config">';

foreach ($managedSchema as $section => $sectionMeta) {
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<td colspan="2">['.dol_escape_htmltag($section).'] '.dol_escape_htmltag($sectionMeta['title']).'</td>';
	print '</tr>';

	foreach ($sectionMeta['fields'] as $key => $label) {
		$inputName = 'NEXUSCONFIG_'.strtoupper(str_replace('-', '_', $section)).'_'.$key;
		$value = isset($currentFormValues[$section][$key]) ? $currentFormValues[$section][$key] : '';

		print '<tr class="oddeven">';
		print '<td>'.$label.'</td>';
		print '<td><input type="text" class="flat minwidth500" name="'.dol_escape_htmltag($inputName).'" value="'.dol_escape_htmltag($value).'"></td>';
		print '</tr>';
	}

	print '</table><br>';
}
print '<div class="tabsAction">';
print '<input type="submit" class="button button-save" value="'.$langs->trans('LibeufinConnectorNexusConfigWriteManagedKeys').'">';
print '</div>';
print '</form>';
print '</div>';

print dol_get_fiche_end();
llxFooter();
$db->close();
